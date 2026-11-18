# Cadrage du chatbot et garde-fous, partie 2 : migration, administration, branchement et évaluation

> Créée le 2026-11-18 en coupant [009-cadrage-garde-fous.md](009-cadrage-garde-fous.md). **La spécification, les décisions et les formats (champs, validation, prompt généré, contrôle de sortie) restent dans 009, qui fait foi.** Ce fichier ne liste que ce qui reste à faire.
> **Correspondance GitHub :** à créer.

## Ce que la partie 1 a livré

Trois classes pures, sans WordPress, testées dans `tests/run.php` (40 tests) :

- `WPIA\Service\TokenEstimator` : `estimer()` et `caracteres()`.
- `WPIA\Prompt\CadrageValidator` : `valider( $brut, $actuel, $max_tokens_consignes )` renvoie `{valeurs, erreurs}` ; `consignesTropLongues()`.
- `WPIA\Prompt\PromptBuilder` : `construire( array $chatbot, string $nom_site, bool $avec_documents, bool $avec_marqueur = true )` renvoie `{base, admin}` ; constantes `VERSION`, `JETON_HORS_SUJET`, `SUJET_GENERIQUE`.

Aucune migration, aucun écran, aucun branchement : rien n'est encore utilisé par le plugin.

## Décisions prises pendant la partie 1

| Sujet | Décision |
|---|---|
| Entrée de `PromptBuilder` | Un tableau (ligne `wpia_chatbot`), pas l'entité `Chatbot`, qui n'est utilisée nulle part. |
| Chatbot sans sujet (antérieur à 009) | `SUJET_GENERIQUE` est utilisé dans le prompt, et l'admin affiche l'avertissement « Cadrage incomplet ». |
| Consignes existantes trop longues | Acceptées si renvoyées telles quelles par le formulaire, avec un avertissement ; une nouvelle valeur trop longue est refusée. |
| Champs obligatoires | `sujet` et `message_hors_sujet` sont obligatoires à l'enregistrement. |
| Réponse hors sujet par défaut | Le texte par défaut (traduit) est pré-rempli par le contrôleur, pas par le validateur. |
| Garde `ABSPATH` | Absente des nouveaux fichiers, comme dans le reste de `src/` ; les tests ne définissent pas `ABSPATH`. À traiter dans un lot séparé. |

**Dépend de :** partie 1 (faite) · lot B : [008](008-generation-multitours.md) et [006](006-markdown.md) lot B · **Utilisée par :** [007](007-chat-publique.md), [011](011-cache-reponses.md)

## Tâches

### Lot A : migration, administration, documentation (aucune dépendance)

1. **Migration** (`WPIA_DB_VERSION` passe de 1.2.0 à 1.3.0, dans `plugin-wordpress-ia.php`) :
   - colonnes de `wpia_chatbot` dans `DatabaseInstaller`, en `varchar` (une colonne `text` ne peut pas avoir de valeur par défaut en MySQL 5.7) : `sujet` (200), `ton` (20, défaut `neutre`), `langue` (20, défaut `visiteur`), `message_accueil` (300), `message_hors_sujet` (300), `questions_suggerees` (1000, JSON), `marqueur_temoin` (32) ; `NOT NULL DEFAULT ''` sauf indication contraire ;
   - méthode dédiée `migrateChatbotMarkers()`, sur le modèle de `migrateUsageTable()` : donne un marqueur distinct (`wpia-` + `bin2hex( random_bytes( 8 ) )`) aux chatbots qui n'en ont pas, sans effet si on la relance ;
   - les 7 colonnes sont ajoutées à `$columns` de `ChatbotRepository` ;
   - l'entité `Chatbot` reçoit les nouveaux champs, **si elle est utilisée** à ce stade ; sinon la tâche est reportée, voir « Décisions ».
