# Génération multi-tours : historique et prompt système

> **Correspondance GitHub :** à créer. Révisée le 2026-11-23.
> **Statut :** livrée le 2026-11-23 (lots A et B). Le cache de prompt ne servira qu'avec le contexte volumineux de 006 lot B (`buildSystem()` ne renvoie pour l'instant que les consignes du chatbot).
> **Dépend de :** #8 et #11 (livrées). **Prérequis de :** [007](007-chat-publique.md) (lot A), [006](006-markdown.md) lot B et [009](009-cadrage-garde-fous-2.md) lot B (lot A).

## Pourquoi

`AiGenerationService::generate( $prompt, $contexte )` ne fait qu'un seul échange : chaque fournisseur reçoit une chaîne `system_prompt` et une chaîne `prompt`, et construit un unique message `user`. Le chat public (007) a besoin d'envoyer un historique, et 006 et 009 ont besoin d'une place pour le prompt système construit côté serveur.

## Objectif

Le service accepte une conversation (historique et question), construit le prompt système côté serveur, réserve la consommation sur tout ce qui est envoyé, et transmet le tout aux 5 fournisseurs.

## Les deux lots

| Lot | Contenu | Dépend de |
|---|---|---|
| **A** | Conversation multi-tours, validation, troncature, estimation des limites, réglages | rien : démarrable tout de suite |
| **B** | Cache de prompt (Anthropic), comptage des tokens en cache | 006 lot B |

Le lot A suffit à débloquer 007. Le lot B n'a d'intérêt que lorsque le prompt système est volumineux (documents de 006).

---

## Lot A : conversation multi-tours

### Décisions

| Sujet | Décision |
|---|---|
| Nouvelle entrée | `generateChat( array $requete ): array\|\WP_Error`. `$requete` contient `messages` (liste de `{role: user\|assistant, content}`, le dernier étant `user`), `chatbot_id` (facultatif) et `identites` (`{visiteur?, session?}`, comme l'actuel `$contexte` de `generate()`). |
| Compatibilité | `generate( $prompt, $contexte )` est conservé : il appelle `generateChat()` avec un seul message. Le test de connexion de l'admin ne change pas. |
| Chatbot | `chatbot_id` absent : chatbot de `wpia_active_configuration`. Présent : il doit être **égal** au chatbot actif, sinon `wpia_configuration_error` sans appel. Le type de fournisseur reste lu dans l'option (la table des fournisseurs n'a pas de colonne de type). |
| Chatbot « public » (007) | Pour le MVP, c'est le chatbot actif quand `chatbot_enabled` = 1. Le `chatbot_id` d'une requête HTTP n'est jamais pris tel quel. |
| Prompt système | Construit côté serveur par une méthode privée `buildSystem()`, qui renvoie `{stable, variable}`. Au lot A : `stable` = `system_prompt` du chatbot, `variable` vide. 006 et 009 remplaceront son contenu, sans changer son type de retour. |
| Validation | Refus `wpia_invalid_request`, sans appel et sans réservation, si : `messages` est vide ; un rôle n'est ni `user` ni `assistant` (donc tout `system`) ; un contenu n'est pas une chaîne ou est vide ; le dernier message n'est pas `user` ; il y a plus de **40** messages ; un message dépasse **8 000** caractères. (La limite de la question, 500 caractères, relève de 007.) |
| Option « Conserver l'historique » | Réglage `history_enabled` (case à cocher, **activé** par défaut). Désactivé, le serveur **ignore** l'historique reçu et n'envoie au fournisseur que la question : chaque message est traité seul, sans mémoire des échanges précédents (réponses moins cohérentes, mais requêtes moins chères). Les messages reçus sont tout de même validés. Cette option ne concerne que l'envoi au fournisseur ; elle n'a aucun lien avec le stockage des conversations en base (0004). |
| Historique | L'historique est l'ensemble des messages **sauf le dernier** (la question). Quand l'option est activée, on garde les `history_max_messages` derniers (réglage, **6** par défaut, de 1 à 20), puis on retire les plus anciens, deux par deux, tant que l'estimation dépasse `history_token_budget` (réglage, **1 500** par défaut, de 100 à 20 000). La troncature se fait toujours au début. Le premier message conservé doit être `user`. La question n'est jamais tronquée. Estimateur : `TokenEstimator::estimer()`. |
| Alternance | Pour tous les fournisseurs, deux messages consécutifs de même rôle sont fusionnés (séparés par une ligne vide). |
| Fournisseurs | OpenAI, Mistral, Custom, Ollama : un message `system` en tête (`stable` puis `variable`), puis l'historique, puis la question. Anthropic : `system` séparé, sous forme de chaîne. Pour un seul message, le corps envoyé reste **identique** à l'actuel. |
| Limites (005) | L'estimation du limiteur porte sur **toutes** les entrées : `stable`, `variable`, historique **conservé** et question, plus `max_tokens`. Elle est calculée après la troncature. Le limiteur garde sa règle (`strlen / 4`). |
| Valeurs par défaut | Le prompt système de 006 pèse environ 4 000 tokens : avec 10 000 tokens par jour, une seule requête passe. Nouveaux défauts : `daily_token_limit` **100 000**, `max_tokens_conversation` **40 000**. Ils s'appliquent à toute installation qui n'a pas encore enregistré ses réglages. |
| Retour | Inchangé (`content`, `model`, `usage`). |
| Journaux | Aucun contenu (messages, système, réponse) dans les logs ni dans les erreurs. |
| Cache de réponses (011) | Placé par 007 avant `generateChat()`. Rien à prévoir ici. |
| Persistance (0004) | Si elle est activée, 007 lit l'historique en base et le passe dans `messages`. Rien à prévoir ici. |
| Contrôle de sortie (009) | Il aura lieu dans `generateChat()`, après la normalisation et avant la confirmation au limiteur. Les tokens consommés restent comptés. Ajouté par 009 lot B, pas ici. |

