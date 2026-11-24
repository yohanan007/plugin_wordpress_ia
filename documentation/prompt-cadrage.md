# Cadrage du chatbot et prompt système (développeur)

Ce document décrit les classes qui construisent le **cadrage** d'un chatbot et
les deux premiers blocs de son prompt système. La spécification (champs, limites,
décisions) est dans `docs/issues/009-cadrage-garde-fous.md` ; le reste du travail
est dans `docs/issues/009-cadrage-garde-fous-2.md`.

> **État :** `PromptBuilder` est appelé par `PromptAssembler` (006, voir
> [contexte.md](contexte.md)) à chaque génération. Migration, administration et
> contrôle de sortie sont livrés (dernière section) ; le jeu d'évaluation reste à faire.

## Place dans le prompt système

Le prompt système est assemblé en quatre blocs, dans cet ordre :

| Bloc | Contenu | Produit par |
|---|---|---|
| [1] | Prompt de base : règles fixes et cadrage | `PromptBuilder` (`base`) |
| [2] | Consignes de l'admin (`wpia_chatbot.system_prompt`) | `PromptBuilder` (`admin`) |
| [3] | `<contexte>` | `ContextBuilder` (006) |
| [4] | `<documents>` | `ContextBuilder` (006) |

`PromptAssembler` (006) assemble les quatre blocs. `PromptBuilder` ne produit que
[1] et [2].

## Les trois classes

Toutes sont **pures** : aucune fonction WordPress, aucun accès à la base, aucun
hasard. Elles se testent sans WordPress (`php tests/run.php`).

### `WPIA\Service\Prompt\TokenEstimator`

Estimation unique du nombre de tokens : `ceil( caractères / 4 )`.

```php
TokenEstimator::estimer( 'Bonjour' );    // 2
TokenEstimator::caracteres( 'é' );        // 1 (strlen() donnerait 2)
```

On compte des **caractères UTF-8**, pas des octets. `mbstring` n'est pas
nécessaire (`preg_match_all( '/./us' )`). Un texte qui n'est pas de l'UTF-8
valide est compté en octets, par prudence.

### `WPIA\Service\Prompt\CadrageValidator`

Valide les champs de cadrage soumis par l'admin.

```php
$resultat = ( new CadrageValidator() )->valider( $brut, $actuel, $max_tokens );
// $resultat['valeurs'] : les 7 colonnes de cadrage, prêtes à être nettoyées
// $resultat['erreurs'] : champ => code d'erreur (vide si tout est valide)
```

| Paramètre | Contenu |
|---|---|
| `$brut` | Champs soumis, après `wp_unslash()`, **avant tout `sanitize_*()`**. Un champ absent garde sa valeur actuelle. |
| `$actuel` | Ligne `wpia_chatbot` actuelle (tableau vide à la création). |
| `$max_tokens` | Réglage `prompt_admin_max_tokens`. |

Les clés de `valeurs` sont toujours : `sujet`, `message_accueil`,
`message_hors_sujet`, `ton`, `langue`, `questions_suggerees` (JSON, `'[]'` par
défaut) et `system_prompt`.

**Règles à connaître**

- **Texte brut obligatoire.** `sanitize_textarea_field()` retire les balises : un
  contrôle fait après ce nettoyage ne verrait jamais `<documents>`. Le nettoyage
  WordPress s'applique **ensuite**, aux seules valeurs renvoyées.
- **Refus ciblé.** Un champ invalide garde sa valeur actuelle et produit un code
  d'erreur ; les autres champs sont acceptés. Le validateur ne produit aucun
  message : c'est à l'appelant de traduire les codes.
- **Fragments interdits** dans tous les champs textuels, sans tenir compte de la
  casse : `<contexte`, `</contexte`, `<documents`, `</documents`, `<section`,
  `</section` et `[[HORS_SUJET]]` (`FRAGMENTS_INTERDITS`).
- **Obligatoires :** `sujet` et `message_hors_sujet`. Un champ facultatif vide
  n'est pas une erreur.
- **Listes fermées :** une valeur inconnue pour `ton` ou `langue` est ramenée à
  la valeur par défaut (`neutre`, `visiteur`), sans erreur.