2. **Réglage** `prompt_admin_max_tokens` dans `AdminSettings` : 1 000 par défaut, bornes 100 à 10 000.
3. **Administration** (`AdminController::renderAIConfiguration` et `sanitizeAIConfiguration`) :
   - section « Cadrage du chatbot » avec les champs de cadrage ; « Instructions système » est renommé « Consignes complémentaires » ;
   - `sanitizeAIConfiguration` appelle `CadrageValidator` sur le texte brut (`wp_unslash()` seulement), traduit chaque code d'erreur en message (`add_settings_error`, un par champ), puis nettoie les valeurs acceptées ;
   - le contrôle de longueur de `system_prompt` sort du refus global actuel : des consignes refusées n'empêchent plus l'enregistrement du fournisseur, du compte et du modèle ;
   - un marqueur témoin est généré à la création d'un chatbot ;
   - **aperçu en lecture seule** des blocs [1] et [2] (`avec_marqueur = false`, `avec_documents` déduit des documents `ready` et actifs) avec une estimation en tokens ;
   - avertissements : « Cadrage incomplet » (sujet vide) et consignes trop longues ;
   - réponse hors sujet pré-remplie avec le texte par défaut traduit ;
   - `assets/js/admin.js` : compteur de tokens des consignes, avec la limite affichée.
4. **Documentation** : `documentation/cadrage.md`, avec des conseils de rédaction des champs et un exemple complet pour un CV.

### Lot B : branchement et évaluation (après 008 et 006 lot B)

5. **Branchement** : `PromptBuilder` est appelé par `PromptAssembler` (006), lui-même branché dans `AiGenerationService::generateChat()` (008). Le contrôle de sortie (marqueur, puis jeton `[[HORS_SUJET]]`) se fait dans `generateChat()`, avant toute mise en cache (011).
6. **Jeu d'évaluation** : `tests/evaluation/questions.md` (environ 20 questions : dans le sujet, hors sujet, piège) et `tests/evaluation/run.php`, lancé à la main, température forcée à 0, comparaison de la réponse brute au comportement attendu, rapport. Exemples dans [009](009-cadrage-garde-fous.md).

## Hors périmètre

Celui de 009, plus : l'ordre de suppression d'un document dans `deleteDocument` (fichier supprimé avant les lignes en base, sans transaction) et les gardes `ABSPATH` de `src/`, qui font l'objet de lots séparés.

## Critères d'acceptation

Ceux de 009 qui ne sont pas cochés, à savoir :

- Des consignes refusées n'empêchent pas l'enregistrement d'un changement de modèle soumis dans le même formulaire ; les consignes gardent leur valeur précédente.
- Des consignes existantes trop longues sont toujours envoyées, et un avertissement s'affiche.
- Un chatbot sans sujet affiche l'avertissement « Cadrage incomplet » et utilise `SUJET_GENERIQUE`.
- La migration attribue un marqueur témoin distinct à chaque chatbot existant, et peut être relancée sans changer les marqueurs déjà posés.
- L'aperçu dans l'admin n'affiche jamais le marqueur témoin.
- Une réponse simulée contenant le marqueur est remplacée par la réponse hors sujet et journalisée sans contenu.
- Une réponse simulée `[[HORS_SUJET]]` est remplacée exactement par la réponse hors sujet de l'admin.
- Une réponse remplacée par le contrôle de sortie n'est jamais mise en cache (011).
- Le jeu d'évaluation, lancé avec un CV d'exemple, obtient au moins 18 réponses conformes sur 20 ; le rapport est joint à la PR.
- L'API REST publique (007) renvoie le message d'accueil et les questions suggérées, échappés, mais jamais le prompt, le sujet brut, les consignes ni le marqueur.
- Vérification manuelle dans un WordPress local : enregistrement valide, champ refusé, aperçu, avertissements.

## Décisions

- Les accesseurs de l'entité `Chatbot` (tâche 1) restent à trancher à l'implémentation : tant que l'entité n'est utilisée nulle part, les ajouter n'apporte rien. La décision et sa justification sont notées dans la PR.
