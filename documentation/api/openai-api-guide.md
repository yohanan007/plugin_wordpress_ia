# API OpenAI : guide de base

Connexion, en-têtes, envoi d'un message et lecture de la réponse avec l'API **Responses** (`POST /v1/responses`), l'endpoint qu'OpenAI recommande pour les nouveaux projets.

> Documentation officielle : https://developers.openai.com/api/docs
> Référence complète : https://developers.openai.com/api/reference/overview

---

## 1. Connexion

### URL de base

```
https://api.openai.com/v1
```

Toutes les requêtes passent en HTTPS et envoient du JSON.

### Clé API

1. Créez une clé sur https://platform.openai.com/settings/organization/api-keys
2. Gardez-la **côté serveur uniquement**, dans une variable d'environnement, une constante dans `wp-config.php` ou une option chiffrée.
3. **Ne l'exposez jamais** dans du JavaScript envoyé au navigateur. Le front doit appeler votre serveur (par exemple un endpoint REST WordPress), et c'est le serveur qui appelle OpenAI.

```bash
export OPENAI_API_KEY="sk-..."
```

```php
// wp-config.php
define( 'OPENAI_API_KEY', 'sk-...' );
```

> Une clé révoquée cesse de fonctionner en quelques secondes. Les autres changements d'authentification peuvent mettre jusqu'à 15 minutes à s'appliquer.

---

## 2. En-têtes HTTP

### En-têtes de requête

| En-tête | Obligatoire | Valeur / rôle |
|---|---|---|
| `Authorization` | **Oui** | `Bearer <OPENAI_API_KEY>` |
| `Content-Type` | **Oui** (POST) | `application/json` |
| `OpenAI-Organization` | Non | ID de l'organisation, si vous appartenez à plusieurs organisations |
| `OpenAI-Project` | Non | ID du projet ; l'usage est facturé à ce projet |
| `X-Client-Request-Id` | Non | Votre propre ID unique (ASCII, 512 caractères max), utile pour tracer une requête |

Taille totale des en-têtes : moins de 64 Kio.

### En-têtes de réponse utiles

| En-tête | Contenu |
|---|---|
| `x-request-id` | ID de la requête côté OpenAI : **à journaliser**, le support le demande |
| `openai-processing-ms` | Temps de traitement côté serveur |
| `x-ratelimit-remaining-requests` | Nombre de requêtes restantes dans la fenêtre |
| `x-ratelimit-remaining-tokens` | Nombre de tokens restants dans la fenêtre |
| `x-ratelimit-reset-requests` / `x-ratelimit-reset-tokens` | Délai avant la remise à zéro des limites |

---

## 3. Envoyer un message

### Endpoint

```
POST https://api.openai.com/v1/responses
```

### Corps de la requête : paramètres principaux

| Paramètre | Type | Description |
|---|---|---|
| `model` | string | **Obligatoire.** ID du modèle (par ex. `gpt-6.2-sol`, conseillé comme point de départ, ou `gpt-6-luna` pour les gros volumes à petit coût). La liste à jour est sur la page *Models* de la doc. |
| `reasoning` | object | Effort de raisonnement : `{ "effort": "low" }`. Valeurs possibles selon le modèle : `none`, `low`, `medium` (par défaut), `high`, `xhigh`, `max`. Pour un chatbot de FAQ, `low` réduit la latence et le coût. |
| `input` | string ou tableau | **Obligatoire.** Le message de l'utilisateur (texte simple) ou une liste de messages avec un rôle. |
| `instructions` | string | Message système ou développeur : le rôle et les règles du bot. |
| `previous_response_id` | string | ID de la réponse précédente, pour enchaîner une conversation. |
| `max_output_tokens` | integer | Limite de tokens générés (raisonnement compris). |
| `store` | boolean | Conserve la réponse côté OpenAI (`true` par défaut). Nécessaire pour `previous_response_id`. |
| `stream` | boolean | `true` pour recevoir la réponse au fil de l'eau (Server-Sent Events). |

> ⛔ **N'envoyez pas `temperature` ni `top_p`** avec les modèles GPT-6. Ce sont des modèles de raisonnement : dès que l'effort de raisonnement n'est pas `none`, ces paramètres doivent être retirés de la requête, sinon elle est refusée (erreur 400). Certains modèles, comme GPT-6.2 Sol et GPT-6 Astra, refusent en plus l'effort `none` : avec eux, `temperature` n'est jamais utilisable. Guidez le ton et la précision par les `instructions`.
>
> `max_output_tokens` compte aussi les tokens de raisonnement. S'il est trop bas, la réponse peut revenir avec `status: "incomplete"` et un texte vide ou tronqué.

### Exemple minimal (cURL)

```bash
curl https://api.openai.com/v1/responses \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -d '{
    "model": "gpt-6.2-sol",
    "instructions": "Tu es l assistant du site. Réponds en français, brièvement.",
    "input": "Quels sont vos horaires d ouverture ?"
  }'
```

### Exemple avec des rôles (historique fourni par vous)

```json
{
  "model": "gpt-6.2-sol",
  "input": [
    { "role": "developer", "content": "Réponds uniquement à partir du contexte fourni." },
    { "role": "user",      "content": "Contexte :\n<extraits Markdown>\n\nQuestion : comment me désinscrire ?" }
  ]
}
```

