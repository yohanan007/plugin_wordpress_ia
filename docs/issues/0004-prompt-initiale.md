# issue 

Contexte : les tables existent, mais les échanges ne sont pas encore créés par une fonctionnalité active.
Objectif : définir un stockage sécurisé et un cycle de vie clair pour conversations et messages.

Enregistrer les messages, dates, rôles et métadonnées minimales nécessaires à l’administration.
Chiffrer le contenu sensible au repos et limiter les données personnelles collectées.
Lier les échanges à un identifiant de session non prédictible sans exposer l’adresse IP en clair.
Documenter sauvegarde, rotation des salts, migration des données chiffrées et durée de conservation.
Prévoir la suppression cohérente des messages et métadonnées associées.
Dépendances : précède l’interface de chat et l’administration des conversations.
Acceptation : tests de persistance, isolation entre sessions, chiffrement et erreurs de déchiffrement.

## Analyse

**État actuel :** tables `wpia_conversation` / `wpia_message`, entités et dépôts présents (CRUD générique uniquement). Aucune écriture active, aucune cascade, schéma en `1.1.0`.

### Points bloquants dans le code existant

- **Repli en clair** : `SecretCipher::decrypt()` renvoie la valeur telle quelle sans préfixe `wpia:v1:` → ajouter un mode strict pour les messages.
- **Contexte de clé figé** : `'wpia-api-secret-v1'` est partagé par clés API et documents → contexte paramétrable (`wpia-message-v1`), défaut inchangé.
- **Pas d'AAD** : un chiffré peut être déplacé d'une ligne à l'autre → lier à `conversation_id` + `role`.
- **Pas d'identifiant de clé** : rotation des salts impossible sans tout rechiffrer → versionner la clé et accepter une ancienne clé temporaire.
- **IP** : un SHA-256 d'IPv4 se casse par force brute → HMAC à clé secrète ; `ip_hash` à rendre nullable (minimisation).
- **Session** : stocker le hash du jeton, jamais le jeton.
- **Rétention** : index manquant sur `date_derniere_activite` ; `MigrationManager` ne fait que `dbDelta` (pas de migration de données).
- **`nb_messages`** dénormalisé → mise à jour transactionnelle.
- **Tests** : le faux `wpdb` de `tests/run.php` ne gère pas ces tables.
- Pas de `uninstall.php` (hors périmètre, à prévoir).

### Plan

1. `SecretCipher` : contexte, AAD, mode strict, rétrocompatible.
2. Migration `1.2.0` : index de rétention, `ip_hash` nullable, hash du jeton de session.
3. `ConversationStore` : création/reprise de session, ajout chiffré, lecture isolée (chatbot + session), suppression en cascade transactionnelle.
4. `ConversationRetentionManager` : cron et réglage distincts de `LogRetentionManager`.
5. Documentation : sauvegarde, rotation avec ancienne clé, rechiffrement, conservation.
6. Tests : persistance, isolation, contenu chiffré en base, données altérées, clé changée, chiffré déplacé, suppression complète.

**Hors périmètre :** interface publique et écran d'administration des conversations (cf. `0002-interface-admin.md`).

### Décisions à prendre

- Une session = une conversation, ou plusieurs ?
- Persister les messages `system` ? (recommandé : non)
- Durée de conservation par défaut (ex. 30 jours) et options.
- `ip_hash` : conservé en HMAC pour les limites, ou réservé à `limite_utilisation` ?


---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Correspondance :** GitHub #10, branche `10-persister-et-protéger-les-conversations`.

- **Nom de fichier trompeur** : `0004-prompt-initiale` traite de la persistance des conversations, pas d'un prompt.
- **Ce n'est pas encore une issue, mais une analyse** : l'état des lieux est de bonne qualité (repli en clair de `SecretCipher`, IP en SHA-256 cassable, `nb_messages` dénormalisé, faux `wpdb` des tests). En revanche, les **décisions restent ouvertes**. Un développement lancé dans cet état prendra ces décisions au hasard.
- **Question manquante, la plus importante** : *faut-il stocker les conversations ?* Pour un chat qui présente un CV ou un parcours, l'intérêt principal pour l'admin est de savoir quelles questions sont posées. Le chat peut fonctionner sans stockage si l'historique est gardé côté navigateur pendant la session. Le stockage doit donc être **optionnel et désactivé par défaut**. Le RGPD est alors bien plus simple et le MVP n'en dépend pas.
- **Sécurité disproportionnée pour un MVP** : données liées au contexte (AAD), identifiant de clé et rotation des sels avec ancienne clé temporaire. De plus, l'AAD n'existe pas avec `sodium_crypto_secretbox` (déjà utilisé) : il faudrait changer d'algorithme (`sodium_crypto_aead_xchacha20poly1305_ietf_*`). La rotation de clé relève d'une issue transverse (voir [010](010-rgpd-desinstallation.md)).
- **Chevauchement avec 005** : l'identification du visiteur (session, `ip_hash`) est traitée ici **et** dans les limites. Décision : l'identification pour les limites appartient à 005, et ici on ne stocke que le hash du jeton de session.
- **Place dans la feuille de route** : présentée comme « précède l'interface de chat ». Ce n'est pas nécessaire. Le chat public (007) peut être livré d'abord, sans stockage.
- **Critères d'acceptation** : « tests de persistance, isolation, chiffrement » donne les thèmes, mais pas les cas concrets.

## Issue proposée

