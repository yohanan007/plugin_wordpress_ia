# RGPD, désinstallation et clé de chiffrement

> Nouvelle issue créée lors de la revue du 2026-11-11. Elle regroupe des exigences de `claude.md` qu'aucune issue ne couvrait.
> **Correspondance GitHub :** à créer.

## Pourquoi cette issue

`claude.md` exige les points suivants, sans qu'aucune issue ne les porte :

- un exportateur et un effaceur de données personnelles (`wp_privacy_personal_data_exporters` / `_erasers`) ;
- un texte pour la politique de confidentialité (`wp_add_privacy_policy_content`) ;
- une constante de clé de chiffrement surchargeable dans `wp-config.php`. Aujourd'hui, `SecretCipher::getKey()` dérive **toujours** la clé de `wp_salt('auth') . wp_salt('secure_auth')` ;
- la documentation de ce qui se passe quand les sels WordPress changent.

Par ailleurs, `uninstall.php`, prévu dans l'arborescence de #1, n'existe pas : désinstaller le plugin laisse 10 tables, des options et des fichiers chiffrés.

**Dépend de :** [005](005-appliquer_limite.md) ; [0004 / #10](0004-prompt-initiale.md) si le stockage des conversations est livré.

## Objectif

Le plugin est conforme aux attentes RGPD de WordPress, et l'admin maîtrise la clé qui protège ses secrets ainsi que le sort de ses données à la désinstallation.

## Décisions

| Sujet | Décision |
|---|---|
| Constante de clé | `WPIA_ENCRYPTION_KEY` dans `wp-config.php`. Si elle est définie et fait au moins 32 caractères, `SecretCipher` l'utilise comme matériau HKDF à la place des sels. `claude.md` cite `MON_PLUGIN_ENCRYPTION_KEY` : le nom est à aligner (mise à jour à faire par Yohanan). |
| Changer de clé | Pas de rotation automatique dans cette issue. Une procédure documentée suffit : ressaisir la clé API, réimporter les documents, purger les conversations. Un écran « Sécurité » signale les secrets qui ne se déchiffrent plus. |
| Identité RGPD | Les conversations anonymes ne sont reliées à aucune adresse e-mail : elles ne peuvent être ni exportées ni effacées « par e-mail ». La politique de confidentialité le dit, et la durée de conservation (0004) fait office de garantie. Si le stockage est activé et que le visiteur est connecté, la conversation reçoit le `user_id` (nouvelle colonne nullable), ce qui la rend exportable et effaçable. |
| Limites (005) | Les lignes `u:<id>` sont effacées par l'effaceur. Les lignes `ip:<hmac>` ne sont pas réidentifiables : elles sont purgées après 7 jours par le cron. |
| Désinstallation | Option « Supprimer toutes les données à la désinstallation », **désactivée par défaut** pour éviter les pertes accidentelles. Activée : `uninstall.php` supprime les tables `wpia_*`, les options `wpia_*`, les transients `wpia_*`, les tâches cron du plugin et le dossier `uploads/wpia/`. Désactivée : seules les tâches cron sont retirées. |

## Tâches

1. `SecretCipher::getKey()` : prise en compte de `WPIA_ENCRYPTION_KEY`, avec un test des deux chemins.
2. Écran « Sécurité » : afficher l'origine de la clé (constante ou sels), sans jamais l'afficher elle-même, et le nombre de secrets indéchiffrables (clé API, documents, messages).
3. `PrivacyIntegration` (`src/Service/`) : exportateur, effaceur et texte de politique de confidentialité (données collectées, finalité, durée, fournisseur IA destinataire des messages).
4. `uninstall.php` et l'option correspondante.
5. Purge des lignes de limites de plus de 7 jours, dans le cron de rétention existant.
6. `documentation/securite.md` : origine de la clé, effet d'un changement de sels, procédure de reprise, sauvegarde (une sauvegarde de base est inutile sans la même clé).

## Hors périmètre

Rotation de clé avec rechiffrement automatique, AAD, outil de rechiffrement en ligne de commande (WP-CLI).

## Critères d'acceptation

- Avec `WPIA_ENCRYPTION_KEY` définie, une clé API chiffrée se déchiffre ; en changeant la constante, l'écran « Sécurité » signale 1 secret indéchiffrable, et la génération renvoie `wpia_secret_error` sans planter.
- `Outils > Exporter les données personnelles` pour un utilisateur connecté ayant discuté (stockage activé) produit ses messages **déchiffrés** ; l'effacement les supprime, ainsi que ses lignes de limites.
- Le texte proposé apparaît dans `Réglages > Confidentialité > Guide de la politique`.
- Désinstallation, option désactivée : les tables et les documents sont toujours là, aucun cron `wpia_*` ne subsiste.
- Désinstallation, option activée : `SHOW TABLES LIKE '%wpia_%'` ne renvoie rien, aucune option `wpia_%`, dossier `uploads/wpia/` absent.
