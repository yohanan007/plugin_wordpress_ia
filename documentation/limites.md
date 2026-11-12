# Limites de consommation

Chaque génération IA (`AiGenerationService::generate()`) est soumise à des
limites **avant** l'appel au fournisseur. Si une limite est atteinte, la
demande est refusée et le fournisseur n'est pas contacté.

## Portées

| Portée | Réglage | Compteur | Clé |
|---|---|---|---|
| **site** | `daily_token_limit` : budget journalier du site | tokens de tous les chatbots et visiteurs | `chatbot_id = 0`, `identifiant = ''`, jour |
| **visiteur** | `max_messages_user` | messages par visiteur et par jour | chatbot, identifiant du visiteur, jour |
| **session** | `max_tokens_conversation` | tokens cumulés d'une conversation | chatbot, jeton haché, `1970-01-01` |

`max_tokens_request` reste le plafond de la réponse (`max_tokens` envoyé au
fournisseur), sans compteur.

La portée **site** est la vraie protection de la facture : une limite par
visiteur se contourne (autre navigateur, autre réseau). La portée **session**
n'est appliquée que si l'appelant transmet le jeton de conversation
(`$contexte['session']`, cookie `wpia_session` à venir avec le chat public).

Le jour est la date locale du site (`wp_date( 'Y-m-d' )`). Un nouveau jour crée
de nouvelles lignes : aucune tâche cron n'est nécessaire pour la remise à zéro.

## Identification des visiteurs

- Utilisateur connecté : `u:<ID>`.
- Visiteur anonyme : `ip:` + `HMAC-SHA256( IP, clé )`. La clé est dérivée des
  sels WordPress par HKDF (contexte `wpia-ip-v1`). **L'IP en clair n'est jamais
  stockée.** Les adresses IPv6 sont comptées par préfixe /64, car les adresses
  temporaires changent au sein d'un même /64.
- Seul `REMOTE_ADDR` est lu. `X-Forwarded-For` est ignoré car falsifiable.
  Derrière un proxy de confiance, utilisez le filtre `wpia_client_ip` :

  ```php
  add_filter( 'wpia_client_ip', function ( string $ip ): string {
  	// Exemple : reverse proxy de confiance qui pose X-Real-IP.
  	return $_SERVER['HTTP_X_REAL_IP'] ?? $ip;
  } );
  ```
- Jeton de session : stocké sous la forme `s:` + SHA-256.

**Limite connue :** plusieurs personnes derrière une même IP (NAT d'entreprise,
réseau mobile) partagent le même quota visiteur. Le budget site reste la
protection principale.

## Déroulé d'une génération

1. **Estimation** E = `ceil( octets / 4 )` du prompt système et de la question,
   plus `max_tokens` (plafond de sortie). C'est la même règle que la taille des
   documents. Elle est volontairement prudente.
2. **Réservation**, portée par portée (site, visiteur, session) :
   `INSERT IGNORE` de la ligne à 0, puis un `UPDATE` conditionnel qui ajoute E
   tokens et 1 message seulement si le plafond reste respecté. Cet `UPDATE` est
   l'opération atomique : deux requêtes simultanées ne peuvent pas passer toutes
   les deux sous le plafond. Si une portée refuse, les portées déjà réservées
   sont annulées.
3. **Appel** au fournisseur.
4. **Confirmation** : le compteur reçoit l'écart (réel − E). Le réel est
   `usage.total_tokens`. Sans métrique fournisseur, l'estimation est conservée.
   Si l'entrée a été sous-estimée, le réel peut faire dépasser légèrement le
   plafond ; les requêtes suivantes sont alors refusées.
5. **Échec** :
   - erreur réseau, timeout ou statut HTTP hors 2xx : la réservation est
     entièrement libérée (tokens et message) ;
   - réponse 2xx inexploitable (JSON invalide, texte vide, limite de sortie) :
     l'appel a été facturé, l'estimation est conservée.

## Refus

```php
$result = $service->generate( $question, array( 'session' => $jeton ) );
if ( is_wp_error( $result ) && 'wpia_limit_reached' === $result->get_error_code() ) {
	$data = $result->get_error_data(); // array( 'status' => 429, 'portee' => 'site'|'visiteur'|'session' )
}
```

Le message est générique et traduisible ; il peut être affiché au visiteur.
Si les compteurs sont inaccessibles (erreur de base de données), la génération
est refusée avec `wpia_usage_unavailable` (503) : **échec fermé**, le fournisseur
n'est pas appelé. Les refus sont journalisés sans contenu
(`AI generation failed (limits, <code>)`).

## Alerte

Lorsque la consommation du jour atteint `token_alert_threshold` % du budget
site, un e-mail est envoyé à `admin_email`. L'envoi est garanti **une seule
fois par jour**, même en cas de requêtes simultanées : seul l'appel qui fait
passer `alerte_envoyee` de 0 à 1 sur la ligne site du jour envoie l'e-mail. La
date de la dernière alerte est conservée dans l'option `wpia_alert_last`.

Le tableau de bord et la page « Alerte token » affichent la consommation du
jour, lue sur la ligne site du jour.

## Conservation et purge

Les lignes **visiteur** et **session** contiennent des identifiants
pseudonymes. Une tâche cron quotidienne (`wpia_purge_usage`) supprime celles
dont la dernière mise à jour (`date_maj`) est plus ancienne que
`usage_retention_days` (7 jours par défaut, de 1 à 365).

- Les lignes **site** (une par jour, sans donnée personnelle) sont conservées
  pour l'historique.
- Une conversation inactive au-delà de la durée de conservation perd son
  compteur : si elle reprend, son budget repart de zéro. Le budget site et le
  quota visiteur restent appliqués.
- La tâche est supprimée à la désactivation du plugin.

## Schéma

Table `{prefix}wpia_limite_utilisation` (migration `1.2.0`) :

| Colonne | Rôle |
|---|---|
| `chatbot_id` | 0 pour la portée site |
| `portee` | `site`, `visiteur` ou `session` |
| `identifiant` | `''`, `u:<id>`, `ip:<hmac>` ou `s:<sha256>` |
| `jour` | date du compteur (`1970-01-01` pour les sessions) |
| `nb_messages`, `nb_tokens` | compteurs |
| `alerte_envoyee` | alerte du jour envoyée (ligne site) |
| `date_maj` | dernière mise à jour, utilisée par la purge |

Clé unique `(chatbot_id, portee, identifiant, jour)` et index
`(portee, date_maj)` pour la purge. L'ancienne table (clé chatbot/session, sans
jour) n'était alimentée par aucun code : la migration la supprime si elle est
vide, sinon elle la renomme en `{prefix}wpia_limite_utilisation_old_110`.
