# API Mistral AI : guide de base

Connexion, en-têtes, envoi d'un message et lecture de la réponse avec l'API **Chat Completions** de Mistral (`POST /v1/chat/completions`).

> Documentation officielle : https://docs.mistral.ai
> Référence de l'endpoint : https://docs.mistral.ai/api/endpoint/chat
> Glossaire des erreurs : https://docs.mistral.ai/resources/error-glossary

---

## 1. Connexion

### URL de base

```
https://api.mistral.ai/v1
```

Toutes les requêtes passent en HTTPS et envoient du JSON.

### Clé API

1. Créez une clé dans la console Mistral : https://console.mistral.ai/api-keys
2. Gardez-la **côté serveur uniquement**, dans une variable d'environnement, une constante dans `wp-config.php` ou une option chiffrée.
3. **Ne l'exposez jamais** dans du JavaScript envoyé au navigateur. Le front doit appeler votre serveur, et c'est le serveur qui appelle Mistral.

```bash
export MISTRAL_API_KEY="..."
```

```php
// wp-config.php
define( 'MISTRAL_API_KEY', '...' );
```

### Tester la clé

```bash
curl https://api.mistral.ai/v1/models \
  -H "Authorization: Bearer $MISTRAL_API_KEY"
```

Si la clé est bonne, la réponse liste les modèles disponibles pour votre compte. Sinon, vous recevez une erreur 401.

> Les limites de débit sont fixées au niveau de l'**organisation**. Le mode gratuit (par défaut) a des limites basses, pour tester. Le plan *Scale* (paiement à l'usage) donne accès à des limites plus élevées.

---

## 2. En-têtes HTTP

### En-têtes de requête

| En-tête | Obligatoire | Valeur |
|---|---|---|
| `Authorization` | **Oui** | `Bearer <MISTRAL_API_KEY>` |
| `Content-Type` | **Oui** (POST) | `application/json` |
| `Accept` | Non | `application/json` (ou `text/event-stream` en streaming) |

Contrairement à OpenAI, il n'y a pas d'en-tête d'organisation ou de projet : la clé est rattachée à un *workspace* dans la console.

### En-têtes de réponse utiles

| En-tête | Contenu |
|---|---|
| `Retry-After` | Renvoyé avec une erreur 429 : nombre de secondes à attendre avant de réessayer |

---

## 3. Envoyer un message

### Endpoint

```
POST https://api.mistral.ai/v1/chat/completions
```

### Corps de la requête : paramètres principaux

| Paramètre | Type | Défaut | Description |
|---|---|---|---|
| `model` | string | — | **Obligatoire.** ID du modèle : `mistral-large-latest`, `mistral-medium-latest`, `mistral-small-latest`… |
| `messages` | tableau | — | **Obligatoire.** L'historique de la conversation (voir les rôles ci-dessous). |
| `temperature` | number | dépend du modèle | Créativité. Mistral recommande entre 0.0 et 0.7. Ne la modifiez pas en même temps que `top_p`. |
| `top_p` | number | — | Échantillonnage *nucleus* (alternative à `temperature`). |
| `max_tokens` | integer | — | Limite de tokens générés. Le prompt plus `max_tokens` ne doit pas dépasser le contexte du modèle. |
| `stream` | boolean | `false` | `true` pour recevoir la réponse au fil de l'eau (Server-Sent Events, terminés par `data: [DONE]`). |
| `stop` | string ou tableau | `null` | Arrête la génération dès que ce texte apparaît. |
| `random_seed` | integer | `null` | Graine fixe pour des réponses reproductibles. |
| `response_format` | object | `{"type":"text"}` | `json_object` (mode JSON) ou `json_schema` (JSON conforme à un schéma). |
| `safe_prompt` | boolean | `false` | Ajoute automatiquement une consigne de sécurité avant la conversation. |
| `reasoning_effort` | string | dépend du modèle | Effort de raisonnement, pour les modèles hybrides comme Mistral Small 4 (`mistral-small-latest`) : `"none"` (réflexion minimale, sans bloc de réflexion dans la réponse) ou `"high"` (réflexion complète, plus de tokens). |