### Tâches

1. **`AbstractProvider`** : méthode commune `buildMessages()` (fusion des rôles) et construction de la liste de messages avec le message `system`.
2. **Les 5 fournisseurs** : les paramètres `system_prompt` et `prompt` sont remplacés par `systeme` (`{stable, variable}`) et `messages`. Les trois fournisseurs au code identique (OpenAI, Mistral, Custom) utilisent la méthode commune.
3. **`AiGenerationService`** : `generateChat()`, `buildSystem()`, validation, troncature, estimation sur toutes les entrées, `loadConfiguration( ?int $chatbot_id )`. `generate()` délègue.
4. **`AdminSettings`** : réglages `history_enabled` (case à cocher), `history_max_messages` et `history_token_budget` (section consommation), nouveaux défauts. Un avertissement s'affiche à l'enregistrement si `max_tokens_conversation` est inférieur à `max_tokens_request` + `history_token_budget`.
5. **Documentation** : `documentation/generation-ia.md` (signature, exemple d'appel, ordre des éléments du prompt) et `documentation/limites.md` (estimation sur toutes les entrées, nouveaux défauts).

### Critères d'acceptation

- `generate( 'Bonjour' )` produit exactement le même corps HTTP qu'avant, pour les 5 fournisseurs.
- Avec 10 messages d'historique plus la question et `history_max_messages` = 6, le corps contient les 6 derniers messages de l'historique, puis la question, et le premier message est `user`. Avec 5, il en contient 4.
- Avec `history_token_budget` bas, les plus anciens messages sont retirés deux par deux ; la question reste intacte.
- Sont refusés sans appel ni réservation : un dernier message `assistant`, un rôle `system`, un contenu vide, 41 messages, un message de plus de 8 000 caractères.
- Avec `history_enabled` désactivé et 10 messages d'historique, le corps envoyé ne contient que la question, et l'estimation du limiteur n'inclut pas l'historique. Un historique invalide (rôle `system`, par exemple) est tout de même refusé.
- Deux messages `user` consécutifs sont fusionnés chez les 5 fournisseurs.
- Un `chatbot_id` inactif ou différent du chatbot actif donne `wpia_configuration_error` sans appel.
- L'estimation du limiteur est calculée sur l'historique conservé : un historique tronqué la réduit.
- Aucun contenu de message ni de système n'apparaît dans les logs ni dans les erreurs.
- Les réglages `history_max_messages` et `history_token_budget` hors bornes sont ramenés aux bornes (comme les autres réglages numériques) ; `history_enabled` est activé par défaut.

### Tests

Dans `tests/run.php`, pour chaque fournisseur, comparer le corps JSON envoyé à un corps attendu : un seul message, historique, historique tronqué par nombre, historique tronqué par budget, historique désactivé, fusion de rôles. Tests du service avec un limiteur factice : estimation sur toutes les entrées, refus sans appel.

---

## Lot B : cache de prompt (après 006 lot B)

### Décisions

| Sujet | Décision |
|---|---|
| Anthropic | Quand `stable` dépasse 1 024 tokens estimés, `system` devient une liste de blocs. Le bloc `stable` porte `cache_control: {type: "ephemeral"}` et le bloc `variable` (s'il existe) vient **après**. Sous ce seuil, le corps ne change pas. Le seuil est une constante. Avant de coder, vérifier dans la documentation Anthropic le minimum réel selon le modèle (sous ce minimum, le marquage est ignoré sans erreur). |
| OpenAI et autres | Rien à ajouter : le cache d'OpenAI est automatique. `stable` est déjà placé en premier dans le message `system`. |
| Comptage pour le limiteur | `usage.input_tokens` doit contenir **tous** les tokens d'entrée traités. Chez Anthropic, `input_tokens` exclut le cache : on additionne `input_tokens`, `cache_creation_input_tokens` et `cache_read_input_tokens`. Chez OpenAI, `prompt_tokens` inclut déjà `cached_tokens` : on ne l'additionne pas. |
| Retour | Ajout de `usage.cache_read_tokens` quand le fournisseur le renvoie. |

### Tâches

6. **`AnthropicProvider`** : `system` en blocs avec `cache_control` au-delà du seuil.
7. **`ResponseNormalizer`** : lecture des tokens de cache selon les règles ci-dessus.
8. **Documentation** : `documentation/generation-ia.md`, raison de l'ordre des éléments (cache).

### Critères d'acceptation

- Avec `stable` au-dessus du seuil, `system` est une liste dont le bloc `stable` porte `cache_control` et dont le bloc `variable` le suit. Sous le seuil, le corps est inchangé.
- Anthropic : `input_tokens` 20 et `cache_read_input_tokens` 3 000 donnent `usage.input_tokens` = 3 020 et `cache_read_tokens` = 3 000.
- OpenAI : `prompt_tokens` 3 020 dont 3 000 en cache donnent `input_tokens` = 3 020 et `cache_read_tokens` = 3 000.
- Le total confirmé auprès du limiteur inclut les tokens lus dans le cache.

---

## Hors périmètre

Réponse en streaming, appels d'outils, résumé automatique de l'historique, déduction du fournisseur à partir du chatbot (colonne `type`), plusieurs chatbots publics.
