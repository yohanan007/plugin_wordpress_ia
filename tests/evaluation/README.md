# Jeu d'évaluation du cadrage

Vérifie qu'un chatbot cadré se comporte comme prévu avec un vrai modèle : réponses
dans le sujet, refus hors sujet, aucune fuite du prompt. **Il se lance à la main,
jamais en CI** : il fait 20 appels réels au fournisseur (il coûte quelques centimes).

À relancer après toute modification du cadrage, des règles de `PromptBuilder` ou du
modèle utilisé.

## Lancement

```sh
WPIA_EVAL_PROVIDER=anthropic \
WPIA_EVAL_MODEL=<modèle> \
WPIA_EVAL_API_KEY=<clé> \
php tests/evaluation/run.php
```

| Variable | Rôle |
|---|---|
| `WPIA_EVAL_PROVIDER` | `openai`, `mistral`, `anthropic`, `ollama` ou `custom` (obligatoire) |
| `WPIA_EVAL_API_KEY` | Clé API, lue uniquement dans l'environnement et jamais affichée (inutile pour `ollama`) |
| `WPIA_EVAL_MODEL` | Modèle (par défaut : celui du catalogue) |
| `WPIA_EVAL_URL` | URL de base de l'API (par défaut : celle du catalogue) |
| `WPIA_EVAL_CV` | Chemin d'un autre CV Markdown (par défaut : `cv-exemple.md`) |

Aucune base ni installation WordPress n'est nécessaire. Le script écrit `rapport.md`
(ignoré par git : joignez-le à la PR) et sort avec le code 0 si au moins
**18 réponses sur 20** sont conformes, 1 sinon, 2 si la configuration est incomplète.

## Contenu

- `questions.md` : les 20 questions, leur comportement attendu et le contrôle appliqué.
- `chatbot-exemple.php` et `cv-exemple.md` : chatbot et CV fictifs (Marie Durand).
- `Evaluateur.php` : lecture des questions et contrôle d'une réponse, testés sans réseau
  dans `tests/run.php`.
- `run.php` : construit le prompt avec les classes du plugin, force la température à 0,
  compare la réponse **brute** (avant remplacement par `OutputGuard`) à l'attendu.

## Limites

- Le contrôle est automatique et heuristique (mots attendus, formulations d'ignorance) :
  relisez les réponses des échecs dans le rapport avant de conclure à une régression.
- Avec une température à 0, les réponses sont stables mais pas strictement identiques
  d'un appel à l'autre chez tous les fournisseurs.
- Le prompt est construit sans base de données : le CV est envoyé en entier, comme en
  mode « documents complets ». Le mode « sélection de sections » n'est pas évalué.
