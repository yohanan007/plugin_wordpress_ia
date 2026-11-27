# API Ollama : guide de base

Connexion, en-têtes, envoi d'un message et lecture de la réponse avec l'API native d'Ollama (`POST /api/chat`).

> Documentation officielle : https://docs.ollama.com/api/introduction
> Endpoint chat : https://docs.ollama.com/api/chat
> Erreurs : https://docs.ollama.com/api/errors

---

## 1. Connexion

Ollama s'utilise de deux façons.

### A. En local (le cas le plus courant)

```
http://localhost:11434/api
```

- **Aucune authentification** : pas de clé API.
- Il faut qu'Ollama soit lancé (`ollama serve`, ou l'application de bureau) et que le modèle soit téléchargé :

```bash
ollama pull llama3.2
curl http://localhost:11434/api/tags    # liste les modèles installés
```

### B. Dans le cloud d'Ollama (ollama.com)

```
https://ollama.com/api
```

- Il faut une **clé API**, à créer sur https://ollama.com/settings/keys.
- Pas besoin d'installer Ollama.
- Les clés n'expirent pas, mais on peut les révoquer sur la même page. Gardez-les hors du code envoyé au navigateur et hors du dépôt Git.

```bash
export OLLAMA_API_KEY="..."
```

### Cas particulier : WSL2 et Docker

Avec WordPress et Ollama dans **le même environnement** (tous les deux dans WSL2), `localhost:11434` fonctionne directement. Sinon :

- **Ollama installé sur Windows, WordPress dans WSL2** : selon la configuration réseau de WSL, `localhost` peut ne pas atteindre Windows. Utilisez l'IP de l'hôte Windows, et lancez Ollama avec `OLLAMA_HOST=0.0.0.0` pour qu'il accepte les connexions extérieures.
- **WordPress dans un conteneur Docker** : utilisez `http://host.docker.internal:11434`.

Rendez donc l'URL de base **configurable** dans les réglages du plugin.

---

## 2. En-têtes HTTP

| En-tête | Local | Cloud | Valeur |
|---|---|---|---|
| `Content-Type` | **Oui** | **Oui** | `application/json` |
| `Authorization` | Non | **Oui** | `Bearer <OLLAMA_API_KEY>` |

Pas d'en-tête de version, d'organisation ni de projet.

---

## 3. Envoyer un message

### Endpoint

```
POST /api/chat
```

### Corps de la requête : paramètres principaux

| Paramètre | Type | Défaut | Description |
|---|---|---|---|
| `model` | string | — | **Obligatoire.** Nom du modèle installé (par ex. `llama3.2`, `mistral`, `gemma3`). |
| `messages` | tableau | — | **Obligatoire.** Historique de la conversation. |
| `stream` | boolean | **`true`** | ⚠️ Par défaut, la réponse arrive **en streaming**. Mettez `false` pour recevoir un seul objet JSON. |
| `options` | object | — | Réglages de génération (voir ci-dessous). |
| `format` | `"json"` ou schéma JSON | — | Force une réponse au format JSON. |
| `keep_alive` | string ou number | — | Durée pendant laquelle le modèle reste en mémoire (`"5m"`, ou `0` pour le décharger tout de suite). |
| `think` | boolean ou string | `null` | Active ou désactive le « raisonnement » des modèles qui le proposent. |
| `tools` | tableau | — | Outils que le modèle peut appeler (*function calling*). |

### Le sous-objet `options`

