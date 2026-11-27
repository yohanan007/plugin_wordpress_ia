# API Anthropic (Claude) : guide de base

Connexion, en-têtes, envoi d'un message et lecture de la réponse avec l'API **Messages** d'Anthropic (`POST /v1/messages`).

> Documentation officielle : https://platform.claude.com/docs
> Créer un message : https://platform.claude.com/docs/en/api/messages/create
> Erreurs : https://platform.claude.com/docs/en/api/errors
> Limites de débit : https://platform.claude.com/docs/en/api/rate-limits

---

## 1. Connexion

### URL de base

```
https://api.anthropic.com/v1
```

### Clé API

1. Créez une clé dans la Claude Console (https://platform.claude.com). Chaque clé est rattachée à un *workspace*.
2. Gardez-la **côté serveur uniquement**, dans une variable d'environnement, une constante dans `wp-config.php` ou une option chiffrée.
3. **Ne l'exposez jamais** dans du JavaScript envoyé au navigateur.

```bash
export ANTHROPIC_API_KEY="sk-ant-..."
```

```php
// wp-config.php
define( 'ANTHROPIC_API_KEY', 'sk-ant-...' );
```

---

## 2. En-têtes HTTP

### En-têtes de requête

| En-tête | Obligatoire | Valeur |
|---|---|---|
| `x-api-key` | **Oui** | Votre clé API |
| `anthropic-version` | **Oui** | `2023-06-01` |
| `content-type` | **Oui** | `application/json` |
| `anthropic-beta` | Non | Active une fonctionnalité en bêta (par ex. `nom-de-la-feature-2025-xx-xx`) |

> ⚠️ **Différence importante** avec les autres API : la clé ne passe **pas** par `Authorization: Bearer`, mais par l'en-tête `x-api-key`.
>
> `anthropic-version` fixe le format de l'API utilisé. Une version plus récente, `2026-10-21`, existe. Elle supprime notamment `temperature`, `top_p` et `top_k` pour tous les modèles. Consultez la page *Versioning* (https://platform.claude.com/docs/en/api/versioning) avant de changer de version.

### En-têtes de réponse utiles

| En-tête | Contenu |
|---|---|
| `request-id` | ID unique de la requête (par ex. `req_018EeWyXxfu5pfWkrYcMdjWG`) : **à journaliser**, le support le demande |
| `anthropic-ratelimit-requests-limit` / `-remaining` / `-reset` | Limite de requêtes, nombre restant et date de remise à zéro |
| `anthropic-ratelimit-tokens-limit` / `-remaining` / `-reset` | Idem pour les tokens |
| `anthropic-ratelimit-input-tokens-*` / `anthropic-ratelimit-output-tokens-*` | Idem, séparément pour les tokens d'entrée et de sortie |
| `retry-after` | Avec une erreur 429 : nombre de secondes à attendre avant de réessayer |

---

## 3. Envoyer un message

### Endpoint

```
POST https://api.anthropic.com/v1/messages
```

### Corps de la requête : paramètres principaux

| Paramètre | Type | Description |
|---|---|---|
| `model` | string | **Obligatoire.** ID du modèle : `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`… |
| `max_tokens` | integer | **Obligatoire** (contrairement aux autres API). Nombre maximal de tokens générés. |
| `messages` | tableau | **Obligatoire.** Messages `user` et `assistant`. |
| `system` | string | Consignes du bot (prompt système). **Paramètre à part**, pas un message. |
| `stop_sequences` | tableau | Textes qui arrêtent la génération. |
| `stream` | boolean | `true` pour recevoir la réponse au fil de l'eau (Server-Sent Events). |
| `tools` | tableau | Outils que le modèle peut appeler. |

> ⛔ **N'envoyez pas `temperature`, `top_p` ni `top_k`.** Les modèles Claude 4.7 et suivants (donc tous les modèles 5.x cités ici) ne les acceptent plus : une valeur autre que celle par défaut provoque une **erreur 400**. Avec la version d'API `2026-10-21`, ces paramètres sont supprimés pour tous les modèles, et n'importe quelle valeur provoque une erreur 400. Pour obtenir des réponses plus factuelles ou plus créatives, **passez par les consignes** du paramètre `system` (par exemple : « Réponds uniquement à partir du contexte fourni, sans extrapoler »).

### Rôles des messages

- Seuls deux rôles existent dans `messages` : **`user`** et **`assistant`**.
- Il n'y a **pas de rôle `system`** : les consignes vont dans le paramètre `system`, au premier niveau du JSON.
- `content` peut être une chaîne, ou un tableau de blocs (`{"type": "text", "text": "..."}`, images, documents…).

### Exemple minimal (cURL)

```bash
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-haiku-5-5",
    "max_tokens": 1024,
    "system": "Tu es l assistant du site. Réponds en français, brièvement.",
    "messages": [
      { "role": "user", "content": "Quels sont vos horaires d ouverture ?" }
    ]
  }'
```

### Conversation sur plusieurs tours

L'API est **sans état** : renvoyez à chaque appel tout l'historique, en alternant `user` et `assistant`. Le premier message doit être un message `user`.

```json
{
  "model": "claude-haiku-5-5",
  "max_tokens": 1024,
  "system": "Tu es l assistant du site.",
  "messages": [
    { "role": "user",      "content": "Quels sont vos horaires ?" },
    { "role": "assistant", "content": "Du lundi au vendredi, de 9h à 18h." },
    { "role": "user",      "content": "Et le samedi ?" }
  ]
}
```

---

## 4. Lire la réponse

### Structure

```json
{
  "id": "msg_013Zva2CMHLNnXjNJJKqJ2EF",
  "type": "message",
  "role": "assistant",
  "model": "claude-haiku-5-5",
  "content": [
    {
      "type": "text",
      "text": "Nous sommes ouverts du lundi au vendredi, de 9h à 18h."
    }
  ],
  "stop_reason": "end_turn",
  "stop_sequence": null,
  "stop_details": null,
  "usage": {
    "input_tokens": 42,
    "output_tokens": 18
  }
}
```

### Champs à retenir

| Champ | Rôle |
|---|---|
| `content[]` | **Tableau de blocs.** Le texte est dans les blocs `type: "text"` → `text` |
| `stop_reason` | Pourquoi la génération s'est arrêtée (voir ci-dessous) |
| `usage` | `input_tokens` / `output_tokens` |
| `id` | Identifiant du message |

> `content` est **toujours un tableau**, même pour une réponse simple. Il peut contenir d'autres blocs (`tool_use`, `thinking`…) : ne lisez que les blocs `text`.

### Valeurs de `stop_reason`

| Valeur | Signification | Que faire |
|---|---|---|
| `end_turn` | Réponse terminée normalement | Utiliser la réponse |
| `max_tokens` | Limite `max_tokens` atteinte : réponse coupée | Augmenter `max_tokens` |
| `stop_sequence` | Une de vos `stop_sequences` a été générée | Lire `stop_sequence` |
| `tool_use` | Le modèle demande l'appel d'un outil | Exécuter l'outil et renvoyer le résultat |
| `pause_turn` | Une boucle d'outils côté serveur a atteint sa limite | Renvoyer le contenu pour continuer |
| `refusal` | Le modèle refuse de répondre (renvoyé avec un **HTTP 200**, pas une erreur) | Lire `stop_details`, afficher un message adapté |
| `model_context_window_exceeded` | La fenêtre de contexte est pleine | Considérer la réponse comme tronquée |

### Extraire le texte (PHP)

```php
function anthropic_extract_text( array $data ): string {
    $text = '';
    foreach ( $data['content'] ?? [] as $block ) {
        if ( ( $block['type'] ?? '' ) === 'text' ) {
            $text .= $block['text'];
        }
    }
    return $text;
}
```

---

## 5. Exemple complet en PHP (WordPress)

```php
/**
 * @param array $history Messages précédents : [ ['role' => 'user', 'content' => '...'], ['role' => 'assistant', ...] ]
 */
function anthropic_send_message( string $message, array $history = [] ) {
    $messages   = $history;
    $messages[] = [ 'role' => 'user', 'content' => $message ];

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => ANTHROPIC_API_KEY,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'model'       => 'claude-haiku-5-5',
            'max_tokens'  => 1024,
            'system'      => 'Tu es l\'assistant du site. Réponds en français.',
            'messages'    => $messages,
            // Pas de 'temperature' : refusé (erreur 400) par les modèles Claude 4.7 et suivants.
        ] ),
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response; // erreur réseau
    }

    $code       = wp_remote_retrieve_response_code( $response );
    $data       = json_decode( wp_remote_retrieve_body( $response ), true );
    $request_id = wp_remote_retrieve_header( $response, 'request-id' );

    if ( $code !== 200 ) {
        $msg  = $data['error']['message'] ?? 'Erreur inconnue';
        $type = $data['error']['type'] ?? 'unknown';
        error_log( sprintf( '[Anthropic] HTTP %d — %s: %s (request id: %s)', $code, $type, $msg, $request_id ) );
        return new WP_Error( 'anthropic_error', $msg, [
            'status'      => $code,
            'type'        => $type,
            'retry_after' => wp_remote_retrieve_header( $response, 'retry-after' ),
        ] );
    }

    return [
        'text'          => anthropic_extract_text( $data ),
        'finish_reason' => $data['stop_reason'] ?? null,
        'usage'         => $data['usage'] ?? null,
    ];
}
```

---

## 6. Erreurs

### Format

Les erreurs sont toujours en JSON, avec un objet `error` (qui contient `type` et `message`) et un champ `request_id` :

```json
{
  "type": "error",
  "error": {
    "type": "not_found_error",
    "message": "The requested resource could not be found."
  },
  "request_id": "req_011CSHoEeqs5C35K2UUqR7Fy"
}
```

### Codes HTTP

| Code | `error.type` | Signification | Réessayer ? |
|---|---|---|---|
| 400 | `invalid_request_error` | Requête mal formée ou contenu invalide. Aussi renvoyé quand une limite de dépense que vous avez fixée est atteinte. | Non |
| 401 | `authentication_error` | Clé mal formée, révoquée ou expirée | Non |
| 402 | `billing_error` | Problème de facturation ou de paiement | Non |
| 403 | `permission_error` | La clé n'a pas accès à cette ressource | Non |
| 404 | `not_found_error` | Ressource introuvable (endpoint, ID de modèle) | Non |
| 413 | `request_too_large` | Requête trop volumineuse | Non |
| 429 | `rate_limit_error` | Limite de débit ou plafond de dépense atteint | **Oui**, après `retry-after` (s'il est absent, c'est un plafond de dépense : réessayer ne sert à rien) |
| 500 | `api_error` | Erreur interne chez Anthropic | **Oui**, sauf si l'en-tête `x-should-retry` vaut `false` |
| 504 | `timeout_error` | Délai dépassé pendant le traitement | **Oui** (ou passer en streaming pour les longues réponses) |
| 529 | `overloaded_error` | API temporairement surchargée | **Oui**, avec un délai croissant |

> Le code **529** est propre à Anthropic : prévoyez-le dans votre logique de nouvelle tentative.

---

## 7. Comparatif des quatre API

| | OpenAI (Responses) | Mistral | Ollama | Anthropic |
|---|---|---|---|---|
| Endpoint | `/v1/responses` | `/v1/chat/completions` | `/api/chat` | `/v1/messages` |
| Authentification | `Authorization: Bearer` | `Authorization: Bearer` | Aucune en local, `Bearer` en cloud | **`x-api-key`** + `anthropic-version` |
| Consignes système | `instructions` | message `system` | message `system` | paramètre **`system`** |
| `max_tokens` | facultatif (`max_output_tokens`) | facultatif | facultatif (`options.num_predict`) | **obligatoire** |
| Texte de la réponse | `output[]` → `output_text` | `choices[0].message.content` | `message.content` | `content[]` → blocs `text` |
| Fin de génération | `status` | `finish_reason` | `done_reason` | `stop_reason` |
| Tokens | `input_tokens` / `output_tokens` | `prompt_tokens` / `completion_tokens` | `prompt_eval_count` / `eval_count` | `input_tokens` / `output_tokens` |
| Corps d'erreur | `error.message` | `message` (à la racine) | `error` (chaîne) | `error.message` |
| Streaming | SSE | SSE | NDJSON, **activé par défaut** | SSE |

---

## 8. Bonnes pratiques

- **Journalisez `request-id`** pour chaque requête en production.
- **N'oubliez pas `max_tokens`** : la requête échoue (400) sans lui.
- **N'envoyez jamais `temperature`, `top_p` ni `top_k`** : guidez le ton et la précision par les consignes du paramètre `system`. Si votre plugin partage un réglage « température » entre fournisseurs, retirez-le de la requête pour Anthropic.
- **Gérez `stop_reason: "refusal"`** : c'est une réponse 200, il faut donc la détecter vous-même.
- **Réessayez seulement les erreurs 429, 500, 504 et 529**, en respectant `retry-after`.
- **Surveillez les en-têtes `anthropic-ratelimit-*-remaining`** pour ralentir avant d'atteindre une erreur 429.
- **Fixez l'ID du modèle** en production et vérifiez la liste des modèles sur la page *Models* de la doc.