> ✅ Chez Mistral, **`temperature` reste pris en charge**, y compris par les modèles qui raisonnent. C'est donc le seul des quatre fournisseurs où il garde tout son intérêt.
>
> ⚠️ `mistral-small-latest` pointe maintenant vers **Mistral Small 4**, un modèle hybride qui sait aussi raisonner. Avec ce modèle, `message.content` est **toujours un tableau de morceaux** (réflexion + texte), quel que soit `reasoning_effort`. La fonction d'extraction de la section 4 en tient compte : elle ne garde que les morceaux `text`. Les anciens modèles de raisonnement `magistral-*` sont dépréciés.

### Rôles des messages

Chaque message est un objet `{ "role": ..., "content": ... }` :

- `system` : les consignes du bot (rôle, ton, règles) ;
- `user` : les messages de l'utilisateur ;
- `assistant` : les réponses précédentes du modèle ;
- `tool` : le résultat d'un appel d'outil (*function calling*).

### Exemple minimal (cURL)

```bash
curl https://api.mistral.ai/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MISTRAL_API_KEY" \
  -d '{
    "model": "mistral-small-latest",
    "messages": [
      { "role": "system", "content": "Tu es l assistant du site. Réponds en français, brièvement." },
      { "role": "user",   "content": "Quels sont vos horaires d ouverture ?" }
    ]
  }'
```

### Conversation sur plusieurs tours

L'API est **sans état** : Mistral ne garde rien entre deux appels. Pour poursuivre une conversation, renvoyez à chaque appel **tout l'historique** (le message `system`, puis les messages `user` et `assistant` en alternance, puis la nouvelle question).

```json
{
  "model": "mistral-small-latest",
  "messages": [
    { "role": "system",    "content": "Tu es l assistant du site." },
    { "role": "user",      "content": "Quels sont vos horaires ?" },
    { "role": "assistant", "content": "Du lundi au vendredi, de 9h à 18h." },
    { "role": "user",      "content": "Et le samedi ?" }
  ]
}
```

Pensez à tronquer l'historique (garder les N derniers échanges) pour ne pas dépasser la taille du contexte ni faire grimper les coûts.

---

## 4. Lire la réponse

### Structure

```json
{
  "id": "cmpl-e5cc70bb28c444948073e77776eb30ef",
  "object": "chat.completion",
  "created": 1702256327,
  "model": "mistral-small-latest",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Nous sommes ouverts du lundi au vendredi, de 9h à 18h."
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 42,
    "completion_tokens": 18,
    "total_tokens": 60
  }
}
```

### Champs à retenir

| Champ | Rôle |
|---|---|
| `choices[0].message.content` | **Le texte de la réponse** |
| `choices[0].finish_reason` | Pourquoi la génération s'est arrêtée (voir ci-dessous) |
| `usage` | Tokens consommés (`prompt_tokens`, `completion_tokens`, `total_tokens`) |
| `id` | Identifiant de la requête, à journaliser |

### Valeurs de `finish_reason`

| Valeur | Signification |
|---|---|
| `stop` | Réponse terminée normalement |
| `length` | Coupée par la limite `max_tokens` |
| `model_length` | Coupée par la taille maximale du contexte du modèle |
| `tool_calls` | Le modèle demande l'appel d'un outil |
| `error` | Erreur pendant la génération |

> `content` peut être une chaîne **ou un tableau de morceaux** (`{"type": "thinking", ...}`, `{"type": "text", "text": "..."}`). Avec Mistral Small 4 (`mistral-small-latest`), c'est toujours un tableau. Le code ci-dessous gère les deux cas et ignore les morceaux de réflexion.

### Extraire le texte (PHP)

```php
function mistral_extract_text( array $data ): string {
    $content = $data['choices'][0]['message']['content'] ?? '';
    if ( is_string( $content ) ) {
        return $content;
    }
    $text = '';
    foreach ( (array) $content as $chunk ) {
        if ( ( $chunk['type'] ?? '' ) === 'text' ) {
            $text .= $chunk['text'];
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
function mistral_send_message( string $message, array $history = [] ) {
    $messages   = [ [ 'role' => 'system', 'content' => 'Tu es l\'assistant du site. Réponds en français.' ] ];
    $messages   = array_merge( $messages, $history );
    $messages[] = [ 'role' => 'user', 'content' => $message ];

    $response = wp_remote_post( 'https://api.mistral.ai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . MISTRAL_API_KEY,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'model'       => 'mistral-small-latest',
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => 800,
            'reasoning_effort' => 'none', // réponse rapide, sans bloc de réflexion
        ] ),
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response; // erreur réseau
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        // Chez Mistral, "message" est à la racine du JSON d'erreur (pas dans "error").
        $msg = $data['message'] ?? ( $data['detail'][0]['msg'] ?? 'Erreur inconnue' );
        error_log( sprintf( '[Mistral] HTTP %d — %s', $code, is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
        return new WP_Error( 'mistral_error', $msg, [
            'status'      => $code,
            'retry_after' => wp_remote_retrieve_header( $response, 'retry-after' ),
        ] );
    }

    return [
        'text'          => mistral_extract_text( $data ),
        'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
        'usage'         => $data['usage'] ?? null,
    ];
}
```