| Option | Description |
|---|---|
| `temperature` | Créativité (plus haut = plus aléatoire) |
| `num_predict` | Nombre maximal de tokens générés (l'équivalent de `max_tokens`) |
| `num_ctx` | Taille du contexte, en tokens |
| `top_p` / `top_k` | Échantillonnage |
| `stop` | Texte(s) qui arrêtent la génération |
| `seed` | Graine fixe, pour des réponses reproductibles |

> **`num_ctx` est important pour un chatbot avec une base de connaissances.** Si le contexte est trop petit pour vos extraits Markdown et l'historique, le modèle « oublie » le début du prompt sans renvoyer d'erreur.

### Rôles des messages

Chaque message est `{ "role": ..., "content": ... }` :

- `system` : les consignes du bot ;
- `user` : les messages de l'utilisateur ;
- `assistant` : les réponses précédentes du modèle ;
- `tool` : le résultat d'un appel d'outil.

Un message peut aussi contenir `images` (tableau d'images en base64), pour les modèles multimodaux.

### Exemple minimal (cURL, local)

```bash
curl http://localhost:11434/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "model": "llama3.2",
    "stream": false,
    "messages": [
      { "role": "system", "content": "Tu es l assistant du site. Réponds en français, brièvement." },
      { "role": "user",   "content": "Quels sont vos horaires d ouverture ?" }
    ],
    "options": { "temperature": 0.3, "num_predict": 800 }
  }'
```

### Exemple cloud

Même requête, avec l'URL du cloud et la clé :

```bash
curl https://ollama.com/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OLLAMA_API_KEY" \
  -d '{ "model": "...", "stream": false, "messages": [ ... ] }'
```

### Conversation sur plusieurs tours

Comme chez Mistral, l'API est **sans état** : renvoyez à chaque appel tout l'historique (le message `system`, puis les messages `user` et `assistant` en alternance, puis la nouvelle question).

---

## 4. Lire la réponse

### Structure (`stream: false`)

```json
{
  "model": "llama3.2",
  "created_at": "2025-10-17T23:14:07.414671Z",
  "message": {
    "role": "assistant",
    "content": "Nous sommes ouverts du lundi au vendredi, de 9h à 18h."
  },
  "done": true,
  "done_reason": "stop",
  "total_duration": 174560334,
  "load_duration": 101397084,
  "prompt_eval_count": 11,
  "prompt_eval_duration": 13074791,
  "eval_count": 18,
  "eval_duration": 52479709
}
```

### Champs à retenir

| Champ | Rôle |
|---|---|
| `message.content` | **Le texte de la réponse** |
| `message.thinking` | Le raisonnement du modèle, si `think` est activé |
| `done` / `done_reason` | Fin de la génération et sa raison (`stop`, `length`…) |
| `prompt_eval_count` | Tokens en entrée |
| `eval_count` | Tokens générés |
| `total_duration` | Durée totale, **en nanosecondes** |
| `load_duration` | Temps de chargement du modèle (élevé au premier appel, quand le modèle n'est pas encore en mémoire) |

### En streaming (`stream: true`, le défaut)

La réponse arrive au format **NDJSON** (`application/x-ndjson`) : une ligne JSON par morceau, chacune avec un bout de `message.content`. Le dernier morceau a `"done": true`.

```
{"model":"llama3.2","created_at":"...","message":{"role":"assistant","content":"Nous"},"done":false}
{"model":"llama3.2","created_at":"...","message":{"role":"assistant","content":" sommes"},"done":false}
...
{"model":"llama3.2","created_at":"...","message":{"role":"assistant","content":""},"done":true}
```

> Contrairement à OpenAI, Mistral et Anthropic, ce n'est **pas** du Server-Sent Events (`data: ...`) : chaque ligne est un objet JSON brut.

---

## 5. Exemple complet en PHP (WordPress)

```php
/**
 * @param array $history Messages précédents : [ ['role' => 'user', 'content' => '...'], ['role' => 'assistant', ...] ]
 */
function ollama_send_message( string $message, array $history = [] ) {
    $base_url = get_option( 'mon_plugin_ollama_url', 'http://localhost:11434' );
    $api_key  = get_option( 'mon_plugin_ollama_key', '' ); // vide en local

    $messages   = [ [ 'role' => 'system', 'content' => 'Tu es l\'assistant du site. Réponds en français.' ] ];
    $messages   = array_merge( $messages, $history );
    $messages[] = [ 'role' => 'user', 'content' => $message ];

    $headers = [ 'Content-Type' => 'application/json' ];
    if ( $api_key ) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $response = wp_remote_post( trailingslashit( $base_url ) . 'api/chat', [
        'headers' => $headers,
        'body'    => wp_json_encode( [
            'model'    => 'llama3.2',
            'stream'   => false,          // indispensable avec wp_remote_post
            'messages' => $messages,
            'options'  => [
                'temperature' => 0.3,
                'num_predict' => 800,
                'num_ctx'     => 8192,
            ],
        ] ),
        'timeout' => 120, // un modèle local peut être lent, surtout au premier chargement
    ] );

    if ( is_wp_error( $response ) ) {
        // Souvent : Ollama n'est pas lancé, ou l'URL n'est pas joignable (WSL2 / Docker)
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        // Chez Ollama, "error" est une simple chaîne de caractères.
        $msg = $data['error'] ?? 'Erreur inconnue';
        error_log( sprintf( '[Ollama] HTTP %d — %s', $code, $msg ) );
        return new WP_Error( 'ollama_error', $msg, [ 'status' => $code ] );
    }

    return [
        'text'          => $data['message']['content'] ?? '',
        'finish_reason' => $data['done_reason'] ?? null,
        'usage'         => [
            'input_tokens'  => $data['prompt_eval_count'] ?? null,
            'output_tokens' => $data['eval_count'] ?? null,
        ],
    ];
}
```

---

## 6. Erreurs

### Format

Le message d'erreur est une **simple chaîne** dans la propriété `error` :

```json
{ "error": "model \"llama9\" not found, try pulling it first" }
```

Si l'erreur survient **pendant un streaming**, le serveur envoie une ligne NDJSON contenant `error`, mais le code HTTP reste 200, puisque la réponse a déjà commencé. Vérifiez donc la présence de `error` dans chaque ligne.

### Codes HTTP

| Code | Signification | Que faire |
|---|---|---|
| 400 | Requête invalide (paramètre manquant, JSON incorrect) | Corriger la requête |
| 404 | Modèle introuvable | `ollama pull <modèle>`, vérifier le nom exact avec `/api/tags` |
| 429 | Limite de débit dépassée (surtout en cloud) | Réessayer avec un délai croissant |
| 500 | Erreur interne (mémoire insuffisante, modèle trop gros…) | Vérifier les logs d'Ollama, essayer un modèle plus petit |
| 502 | Modèle cloud injoignable | Réessayer plus tard |
| *(pas de réponse)* | Ollama non lancé, mauvaise URL ou port | Vérifier `ollama serve` et l'URL de base |

---

## 7. Alternative : l'API compatible OpenAI

Ollama propose aussi un endpoint **compatible OpenAI Chat Completions** :

```
POST http://localhost:11434/v1/chat/completions
```

Le format de requête et de réponse est alors celui d'OpenAI ou de Mistral (`messages`, `choices[0].message.content`). C'est pratique pour réutiliser le même code PHP que pour Mistral, en changeant seulement l'URL. En local, la clé envoyée est ignorée. Ollama ne prend en charge qu'une partie de l'API OpenAI.

---

## 8. Bonnes pratiques

- **Mettez toujours `"stream": false`** si vous n'avez pas prévu de lire du NDJSON.
- **Rendez l'URL de base configurable** (localhost, IP de l'hôte, Docker, cloud).
- **Prévoyez un `timeout` long** : le premier appel charge le modèle en mémoire.
- **Réglez `num_ctx`** en fonction de la taille de vos extraits Markdown et de l'historique.
- **Utilisez `keep_alive`** (par exemple `"30m"`) pour éviter de recharger le modèle à chaque question.
- **Vérifiez la présence du modèle** au démarrage avec `GET /api/tags`, pour afficher une erreur claire dans l'admin du plugin.