**Titre :** #10 – Enregistrer les conversations (optionnel) de façon chiffrée
**Branche :** `10-persister-et-protéger-les-conversations`
**Dépend de :** [008 – Génération multi-tours](008-generation-multitours.md) pour le format des messages. Peut être livrée **après** 007.

### Contexte

Les tables `wpia_conversation` et `wpia_message` existent (schéma `1.1.0`), avec leurs entités et un CRUD générique. Rien ne les alimente. Le tableau de bord lit déjà `SUM(tokens)` dans `wpia_message`. `SecretCipher` renvoie la valeur telle quelle quand elle n'a pas le préfixe `wpia:v1:` (repli en clair) et utilise un contexte HKDF unique, `wpia-api-secret-v1`.

### Objectif

Permettre à l'admin, s'il le souhaite, de relire les échanges de son chatbot, sans qu'aucun contenu lisible ne soit stocké en base.

### Décisions

| Sujet | Décision |
|---|---|
| Activation | Nouveau réglage `conversation_storage` : **désactivé par défaut**. Désactivé, aucune ligne n'est écrite dans `wpia_conversation` ni dans `wpia_message`. |
| Session | Une session = une conversation. Le jeton de session fait 32 octets aléatoires, dans un cookie `wpia_session` (`HttpOnly`, `Secure` si HTTPS, `SameSite=Lax`, durée = session du navigateur). En base, on ne stocke que `hash('sha256', jeton)` : le jeton étant aléatoire, un simple SHA-256 suffit. |
| IP | **Plus d'IP dans `wpia_conversation`** : colonne `ip_hash` rendue nullable et laissée vide. L'IP n'est utilisée que par les limites (005), sous forme de HMAC. |
| Messages `system` | Jamais stockés (ils sont reconstruits côté serveur). Seuls les rôles `user` et `assistant` sont enregistrés. |
| Chiffrement | `SecretCipher` reçoit un **contexte** facultatif (par défaut `wpia-api-secret-v1`, inchangé) et un **mode strict**. Les messages utilisent le contexte `wpia-message-v1` et le mode strict : une valeur sans préfixe lève une exception au lieu d'être renvoyée en clair. Pas d'AAD dans cette issue. |
| Conservation | Réglage `conversation_retention_days`, **30 jours** par défaut (de 1 à 365). La purge est quotidienne par WP-Cron, avec un hook distinct de `LogRetentionManager` mais le même modèle de code. |
| Compteur `nb_messages` | Mis à jour par `UPDATE … SET nb_messages = nb_messages + 1` dans la même transaction que l'insertion du message. |
| Consommation | Le tableau de bord ne lit plus `wpia_message` pour les tokens : il lit la table des limites (005). |

### Tâches

1. **Migration `1.2.0`** : `ip_hash` nullable ; `session_id` renommé fonctionnellement en hash du jeton (même colonne, `char(64)`) ; index `date_derniere_activite`. Le renommage physique de colonne n'est pas nécessaire.
2. **`SecretCipher`** : paramètres `$context` et `$strict`, rétrocompatibles. Les appels existants ne changent pas.
3. **`ConversationStore`** (nouveau, dans `src/Service/`) :
   - `ouvrir( int $chatbot_id, string $jeton ): int` crée ou reprend la conversation.
   - `ajouter( int $conversation_id, string $role, string $contenu, int $tokens ): void` chiffre le contenu et met à jour les compteurs dans une transaction.
   - `lire( int $chatbot_id, string $jeton, int $limite ): array` ne renvoie que les messages de **ce** chatbot et de **cette** session.
   - `supprimer( int $conversation_id ): void` supprime les messages puis la conversation, en transaction.
4. **`ConversationRetentionManager`** : purge des conversations dont `date_derniere_activite` dépasse la durée de conservation.
5. **Branchement** : quand le stockage est activé, l'endpoint du chat (007) enregistre la question et la réponse, et lit l'historique depuis la base plutôt que depuis le navigateur.
6. **Écran d'admin « Conversations »** (lecture seule) : liste paginée (date, nombre de messages), détail déchiffré à l'affichage, bouton de suppression avec nonce.
7. **Documentation** (`documentation/conversations.md`) : activation, durée de conservation, conséquence d'un changement de sels (messages illisibles, à purger), sauvegarde (une base restaurée sans les mêmes sels est illisible).

### Hors périmètre

- AAD et rotation de clé avec ancienne clé (voir 010).
- Export RGPD (voir 010).
- Statistiques sur les questions posées.

### Critères d'acceptation

- Stockage désactivé : après 3 échanges dans le chat, `wpia_conversation` et `wpia_message` sont vides.
- Stockage activé : après 3 échanges, 1 conversation et 6 messages ; `contenu` commence par `wpia:v1:` et ne contient pas le texte saisi.
- Deux navigateurs (deux cookies) sur le même chatbot : `lire()` avec le jeton A ne renvoie jamais de message de B.
- Un contenu altéré en base (un caractère modifié) fait échouer la lecture de **ce** message avec une erreur journalisée, sans afficher de clair et sans faire planter l'écran.
- Mode strict : une valeur sans préfixe dans `contenu` est rejetée, et non affichée.
- Une conversation dont la dernière activité date de 31 jours est purgée par le cron ; une de 29 jours est conservée.
- La suppression d'une conversation supprime aussi tous ses messages.
- La clé API existante se déchiffre toujours après la modification de `SecretCipher`.

### Tests

Étendre le faux `wpdb` de `tests/run.php` aux tables `wpia_conversation` et `wpia_message`, puis couvrir chaque critère ci-dessus.
