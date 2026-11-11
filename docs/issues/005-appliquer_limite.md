**Contexte :** les seuils sont configurables, mais ne bloquent ni ne réduisent les requêtes réelles.
**Objectif :** appliquer les limites avant chaque génération et comptabiliser l’usage après celle-ci.
- Contrôler les tokens par requête, par conversation, par jour et les messages quotidiens.
- Identifier les utilisateurs et visiteurs anonymes sans dépendre uniquement d’une IP brute.
- Mettre à jour les compteurs de manière atomique afin d’éviter les dépassements concurrents.
- Utiliser les métriques fournisseur quand disponibles et documenter l’estimation de secours.
- Retourner un refus explicite sans envoyer de requête IA lorsque le seuil est atteint.
**Dépendances :** issues 1 et 3 ; s’appuyer sur `AdminSettings` et `LimiteUtilisationRepository`.
**Acceptation :** tests des seuils, réinitialisations journalières, appels concurrents et refus explicites.

---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Correspondance :** GitHub #11, branche `11-appliquer-les-limites-de-consommation` (branche en cours).

- **Bonne intention, mais trop courte pour être développée** : 5 puces, aucune décision sur l'identification, le schéma ou le moment du contrôle.
- **Pas de budget global pour le site, le manque le plus grave.** Une limite par visiteur se contourne en changeant de session, de navigateur ou de réseau. Ce qui protège réellement la facture de l'admin, c'est un **plafond pour l'ensemble du site**, avec coupure automatique. Le réglage `daily_token_limit` existe, mais rien ne dit s'il est global ou par visiteur. Le tableau de bord le traite déjà comme global.
- **Le schéma actuel ne permet pas la remise à zéro quotidienne.** `wpia_limite_utilisation` a une clé unique `(chatbot_id, session_id)` et une colonne `date_maj`, mais **pas de colonne de jour**. Remettre les compteurs à zéro demanderait un cron ou une comparaison de date à chaque requête, ce qui ouvre des situations de concurrence.
- **« Mettre à jour les compteurs de manière atomique » ne suffit pas.** Il faut aussi **réserver avant l'appel**. Sinon, dix requêtes simultanées passent toutes le contrôle avant que la première n'ait été comptée.
- **Le seuil d'alerte n'est pas traité** : `token_alert_threshold` et le sous-menu « Alerte token » existent depuis 0002, sans comportement défini.
- **Dépendances fausses** : « issues 1 et 3 ». L'issue 3 (0003) est la génération IA, ce qui est juste, mais l'issue 1 est l'architecture, qui est déjà livrée. La dépendance réelle est le **point d'entrée** de la génération (`AiGenerationService::generate`).
- **Chevauchement avec 0004** sur l'identification du visiteur. Décision : elle appartient à cette issue.
- **Critères d'acceptation** : « tests des seuils, réinitialisations, appels concurrents » donne les thèmes, mais pas les valeurs attendues.

## Issue proposée

**Titre :** #11 – Appliquer les limites de consommation avant chaque génération
**Branche :** `11-appliquer-les-limites-de-consommation`
**Dépend de :** #8 (génération IA, livrée). Sera reprise par [008](008-generation-multitours.md) et [007](007-chat-publique.md).

### Contexte

- `AdminSettings` contient `max_tokens_request` (1024), `max_tokens_conversation` (4096), `max_messages_user` (20, libellé « par utilisateur et par jour »), `daily_token_limit` (10 000) et `token_alert_threshold` (80 %).
- `AiGenerationService::generate()` applique déjà `min( chatbot.max_tokens, max_tokens_request )` à la réponse, mais ne contrôle rien d'autre.
- `ResponseNormalizer` extrait `usage.input_tokens`, `output_tokens` et `total_tokens` quand le fournisseur les renvoie.
- `wpia_limite_utilisation` existe, mais n'est alimentée par aucun code (à vérifier en base avant la migration).

