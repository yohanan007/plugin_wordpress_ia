# 014 – Ollama : taille de contexte et maintien du modèle en mémoire

**Branche :** à créer · **Dépend de :** 0003, 006 · **Priorité :** moyenne

> Issue issue de la comparaison entre `documentation/api/*.md` et les classes `src/Service/Ia/Fournisseur/` (2026-11-27).

## Contexte

[OllamaProvider.php](../../src/Service/Ia/Fournisseur/OllamaProvider.php) envoie `model`, `messages`, `stream: false` et `options.temperature` / `options.num_predict`. Ces champs sont conformes à [ollama-api-guide.md](../api/ollama-api-guide.md).

Deux paramètres de la doc manquent :

- **`options.num_ctx`**. La doc souligne qu'il est important pour un chatbot avec une base de connaissances : si le contexte est trop petit pour le prompt système (règles, cadrage, documents Markdown de 006) et l'historique, Ollama **coupe le début du prompt sans renvoyer d'erreur**. Le début du prompt, ce sont justement les règles et les garde-fous de 009. Le contexte par défaut d'Ollama (quelques milliers de tokens) est vite dépassé par le mode « Markdown complet » de 006.
- **`keep_alive`**. Sans lui, Ollama décharge le modèle après 5 minutes d'inactivité, et la question suivante subit le temps de chargement.

Le cloud Ollama (`https://ollama.com/api`, `Bearer`) n'est pas concerné : `AiGenerationService::isValidProviderUrl()` n'accepte que `http://localhost`, `127.0.0.1` ou `::1` pour Ollama ([AiGenerationService.php:420-423](../../src/Service/Ia/AiGenerationService.php)).

## Objectif

Avec Ollama, les règles et les documents du chatbot arrivent entiers au modèle, et l'enchaînement des questions reste fluide.

## Décisions

| Sujet | Décision |
|---|---|
| `num_ctx` | Valeur **fixe** : constante `OllamaProvider::NUM_CTX = 8192`. On ne la calcule pas requête par requête : **Ollama recharge le modèle quand `num_ctx` change**, ce qui ajouterait plusieurs secondes à chaque variation. |
| Dépassement de 8192 | Pas de troncature côté plugin. Le budget de prompt de 009 (`prompt_admin_max_tokens`) et la sélection de sections de 006 limitent déjà la taille. Le relevé `prompt` (journal `info`) donne les tokens par bloc, ce qui permet de diagnostiquer un dépassement. |
| Réglage | Filtre `wpia_ollama_num_ctx` (entier, borné entre 2048 et 131072) pour les admins qui ont plus de mémoire. Pas de champ dans l'admin. |
| `keep_alive` | `'30m'`, comme le conseille la doc. Pas de réglage. |
| Cloud Ollama | Hors périmètre (voir le contexte). L'en-tête `Authorization` n'est pas ajouté. |

## Tâches

1. `OllamaProvider` : constante `NUM_CTX`, `options.num_ctx` (après le filtre, borné), `keep_alive` au premier niveau du corps.
2. `documentation/generation-ia.md` : section Ollama (taille de contexte, mémoire nécessaire, filtre `wpia_ollama_num_ctx`).

## Hors périmètre

- Ollama cloud ou distant (hors localhost).
- Ajuster `num_ctx` automatiquement à la taille du prompt.
- Vérifier au démarrage que le modèle est présent (`GET /api/tags`) : le test de connexion de l'admin le fait déjà.

## Critères d'acceptation

- Le corps envoyé à `/api/chat` contient `"keep_alive":"30m"` et `"options":{"temperature":…,"num_predict":…,"num_ctx":8192}`.
- Avec le filtre `wpia_ollama_num_ctx` qui renvoie `16384` → `num_ctx` vaut 16384. Avec `100` → 2048. Avec `"abc"` → 8192.
- Les autres fournisseurs n'envoient ni `num_ctx` ni `keep_alive`.

## Tests

Dans `tests/run.php` : compléter le test « ollama request and normalized response » et ajouter un test du filtre (le bouchon `apply_filters` existe déjà).