- **Questions suggérées :** tableau de chaînes ; les entrées vides sont ignorées,
  le reste est encodé en JSON (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`).
- **Consignes (`system_prompt`) :** limitées en tokens. Une valeur **identique à
  la valeur actuelle** (après normalisation) est acceptée même si elle est trop
  longue : sinon chaque enregistrement, même un simple changement de modèle,
  afficherait une erreur. `consignesTropLongues()` sert à l'avertissement.
- **Normalisation :** `\r\n` devient `\n`, puis `trim()`.

**Limites et codes d'erreur**

| Champ | Limite | Obligatoire |
|---|---|---|
| `sujet` | 200 caractères | oui |
| `message_accueil` | 300 caractères | non |
| `message_hors_sujet` | 300 caractères | oui |
| `questions_suggerees` | 4 questions, 120 caractères chacune | non |
| `system_prompt` | `$max_tokens` tokens | non |

| Code | Constante | Signification |
|---|---|---|
| `obligatoire` | `ERREUR_OBLIGATOIRE` | champ obligatoire vide |
| `trop_long` | `ERREUR_TROP_LONG` | limite dépassée |
| `fragment_interdit` | `ERREUR_INTERDIT` | balise ou jeton interdit |
| `trop_de_questions` | `ERREUR_TROP_DE_QUESTIONS` | plus de 4 questions |
| `invalide` | `ERREUR_INVALIDE` | type inattendu (tableau à la place d'une chaîne, etc.) |

### `WPIA\Service\Prompt\PromptBuilder`

```php
$blocs = ( new PromptBuilder() )->construire( $chatbot, get_bloginfo( 'name' ), $avec_documents );
// $blocs['base']  : bloc [1]
// $blocs['admin'] : bloc [2], chaîne vide si aucune consigne
```

| Paramètre | Contenu |
|---|---|
| `$chatbot` | Tableau : ligne `wpia_chatbot` (`sujet`, `ton`, `langue`, `system_prompt`, `marqueur_temoin`). Ce n'est **pas** l'entité `Chatbot`, qui n'est utilisée nulle part. |
| `$nom_site` | Nom du site, fourni par l'appelant. |
| `$avec_documents` | Faux si le chatbot n'a aucun document `ready` et actif. |
| `$avec_marqueur` | Faux pour l'aperçu de l'admin : le marqueur n'est jamais affiché. Vrai par défaut. |

**Comportement**

- Les règles fixes **précèdent toujours** les consignes de l'admin, qui n'en
  retirent aucune.
- **Variante sans document :** les deux premières règles sont remplacées par une
  règle qui interdit d'affirmer un fait précis.
- **Sujet vide :** `SUJET_GENERIQUE` est utilisé (chatbots antérieurs à 009).
  L'admin doit alors voir un avertissement « Cadrage incomplet ».
- **Ton et langue** inconnus : valeurs par défaut.
- **Valeurs insérées dans les règles** (sujet, nom du site, marqueur) : ramenées
  à une seule ligne et chevrons échappés (`&lt;`, `&gt;`). Le validateur refuse
  déjà ces cas à l'enregistrement ; ceci protège aussi les valeurs plus anciennes.
- **Marqueur témoin :** ligne « Référence interne : … », omise s'il est vide ou si
  `$avec_marqueur` est faux. Il est **lu** depuis le chatbot, jamais généré ici.
- **Jeton hors sujet :** les règles demandent au modèle de répondre uniquement
  `[[HORS_SUJET]]` hors sujet ; c'est le serveur qui le remplace par le message de
  l'admin (`OutputGuard`, voir la dernière section).

## Modifier le texte des règles fixes

Le texte des règles est versionné par `PromptBuilder::VERSION`. Cette version
entre dans la **version du contexte** (006), qui sert de **clé de cache** (011) :
sans incrément, d'anciennes réponses du cache survivraient à un changement de
règles.

Pour modifier une règle :

1. Modifier le texte dans `PromptBuilder`.
2. Incrémenter `PromptBuilder::VERSION`.
3. Lancer `php tests/run.php` : le test de l'empreinte échoue (c'est voulu).
4. Mettre à jour la paire `array( version, empreinte )` dans `tests/run.php`,
   à côté de `empreintePrompt()`, avec la nouvelle version et la nouvelle
   empreinte.

L'empreinte couvre 3 tons × 3 langues × avec et sans document. Si vous ajoutez un
ton ou une langue, ajoutez-le aussi dans `empreintePrompt()`.

## Tests

```bash
php tests/run.php
```

Le lanceur est maison (`$run( nom, fn )`, `assertTrue`, `assertSameValue`) et
se termine par une assertion sur le **nombre** de tests : ajoutez un test, mettez
ce nombre à jour. Ces classes n'ont pas besoin de `ABSPATH`.

## Administration, migration et contrôle de sortie

- **Migration 1.5.0** (`DatabaseInstaller`) : sept colonnes dans `wpia_chatbot`
  (`sujet`, `ton`, `langue`, `message_accueil`, `message_hors_sujet`,
  `questions_suggerees`, `marqueur_temoin`) et `migrateChatbotMarkers()`, qui donne
  un marqueur distinct aux chatbots qui n'en ont pas. Idempotente, exécutée à
  chaque installation.
- **Réglage** `prompt_admin_max_tokens` (`AdminSettings`) : 1 000 par défaut, 100
  à 10 000.
- **`WPIA\Admin\CadrageSanitizer`** : appelée par `sanitizeAIConfiguration`. Elle
  valide le texte brut avec `CadrageValidator`, nettoie les seules valeurs
  acceptées, et renvoie un message traduit par champ refusé. Elle pré-remplit la
  réponse hors sujet par défaut et fournit `nouveauMarqueur()`. Un champ refusé
  n'empêche pas l'enregistrement du fournisseur, du compte et du modèle. Le marqueur
  est posé une fois et jamais remplacé.
- **`WPIA\Admin\CadrageApercu`** : construit l'aperçu des blocs [1] et [2] avec
  `$avec_marqueur = false`, affiché sous le formulaire. Les champs, les
  avertissements et le compteur de tokens (`assets/js/admin.js`) sont dans
  `AdminController::renderCadrageFields()` et `renderCadrageWarnings()`.
- **`WPIA\Service\Prompt\OutputGuard`** : appelé par `generateChat()` après la
  confirmation des tokens. Une réponse contenant le marqueur (fuite du prompt) ou
  `[[HORS_SUJET]]` est remplacée par la réponse hors sujet du chatbot. Le résultat
  porte alors `remplacee => true` : **une telle réponse ne doit jamais être mise en
  cache** (011). Journal `securite`, sans contenu.

Le guide pour l'administrateur est dans [cadrage.md](cadrage.md). Reste à faire : le
jeu d'évaluation (`tests/evaluation/`), voir `docs/issues/009-cadrage-garde-fous-2.md`.