Pensez à `timeout` : la valeur par défaut de `wp_remote_post` (5 s) est trop courte pour un modèle de langage.

---

## 6. Erreurs

### Format

```json
{
  "object": "error",
  "message": "A human-readable description of the error.",
  "type": "invalid_request_error",
  "param": "model",
  "code": "unknown_model"
}
```

| Champ | Description |
|---|---|
| `message` | Description lisible de l'erreur |
| `type` | `invalid_request_error`, `authentication_error`, `rate_limit_error` ou `server_error` |
| `param` | Paramètre en cause, le cas échéant |
| `code` | Code d'erreur lisible par une machine, le cas échéant |

> Une erreur 422 (validation) peut détailler les champs fautifs dans le corps de la réponse. Journalisez le corps complet pour la déboguer.

### Codes HTTP

| Code | Signification | Que faire | Réessayer ? |
|---|---|---|---|
| 400 | Requête mal formée (JSON invalide, `model` ou `messages` manquant, valeur hors limites) | Corriger la requête | Non |
| 401 | Clé absente, invalide, expirée ou d'un autre workspace | Vérifier la clé et l'en-tête `Authorization` | Non |
| 403 | Modèle non inclus dans votre offre, ou droits insuffisants | Vérifier l'offre et l'accès au modèle | Non |
| 404 | Modèle ou endpoint inexistant | Vérifier l'ID du modèle et l'URL | Non |
| 422 | JSON valide mais valeurs incorrectes (mauvais type, valeur non autorisée) | Lire le détail des champs dans la réponse | Non |
| 429 | Limite de débit dépassée (requêtes par seconde, tokens par minute ou par mois) | Attendre la durée de `Retry-After`, puis réessayer avec un délai croissant | **Oui** |
| 500 | Erreur interne | Réessayer après un court délai | **Oui** |
| 502 | Erreur d'un service intermédiaire | Attendre 30 à 60 s | **Oui** |
| 503 | Service surchargé ou en maintenance | Réessayer avec un délai croissant | **Oui** |
| 504 | Requête trop longue | Réduire le prompt ou `max_tokens` | **Oui** |

---

## 7. Différences avec l'API OpenAI

| | OpenAI (Responses) | Mistral (Chat Completions) |
|---|---|---|
| Endpoint | `POST /v1/responses` | `POST /v1/chat/completions` |
| Consignes | `instructions` ou rôle `developer` | Message `role: "system"` |
| Message utilisateur | `input` | `messages[]` |
| Historique | `previous_response_id` (côté OpenAI) ou envoi complet | Toujours envoyé en entier par vous |
| Texte de la réponse | `output[]` → `message` → `output_text` | `choices[0].message.content` |
| Tokens | `usage.input_tokens` / `output_tokens` | `usage.prompt_tokens` / `completion_tokens` |
| Corps d'erreur | `{ "error": { "message": ... } }` | `{ "message": ..., "type": ... }` à la racine |

---

## 8. Bonnes pratiques

- **Appelez l'API depuis le serveur uniquement**, jamais depuis le navigateur.
- **Limitez `max_tokens`** et tronquez l'historique pour maîtriser les coûts d'un chatbot public.
- **Gardez `temperature` basse** (0.1 à 0.3) pour un bot qui répond à partir d'une base de connaissances : les réponses restent plus factuelles.
- **Réessayez seulement les erreurs 429 et 5xx**, en respectant `Retry-After`.
- **Fixez une version précise du modèle** en production plutôt qu'un alias `-latest`, dont le modèle peut changer sans prévenir.
