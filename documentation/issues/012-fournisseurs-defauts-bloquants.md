# 012 – Mistral et Anthropic : réponses de Mistral Small 4 et modèle Anthropic par défaut

**Branche :** à créer · **Dépend de :** 0003, 008 · **Priorité :** haute (la configuration par défaut peut échouer)

> Issue issue de la comparaison entre `documentation/api/*.md` et les classes `src/Service/Ia/Fournisseur/` (2026-11-27).

## Contexte

**Mistral.** Le catalogue propose `mistral-small-latest` ([AIProviderCatalog.php:19](../../src/Service/Ia/AIProviderCatalog.php)). D'après [mistral-api-guide.md](../api/mistral-api-guide.md) (section 3), cet alias pointe maintenant vers **Mistral Small 4**, un modèle hybride qui sait raisonner. Avec ce modèle, `choices[0].message.content` est **toujours un tableau de morceaux** : des morceaux `{"type": "thinking", ...}` (sans clé `text` au premier niveau), puis des morceaux `{"type": "text", "text": "..."}`.

`ResponseNormalizer::extractContent()` ([ResponseNormalizer.php:136-144](../../src/Service/Ia/ResponseNormalizer.php)) renvoie `null` dès qu'un morceau n'a pas de clé `text` sous forme de chaîne. Résultat : `wpia_invalid_response` à chaque réponse. Et si un morceau de réflexion avait une clé `text`, le raisonnement serait affiché au visiteur.

`MistralProvider` n'envoie pas non plus `reasoning_effort`. Le modèle choisit donc son effort de raisonnement par défaut, ce qui coûte des tokens et du temps de réponse.

**Anthropic.** Le catalogue propose `claude-3-5-haiku-latest` ([AIProviderCatalog.php:24](../../src/Service/Ia/AIProviderCatalog.php)), alors que [anthropic-api-guide.md](../api/anthropic-api-guide.md) cite `claude-opus-5-5`, `claude-sonnet-5-5` et `claude-haiku-5-5`. Ce modèle est très probablement retiré : une nouvelle configuration Anthropic échouerait alors avec une erreur 404.

La valeur du catalogue sert seulement à préremplir le champ « Modèle » de l'admin ([AdminController.php:500, 508, 911](../../src/Admin/AdminController.php)). Les chatbots déjà enregistrés gardent le modèle qui est en base.

## Objectif

Un admin qui garde les valeurs proposées par défaut pour Mistral ou Anthropic obtient une réponse dès le premier essai.

## Décisions

| Sujet | Décision |
|---|---|
| Morceaux de `content` (format chat completions, donc OpenAI, Mistral et personnalisé) | Ne garder que les morceaux `type === 'text'` qui ont un `text` de type chaîne. Les autres types (`thinking`, `reference`…) sont **ignorés** au lieu de rendre la réponse invalide. S'il ne reste aucun morceau texte → `wpia_empty_response`. Un morceau qui n'est pas un tableau → `null` (réponse invalide), comme aujourd'hui. |
| Morceau sans `type` mais avec `text` | Accepté comme texte : c'est le comportement actuel, on le garde pour rester compatible. |
| Morceau texte dont `text` n'est pas une chaîne | `null` (réponse invalide) : un morceau qui annonce du texte sans en contenir est malformé. |
| Aucun morceau texte, ou `content = []` | `extractContent()` renvoie `''` (et non plus `null`) : `normalize()` produit alors `wpia_empty_response`. La branche Anthropic n'est pas modifiée (voir 015). |
| `reasoning_effort` (Mistral) | Toujours envoyé, avec la valeur `'none'` : pour un chatbot de CV, on veut une réponse rapide et sans bloc de réflexion. La valeur n'est pas réglable dans l'admin. |
| Modèles Mistral qui ne connaissent pas `reasoning_effort` | On ne fait rien de particulier : la doc ne signale pas d'erreur. Si un modèle le refuse (400), l'erreur est visible grâce au code journalisé (voir [015](015-normaliseur-arret-et-erreurs.md)). |
| Modèle Anthropic par défaut | `claude-haiku-5-5`. |
| Chatbots existants | Pas de migration : le modèle enregistré n'est pas modifié automatiquement. Le dépôt n'a pas de changelog : seule `documentation/generation-ia.md` indique de vérifier le champ « Modèle ». Le message d'erreur reste générique tant que 015 n'est pas livrée. |

## Tâches

1. `ResponseNormalizer::extractContent()` : filtrer les morceaux comme indiqué dans les décisions.
2. `MistralProvider::generate()` : ajouter `'reasoning_effort' => 'none'` au corps de la requête.
3. `AIProviderCatalog` : remplacer le modèle Anthropic par `claude-haiku-5-5`.
4. Documentation : une ligne dans `documentation/generation-ia.md` (sections fournisseurs ou modèles) sur `reasoning_effort` et sur le nouveau modèle par défaut.
5. `tests/run.php` : le test « generate() sends exactly the historical request body » attendait le même corps pour Mistral et OpenAI ; le corps Mistral attendu ajoute `reasoning_effort` en dernière clé.
6. Vérification manuelle avant fusion : une requête avec un modèle Mistral non hybride (`mistral-large-latest`) accepte `reasoning_effort` (pas de 400/422).

## Hors périmètre

- Afficher le raisonnement du modèle.
- Mettre à jour automatiquement les modèles déjà enregistrés.
- Vérifier la disponibilité d'un modèle depuis l'admin (`GET /models`).

## Critères d'acceptation

- Réponse Mistral avec `content = [{"type":"thinking","thinking":[{"type":"text","text":"je réfléchis"}]}, {"type":"text","text":"Bonjour"}]` → `content` normalisé vaut `"Bonjour"` exactement.
- Réponse avec seulement un morceau `thinking` → `wpia_empty_response`.
- La requête Mistral envoyée contient `"reasoning_effort":"none"`. Les requêtes OpenAI et personnalisée ne le contiennent pas.
- Dans l'admin, choisir « Anthropic » préremplit le modèle `claude-haiku-5-5`.
- Les tests existants (`content` sous forme de chaîne, morceaux `text` sans `type`) passent toujours, sauf le test du corps de requête Mistral, mis à jour (tâche 5).

## Tests

Dans `tests/run.php` :
- normaliseur : morceaux `thinking` + `text`, `thinking` seul, morceau non texte portant une clé `text` (ignoré), morceaux `text` sans `type`, morceau qui n'est pas un tableau, morceau texte dont `text` n'est pas une chaîne, `content = []` ;
- requête Mistral : présence de `reasoning_effort` ;
- catalogue : modèle Anthropic par défaut.
