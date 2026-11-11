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