Les rôles possibles sont :
- `developer` (ou `system`) : les consignes, prioritaires sur celles de l'utilisateur ;
- `user` : les messages de l'utilisateur ;
- `assistant` : les réponses précédentes du modèle.

### Conversation sur plusieurs tours

Il y a deux façons de garder l'historique :

1. **Côté OpenAI** : passez l'`id` de la réponse précédente dans `previous_response_id`. Attention, les `instructions` ne sont **pas** reprises d'un tour à l'autre : renvoyez-les à chaque appel.
2. **Côté serveur** : stockez vous-même l'historique et renvoyez à chaque appel le tableau `input` complet (`user` / `assistant` alternés).

```json
{
  "model": "gpt-6.2-sol",
  "previous_response_id": "resp_abc123",
  "instructions": "Tu es l assistant du site.",
  "input": "Et le samedi ?"
}
```

---

## 4. Lire la réponse

### Structure (simplifiée)

```json
{
  "id": "resp_abc123",
  "object": "response",
  "created_at": 1764170000,
  "status": "completed",
  "model": "gpt-6.2-sol",
  "output": [
    {
      "id": "msg_...",
      "type": "message",
      "role": "assistant",
      "status": "completed",
      "content": [
        {
          "type": "output_text",
          "text": "Nous sommes ouverts du lundi au vendredi, de 9h à 18h.",
          "annotations": []
        }
      ]
    }
  ],
  "previous_response_id": null,
  "usage": {
    "input_tokens": 42,
    "output_tokens": 18,
    "total_tokens": 60
  }
}
```

### Champs à retenir

| Champ | Rôle |
|---|---|
| `id` | À conserver pour le tour suivant (`previous_response_id`) |
| `status` | `completed`, `incomplete` (par ex. limite `max_output_tokens` atteinte), `failed`… |
| `output[]` | Liste d'éléments. Le texte se trouve dans les éléments `type: "message"` → `content[]` → `type: "output_text"` → `text` |
| `usage` | Tokens consommés, pour suivre les coûts |

> Les SDK officiels exposent un raccourci `response.output_text` qui assemble tout le texte. En HTTP brut, le plus sûr est de parcourir `output[]` comme ci-dessous.

### Extraire le texte (PHP)

```php
function openai_extract_text( array $data ): string {
    $text = '';
    foreach ( $data['output'] ?? [] as $item ) {
        if ( ( $item['type'] ?? '' ) !== 'message' ) {
            continue;
        }
        foreach ( $item['content'] ?? [] as $part ) {
            if ( ( $part['type'] ?? '' ) === 'output_text' ) {
                $text .= $part['text'];
            }
        }
    }
    return $text;
}
```

---

## 5. Exemple complet en PHP (WordPress)

```php
function openai_send_message( string $message, ?string $previous_id = null ) {
    $body = [
        'model'        => 'gpt-6.2-sol',
        'reasoning'    => [ 'effort' => 'low' ], // pas de 'temperature' : refusé par les modèles de raisonnement
        'instructions' => 'Tu es l\'assistant du site. Réponds en français.',
        'input'        => $message,
    ];
    if ( $previous_id ) {
        $body['previous_response_id'] = $previous_id;
    }

    $response = wp_remote_post( 'https://api.openai.com/v1/responses', [
        'headers' => [
            'Authorization' => 'Bearer ' . OPENAI_API_KEY,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response; // erreur réseau
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? 'Erreur inconnue';
        error_log( sprintf(
            '[OpenAI] HTTP %d — %s (request id: %s)',
            $code, $msg, wp_remote_retrieve_header( $response, 'x-request-id' )
        ) );
        return new WP_Error( 'openai_error', $msg, [ 'status' => $code ] );
    }

    return [
        'id'    => $data['id'],                  // à garder pour le tour suivant
        'text'  => openai_extract_text( $data ),
        'usage' => $data['usage'] ?? null,
    ];
}
```

Pensez à `timeout` : la valeur par défaut de `wp_remote_post` (5 s) est trop courte pour un modèle de langage.

---

## 6. Erreurs

En cas d'erreur, le corps de la réponse contient un objet `error` :

```json
{
  "error": {
    "message": "Incorrect API key provided: sk-....",
    "type": "invalid_request_error",
    "param": null,
    "code": "invalid_api_key"
  }
}
```

| Code HTTP | Cause probable | Que faire |
|---|---|---|
| 400 | Requête mal formée (paramètre invalide, JSON incorrect) | Corriger le corps de la requête |
| 401 | Clé absente, invalide ou révoquée | Vérifier `Authorization` et la clé |
| 403 | Pays, région ou modèle non autorisé | Vérifier les accès du projet |
| 404 | Modèle ou ressource introuvable | Vérifier l'ID du modèle ou de la réponse |
| 429 | Limite de débit dépassée **ou** crédit épuisé | Réessayer avec un délai croissant (*backoff*) ; vérifier la facturation |
| 500 / 503 | Erreur ou surcharge côté OpenAI | Réessayer après un court délai |

Détail des codes : https://developers.openai.com/api/docs/guides/error-codes

---

## 7. Bonnes pratiques

- **Journalisez `x-request-id`** pour chaque requête en production.
- **Fixez une version précise du modèle** : son comportement peut changer d'une version à l'autre.
- **Ne faites jamais d'appel depuis le navigateur** : passez par votre serveur.
- **Limitez `max_output_tokens`** pour maîtriser les coûts d'un chatbot public.
- **Surveillez `usage`** et les en-têtes `x-ratelimit-*`.
