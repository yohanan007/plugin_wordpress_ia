# Architecture du plugin

## Vue d’ensemble
- Plugin PHP 8+ pour WordPress 6+, organisé en namespaces `WPIA\` et chargé via l’autoload PSR-4 de Composer.
- Le fichier d’entrée initialise les hooks WordPress, les migrations et les services transverses.
- L’architecture sépare administration, domaine, accès aux données, services et infrastructure.

## Composants principaux
- **Admin** (`AdminController`, `AdminSettings`) : pages IA Chatbot, réglages, documents, fournisseurs/comptes/chatbots, sécurité, journaux et limites.
- **Domain** : objets métier fournisseur, compte API, chatbot, document, conversation, message et limite d’utilisation.
- **Repository** : accès à `$wpdb` pour les entités ; `DatabaseInstaller` crée les tables et `MigrationManager` applique les évolutions de schéma.
- **Services** : catalogue de fournisseurs, génération IA (`Service/Ia`), limites de consommation (`Service/Usage`), indexation des documents (`Service/Document`), cadrage et prompt système (`Service/Prompt`), stockage des documents, chiffrement et rétention des journaux.
- **Infrastructure** : activation/désactivation du plugin et gestion des migrations.

## Entrées WordPress et dépendances
- Activation : installe les tables ; désactivation : retire la tâche planifiée de purge sans supprimer les données.
- `plugins_loaded` lance les migrations puis enregistre l’administration et la rétention des journaux.
- L’admin s’appuie sur `admin_init`, `admin_menu`, `admin_enqueue_scripts` et `admin_post_*` pour réglages, test API et gestion des documents/journaux.
- La configuration utilise l’API Settings de WordPress ; la purge s’appuie sur WP-Cron ; le test fournisseur utilise `wp_remote_get`.
- Dépendances d’exécution : WordPress (`$wpdb`, options, API HTTP, cron, uploads), Composer et OpenSSL pour le chiffrement.
- Aucun shortcode, endpoint REST/AJAX ou interface de chat front-office n’est actuellement enregistré.

## Données et flux
- Les tables métier relient fournisseur → compte API → chatbot ; les documents Markdown sont associés aux chatbots avec un ordre défini.
- L’admin enregistre les secrets chiffrés en base et les documents chiffrés dans `uploads/wpia-private` ; ils sont déchiffrés uniquement lors de leur usage.
- Le test de connexion récupère le compte et le fournisseur, déchiffre la clé, puis appelle l’API de modèles ; il ne génère pas de réponse IA.
- La génération passe par `AiGenerationService` : `generate()` (un échange) et `generateChat()` (conversation avec historique, tronqué et réglable). Elle valide les messages, réserve la consommation auprès de `UsageLimiter` sur tout ce qui est envoyé, appelle le fournisseur puis normalise la réponse et ses compteurs de tokens, cache compris. Voir [generation-ia.md](generation-ia.md) et [limites.md](limites.md).
- Les limites de consommation (site, visiteur, session) sont appliquées avant chaque génération ; les compteurs journaliers sont dans la table d’usage (schéma 1.2.0). Le schéma prévoit aussi conversations et messages, mais leur persistance n’est pas utilisée (issue 0004, facultative).
- À l’import et à la réindexation, les documents sont découpés en sections chiffrées avec mots-clés hachés (table `wpia_document_section`, schéma 1.3.0). Elles sont transmises à l’IA par `PromptAssembler` et `ContextBuilder` (rôles « Contexte » et « Complet », budget de tokens) : voir [contexte.md](contexte.md). Aucune vectorisation n’est prévue.
- Le cadrage du chatbot (`PromptBuilder`, `CadrageValidator`) existe sous forme de classes pures, non encore branchées (issue 009).
- Les réglages d’affichage sont persistés, mais ne pilotent pas encore de flux de chat front-office.