### Objectif

Aucune requête n'est envoyée au fournisseur si elle risque de dépasser le budget journalier du site ou le quota du visiteur. L'admin est prévenu avant d'atteindre le plafond.

### Décisions

| Sujet | Décision |
|---|---|
| Portées | **site** (tous chatbots et visiteurs confondus), **visiteur** (par jour) et **session** (par conversation). |
| `daily_token_limit` | Budget **global du site** par jour. C'est la protection de la facture. |
| `max_messages_user` | Nombre de messages par **visiteur** et par jour. |
| `max_tokens_conversation` | Tokens cumulés par **session** (jeton du cookie `wpia_session`, voir 007), tous jours confondus. |
| `max_tokens_request` | Plafond de la réponse (`max_tokens`), déjà appliqué. Rien à changer. |
| Identité du visiteur | Utilisateur connecté : `u:` + ID utilisateur. Anonyme : `ip:` + `hash_hmac( 'sha256', IP, clé )`, avec une clé dérivée des sels par HKDF (contexte `wpia-ip-v1`). L'IP en clair n'est jamais stockée. Seul `REMOTE_ADDR` est utilisé : `X-Forwarded-For` est ignoré par défaut, car il est falsifiable, et un filtre `wpia_client_ip` permet de l'adapter derrière un proxy de confiance. |
| Jour | Date locale du site : `current_time( 'Y-m-d' )`. Changer de jour crée de nouvelles lignes, sans cron de remise à zéro. |
| Estimation | `ceil( caractères / 4 )` pour l'entrée (prompt système, contexte, historique et question), plus `max_tokens` pour la sortie. C'est la même règle que l'admin des documents ; elle est documentée. |
| Consommation réelle | `usage.total_tokens`, sinon `input + output`, sinon l'estimation conservée. |
| Échec de l'appel | La réservation est libérée, et l'échec est journalisé sans contenu. |
| Source du tableau de bord | La ligne de portée **site** du jour, et non plus `SUM(tokens)` dans `wpia_message`. |

### Schéma (migration `1.2.0`)

La table actuelle est vide et sa clé unique `(chatbot_id, session_id)` est incompatible avec la nouvelle structure. Or `dbDelta()` ne supprime pas un index existant. La migration fait donc, une seule fois et de façon idempotente :

1. vérifier que la table est vide (sinon, arrêter la migration et journaliser) ;
2. `ALTER TABLE … DROP INDEX chatbot_session` ;
3. appliquer `dbDelta()` sur la nouvelle définition :

```sql
CREATE TABLE {prefix}wpia_limite_utilisation (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  chatbot_id bigint(20) unsigned NOT NULL DEFAULT 0,   -- 0 pour la portée site
  portee varchar(10) NOT NULL,                         -- site | visiteur | session
  identifiant varchar(70) NOT NULL,                    -- '' | u:<id> | ip:<hmac> | s:<hash jeton>
  jour date NOT NULL,                                  -- '1970-01-01' pour la portée session
  nb_messages int(10) unsigned NOT NULL DEFAULT 0,
  nb_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  date_maj datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY portee_jour (chatbot_id,portee,identifiant,jour),
  KEY jour (jour)
)
```

Les anciennes colonnes (`session_id`, `ip_hash`, `nb_messages_jour`, `nb_tokens_jour`) restent en place et inutilisées, car `dbDelta` ne les supprime pas. Leur suppression éventuelle fera l'objet d'une décision séparée.

### Déroulé d'une génération

```
1. Calculer l'estimation E (entrée + max_tokens).
2. Pour chaque portée (site, visiteur, session) :
     INSERT IGNORE de la ligne du jour à 0
     UPDATE … SET nb_tokens = nb_tokens + E, nb_messages = nb_messages + 1
       WHERE <clé> AND nb_tokens + E <= <plafond> AND nb_messages + 1 <= <plafond messages>
     0 ligne modifiée → annuler les réservations déjà faites → refus.
3. Appeler le fournisseur.
4. Succès : corriger chaque portée de (réel − E), sans descendre sous 0.
   Échec : retirer E et 1 message de chaque portée.
5. Si le total de la portée site franchit le seuil d'alerte : alerte (une fois par jour).
```

