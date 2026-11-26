# 015 – Normaliseur : raison d'arrêt, refus et erreurs des fournisseurs

**Branche :** à créer · **Dépend de :** 0003, 012 (même fichier `ResponseNormalizer`) · **Priorité :** moyenne

> Issue issue de la comparaison entre `documentation/api/*.md` et les classes `src/Service/Ia/Fournisseur/` (2026-11-27).

## Contexte

[ResponseNormalizer.php](../../src/Service/Ia/ResponseNormalizer.php) extrait le texte et l'usage, mais ignore trois informations que les quatre guides de `documentation/api/` décrivent :

1. **La raison d'arrêt** (`finish_reason` pour OpenAI, Mistral et le fournisseur personnalisé, `stop_reason` pour Anthropic, `done_reason` pour Ollama) n'est jamais lue.
   - Une réponse **coupée** par la limite de tokens (`length`, `max_tokens`, `model_length`, `model_context_window_exceeded`) est servie comme si elle était complète.
   - Le contrôle `output_tokens > max_tokens` ([ligne 65](../../src/Service/Ia/ResponseNormalizer.php)) ne se déclenche presque jamais, puisque le fournisseur s'arrête justement à la limite.
2. **Un refus** Anthropic (`stop_reason: "refusal"`) ou un filtrage OpenAI (`finish_reason: "content_filter"`) arrive avec un **HTTP 200**. Au mieux on obtient `wpia_empty_response`, au pire un texte partiel.
3. **Les erreurs HTTP** sont réduites à deux codes : `wpia_authentication_error` (401/403) et `wpia_provider_error` (tout le reste).
   - Une limite de débit (429) et une surcharge (503, ou 529 propre à Anthropic) sont confondues avec une requête invalide (400).
   - Le corps de l'erreur, dont le format diffère selon le fournisseur, et l'identifiant de requête (`x-request-id`, `request-id`) ne sont jamais journalisés. L'admin ne peut donc pas diagnostiquer, par exemple, un modèle inconnu ou une erreur 400 sur un paramètre.

`AiGenerationService::logFailure()` journalise aujourd'hui « AI generation failed (fournisseur, code) », sans statut HTTP.

## Objectif

L'admin sait, en lisant les journaux, pourquoi une génération a échoué ou a été coupée, et le visiteur reçoit un message adapté en cas de refus ou de surcharge.

## Décisions

| Sujet | Décision |
|---|---|
| Lecture de la raison d'arrêt | Nouvelle méthode privée `extractStopReason( string $provider, array $data ): ?string` : `choices[0].finish_reason`, `stop_reason` ou `done_reason` selon le fournisseur. |
| Réponse tronquée | Raisons `length`, `max_tokens`, `model_length` et `model_context_window_exceeded`. La réponse est **conservée** (le texte partiel reste utile et a été facturé) et le résultat porte `'tronquee' => true`. Journal `info`, canal `api` : « Response truncated (fournisseur, raison) ». Un texte tronqué **vide** reste `wpia_empty_response`. |
| Refus | Raisons `refusal` (Anthropic) et `content_filter` (OpenAI, personnalisé) → `WP_Error` `wpia_refusal`, message « Le modèle n'a pas pu répondre à cette question. ». Les tokens sont comptés (réponse 2xx, `wasBilled()` le gère déjà). |
| `finish_reason: "error"` (Mistral) | `wpia_provider_error`. |
| Contrôle `output_tokens > max_tokens` | Supprimé : la raison d'arrêt le remplace. |
| 429 | `wpia_rate_limited`, message « Le service est très sollicité, réessayez dans un instant. ». Les données de l'erreur contiennent `retry_after` (entier, en secondes) si l'en-tête `retry-after` est présent et numérique. |
| 503 et 529 | `wpia_provider_unavailable`, même message que pour 429. |
| Nouvelle tentative automatique | **Non** : la requête du visiteur est synchrone, et une nouvelle tentative doublerait le temps d'attente et la réservation de tokens (005). |
| Corps de l'erreur | On extrait **uniquement un code machine**, jamais le message, car le message d'OpenAI peut reprendre un morceau de la clé (« Incorrect API key provided: sk-… »). Ordre : `error.code`, puis `error.type` (OpenAI, Anthropic), puis `code`, puis `type` (Mistral). Passé par `sanitize_key()` et coupé à 64 caractères. Pour Ollama (`error` est une chaîne libre) : pas de code. |
| Identifiant de requête | En-tête `x-request-id` (OpenAI, Mistral) ou `request-id` (Anthropic), conservé s'il correspond à `^[A-Za-z0-9_-]{1,128}$`. |
| Journalisation | Le `WP_Error` du normaliseur porte `status`, `provider_code` et `request_id` dans ses données. `logFailure()` les ajoute au message : « AI generation failed (anthropic, wpia_provider_error, HTTP 400, invalid_request_error, req_018…) ». Aucun contenu de prompt ni de réponse. |
| Réponse au visiteur | `PublicChatHandler` continue d'utiliser le message du `WP_Error`. Les données (`status`, `provider_code`, `request_id`, `retry_after`) ne sont **pas** renvoyées au navigateur. |

## Tâches

1. `ResponseNormalizer` : `extractStopReason()`, gestion de la troncature et du refus, suppression du contrôle `output_tokens > max_tokens`.
2. `ResponseNormalizer` : codes 429, 503 et 529 ; extraction de `provider_code`, `request_id` et `retry_after` dans les données du `WP_Error`.
3. `AiGenerationService::logFailure()` : message de journal enrichi. Nouveau journal `info` pour une réponse tronquée.
4. `PublicChatHandler` / `PublicChatController` : vérifier que les données du `WP_Error` ne sortent pas dans la réponse REST.
5. `documentation/generation-ia.md` : tableau des codes d'erreur et lecture des journaux.

## Hors périmètre

- Nouvelle tentative automatique et file d'attente.
- Indiquer au visiteur qu'une réponse est tronquée (`tronquee` est disponible pour une évolution du front de 007).
- Lire les en-têtes `x-ratelimit-*` / `anthropic-ratelimit-*` pour ralentir à l'avance.

## Critères d'acceptation

- Anthropic, HTTP 200, `stop_reason: "max_tokens"`, texte « Bonjour » → succès, `content` = « Bonjour », `tronquee` = true, une ligne `info` « Response truncated (anthropic, max_tokens) ».
- OpenAI, `finish_reason: "length"`, `content: ""` → `wpia_empty_response`.
- Anthropic, `stop_reason: "refusal"` → `wpia_refusal`, et les tokens sont confirmés auprès du limiteur.
- OpenAI, `finish_reason: "content_filter"` → `wpia_refusal`.
- HTTP 429 avec `retry-after: 12` → `wpia_rate_limited`, données `retry_after` = 12, réservation annulée.
- HTTP 529 (Anthropic) → `wpia_provider_unavailable`.
- HTTP 401, corps OpenAI `{"error":{"message":"Incorrect API key provided: sk-abc…","code":"invalid_api_key"}}` → le journal contient `invalid_api_key` et **ne contient pas** `sk-abc`.
- HTTP 400, corps Mistral `{"message":"…","type":"invalid_request_error","code":"unknown_model"}` → le journal contient `unknown_model`.
- La réponse REST du chat public ne contient ni `request_id` ni `provider_code`.

## Tests

Dans `tests/run.php` : un test par critère ci-dessus. Le bouchon `response()` devra accepter des en-têtes (`retry-after`, `request-id`) et `wp_remote_retrieve_header()` devra être ajouté aux bouchons.
