# Génération multi-tours : historique, contexte et choix du chatbot

> Nouvelle issue créée lors de la revue du 2026-11-11 pour combler un manque entre #8 (service IA) et les issues 005, 006 et 007.
> **Correspondance GitHub :** à créer.

## Pourquoi cette issue

`AiGenerationService::generate( string $prompt )` (livré par #8) ne fait **qu'un seul échange** :

- les fournisseurs construisent `messages` avec un unique message `user` (voir par exemple `AnthropicProvider::generate`) ;
- le contexte documentaire n'a pas de place séparée et devrait être concaténé à la question, ce qui mélange données et instructions ;
- le chatbot est toujours celui de l'option `wpia_active_configuration` ;
- les limites (005) n'ont pas d'informations sur l'appelant.

Les issues 005, 006 et 007 supposent toutes un service capable de faire mieux, mais aucune ne le prévoit. Cette issue est donc un **prérequis** commun.

**Dépend de :** #8 (livrée) · **Prérequis de :** [006](006-markdown.md), [007](007-chat-publique.md), [009](009-cadrage-garde-fous.md) · **À coordonner avec :** [005](005-appliquer_limite.md)

## Objectif

Le service de génération accepte une conversation complète (consignes, contexte, historique et question) pour un chatbot donné, et la transmet correctement à chacun des 5 fournisseurs.

## Décisions

| Sujet | Décision |
|---|---|
| Nouvelle entrée | `generateChat( array $requete ): array\|\WP_Error`, avec `$requete` = `chatbot_id` (int, facultatif ; par défaut le chatbot actif), `messages` (liste de `{role: user\|assistant, content}`, le dernier étant `user`), `contexte` (string, facultatif, produit par 006), `identites` (pour 005). Un tableau typé par PHPDoc suffit : une classe valeur n'est pas nécessaire (consigne « pas de sur-ingénierie » de #8). |
| Compatibilité | `generate( string $prompt )` est conservé et appelle `generateChat()` avec un seul message. Le test de connexion de l'admin ne change pas. |
| Choix du chatbot | `chatbot_id` donné : le compte API vient de `chatbot.compte_api_id` et le fournisseur de `compte_api.fournisseur_id`, avec les mêmes contrôles « actif » et la même validation d'URL qu'aujourd'hui. Absent : la configuration actuelle `wpia_active_configuration` est utilisée. |
| Prompt système | Construit **côté serveur** par 009 (consignes) et 006 (bloc `<documents>`). Il ne vient jamais de la requête du navigateur. |
| Historique | Le serveur ne garde que les `history_max_messages` derniers messages (réglage, **6** par défaut), puis retire les plus anciens jusqu'à respecter `history_token_budget` (réglage, **1 500** par défaut). Il est toujours tronqué au début, jamais au milieu. Le premier message conservé doit être un message `user`. |
| Anthropic | `system` séparé ; les rôles doivent alterner (deux messages `user` consécutifs sont fusionnés). Quand le bloc de contexte dépasse 1 024 tokens estimés, `system` est envoyé sous forme de blocs, celui du contexte portant `cache_control: {type: "ephemeral"}`. |
| OpenAI / Mistral / Custom / Ollama | Un message `system` en tête, puis l'historique. Le cache de prompt d'OpenAI est automatique quand le début du prompt est stable : le contexte est donc placé **avant** l'historique. |
| Retour | Le format actuel (`content`, `model`, `usage`) est inchangé. Il s'y ajoute `usage.cache_read_tokens` quand le fournisseur le renvoie. |

## Tâches

1. `AiGenerationService` : `generateChat()`, `loadConfiguration( ?int $chatbot_id )`, troncature de l'historique, validation des messages (rôles, types, longueurs, dernier message `user`).
2. `AbstractProvider` : le paramètre `prompt` est remplacé par `messages`, avec une méthode commune `buildMessages()`. Chaque fournisseur n'adapte que ses particularités (Anthropic : `system` et `cache_control` ; Ollama : format `chat`).
3. `ResponseNormalizer` : lire `cache_read_input_tokens` (Anthropic) et `prompt_tokens_details.cached_tokens` (OpenAI) quand ils sont présents.
4. Réglages `history_max_messages` et `history_token_budget` dans `AdminSettings` (section consommation).
5. `documentation/generation-ia.md` : nouvelle signature, exemple d'appel, ordre des éléments du prompt et raison de cet ordre (cache).

## Hors périmètre

Réponse en streaming, appels d'outils (*tool use*), résumé automatique de l'historique ancien.

## Critères d'acceptation

- `generate( 'Bonjour' )` produit exactement la même requête HTTP qu'avant (test de non-régression sur le corps envoyé, pour les 5 fournisseurs).
- Avec 10 messages d'historique et `history_max_messages` = 6, le corps envoyé contient les 6 derniers, et le premier est un message `user`.
- Un historique dont le dernier message est `assistant`, ou qui contient un rôle `system`, est refusé (`wpia_invalid_request`) sans appel au fournisseur.
- Anthropic : deux messages `user` consécutifs sont fusionnés, et `system` contient le bloc de contexte avec `cache_control` quand il dépasse le seuil.
- `chatbot_id` pointant vers un chatbot inactif : `wpia_configuration_error` sans appel.
- Le contenu du contexte et de l'historique n'apparaît pas dans les logs en cas d'erreur.

## Tests

Pour chaque fournisseur, comparer le corps JSON envoyé (avec un faux `wp_remote_post`) avec un corps attendu, dans les cas suivants : un seul message, historique, contexte, historique tronqué.