L'`UPDATE` conditionnel est l'opération atomique : deux requêtes simultanées ne peuvent pas toutes les deux passer sous le plafond.

### Tâches

1. Migration `1.2.0` décrite ci-dessus, dans `DatabaseInstaller` / `MigrationManager`.
2. **`UsageLimiter`** (nouveau, `src/Service/UsageLimiter.php`) :
   - `reserver( int $chatbot_id, array $identites, int $estimation ): array|\WP_Error` ;
   - `confirmer( array $reservation, ?int $reel ): void` ;
   - `annuler( array $reservation ): void` ;
   - `consommationSite( string $jour ): array`.
   
   La date et l'IP sont injectables pour les tests.
3. **Intégration** dans `AiGenerationService::generate()` : réservation avant l'appel au fournisseur, puis confirmation ou annulation. Le test de connexion de l'admin compte uniquement dans la portée **site**.
4. **Refus** : `WP_Error( 'wpia_limit_reached', … , array( 'status' => 429, 'portee' => 'site'|'visiteur'|'session' ) )` avec un message générique traduisible, par exemple « Le service a atteint sa limite d'utilisation pour aujourd'hui. » Le fournisseur n'est pas appelé.
5. **Alerte** : au franchissement de `token_alert_threshold` % du budget site, un `wp_mail()` à `admin_email` (une fois par jour, via l'option `wpia_alert_sent` = date) et une notice dans l'admin du plugin. La page « Alerte token » affiche la consommation du jour, le seuil et la date de la dernière alerte.
6. **Tableau de bord** : lire `consommationSite()`.
7. **Libellés** : préciser dans `AdminSettings` que `daily_token_limit` est le « budget journalier du site ».
8. **Documentation** : `documentation/limites.md` décrit les portées, la règle d'estimation, le comportement en cas d'échec et la limite connue (NAT d'entreprise : plusieurs personnes partagent une même IP).

### Hors périmètre

- Budget mensuel ou en euros.
- Liste blanche d'IP ou de rôles.
- Limitation de débit par minute (anti-rafale) : prévue dans 007.

### Critères d'acceptation

Avec `daily_token_limit` = 1 000, `max_messages_user` = 3, `max_tokens_conversation` = 600 et `max_tokens` = 100 :

- 3 messages d'un même visiteur passent ; le 4e est refusé avec `portee = visiteur`, code 429, **sans appel au fournisseur** (compteur d'appels du fournisseur simulé inchangé).
- Un 2e visiteur peut encore envoyer des messages, tant que le budget site n'est pas atteint.
- Quand le budget site est atteint, tous les visiteurs sont refusés avec `portee = site`.
- Le jour J+1 (date injectée), le même visiteur peut renvoyer 3 messages, sans cron.
- Deux réservations dont la somme dépasse le budget restant : la première passe, la seconde est refusée (vérifié par l'`UPDATE` conditionnel).
- Le fournisseur renvoie `total_tokens = 40` pour une estimation de 150 : le compteur augmente de 40.
- Le fournisseur ne renvoie pas `usage` : le compteur garde l'estimation.
- L'appel échoue (timeout) : les compteurs reviennent à leur valeur d'avant la requête.
- Au franchissement de 80 % : un seul e-mail dans la journée, même si 10 requêtes suivent.
- `wpia_limite_utilisation` ne contient aucune IP en clair.

### Tests

Ajouter `wpia_limite_utilisation` au faux `wpdb` de `tests/run.php`, y compris le comportement de l'`UPDATE` conditionnel (lignes modifiées), et couvrir chaque critère ci-dessus.
