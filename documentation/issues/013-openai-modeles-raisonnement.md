# 013 – OpenAI : requêtes compatibles avec les modèles de raisonnement

**Branche :** à créer · **Dépend de :** 0003, 008 · **Priorité :** moyenne (bloquant dès qu'un modèle GPT-6 est choisi)

> Issue issue de la comparaison entre `documentation/api/*.md` et les classes `src/Service/Ia/Fournisseur/` (2026-11-27).

## Contexte

[OpenAiProvider.php](../../src/Service/Ia/Fournisseur/OpenAiProvider.php) appelle `POST /chat/completions` avec `temperature` et `max_tokens`. [openai-api-guide.md](../api/openai-api-guide.md) décrit l'API **Responses** (`POST /v1/responses`) et précise deux points :

- les modèles GPT-6 sont des modèles de raisonnement : **`temperature` et `top_p` provoquent une erreur 400** ;
- la limite de sortie compte aussi les tokens de raisonnement.

Avec le modèle par défaut du catalogue (`gpt-4o-mini`), la requête actuelle fonctionne. En revanche, **dès que l'admin saisit un modèle GPT-6, chaque requête échoue** avec `wpia_provider_error`.

Dans Chat Completions, `max_tokens` est aussi déprécié au profit de `max_completion_tokens`, que tous les modèles acceptent. Les modèles de raisonnement refusent `max_tokens`. Ce point vient de la référence d'OpenAI : le guide local ne le mentionne pas.

Le fournisseur Anthropic ignore déjà la température ([AnthropicProvider.php:21-23](../../src/Service/Ia/Fournisseur/AnthropicProvider.php)) : c'est le précédent à suivre.

## Objectif

Un admin peut configurer n'importe quel modèle OpenAI actuel, de raisonnement ou non, sans erreur 400.

## Décisions

| Sujet | Décision |
|---|---|
| Endpoint | On **garde** Chat Completions (`/chat/completions`). La migration vers l'API Responses est hors périmètre : elle apporterait l'historique côté OpenAI (`previous_response_id`, `store`), dont le plugin n'a pas besoin puisqu'il renvoie l'historique complet à chaque appel. |
| `temperature` | **Plus jamais envoyée** à OpenAI, quel que soit le modèle. On ne cherche pas à détecter les modèles de raisonnement : les listes de modèles changent trop vite. |
| Limite de sortie | `max_completion_tokens` à la place de `max_tokens`. |
| `reasoning_effort` | Envoyé avec `'low'` (valeur que la doc conseille pour un chatbot de FAQ), **uniquement** si le nom du modèle commence par `gpt-5`, `gpt-6` ou `o` suivi d'un chiffre. Les autres modèles (`gpt-4o-mini`…) le refusent. |
| Fournisseur personnalisé | Inchangé (`temperature` et `max_tokens`) : les serveurs compatibles OpenAI (vLLM, LM Studio, Ollama `/v1`) attendent souvent `max_tokens`. |
| Admin | Sous le champ « Température », une description indique qu'elle est ignorée pour OpenAI et Anthropic. |
| `max_tokens` trop bas | Avec un modèle de raisonnement, une limite basse peut produire une réponse vide (`finish_reason: "length"`). Ce cas est traité par [015](015-normaliseur-arret-et-erreurs.md). |

## Tâches

1. `OpenAiProvider::generate()` : retirer `temperature`, remplacer `max_tokens` par `max_completion_tokens`, ajouter `reasoning_effort` selon la règle des décisions (méthode privée `estModeleRaisonnement( string $model ): bool`).
2. `AdminController` (champ « Température », ligne ~514) : ajouter la description.
3. `documentation/generation-ia.md` : paramètres envoyés pour chaque fournisseur.

## Hors périmètre

- Migrer vers l'API Responses.
- Rendre l'effort de raisonnement réglable dans l'admin.
- Changer le modèle OpenAI par défaut du catalogue.

## Critères d'acceptation

- Modèle `gpt-4o-mini` : le corps envoyé contient `model`, `messages` et `max_completion_tokens`, mais ni `temperature`, ni `max_tokens`, ni `reasoning_effort`.
- Modèles `gpt-6.2-sol` et `o4-mini` : le corps contient `"reasoning_effort":"low"`.
- Fournisseur personnalisé : le corps contient toujours `temperature` et `max_tokens`.
- La description de l'admin s'affiche sous le champ « Température ».

## Tests

Dans `tests/run.php` : mettre à jour le test « openai request and normalized response » (paramètres envoyés), et ajouter un test pour `reasoning_effort` (`gpt-4o-mini`, `gpt-6.2-sol`, `o4-mini`) et un test de non-régression pour le fournisseur personnalisé.
