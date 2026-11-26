# Index des issues

> Créé lors de la revue du 2026-11-11. Les fichiers existants ne sont ni renommés ni supprimés ; chacun contient à la fin une section « Revue » avec une critique et une issue proposée.

## Correspondance

Les noms de fichiers, les titres internes et les numéros GitHub ne suivent pas la même numérotation. Ce tableau fait foi. La colonne GitHub est à compléter ou vérifier : le serveur GitHub n'était pas joignable pendant la revue.

| Fichier | Sujet | GitHub | Branche | Statut |
|---|---|---|---|---|
| [0001-branche-initiale.md](0001-branche-initiale.md) | Architecture, tables, entités | #1 | `1-branche-initiale` | Livrée (PR #4) |
| [0002-interface-admin.md](0002-interface-admin.md) | Interface d'administration | #5 | `5-mis-en-place-de-linterface-admin-du-plugin` | Livrée (PR #6, #7) |
| [0003-service-ia.md](0003-service-ia.md) | Service de génération IA | #8 | `8-ajouter-la-génération-ia-via-les-fournisseurs-configurés` | Livrée |
| [0004-prompt-initiale.md](0004-prompt-initiale.md) | **Persistance des conversations** (malgré le nom) | #10 | `10-persister-et-protéger-les-conversations` | **À faire**, facultative pour le MVP |
| [005-appliquer_limite.md](005-appliquer_limite.md) | Limites de consommation | #11 | `11-appliquer-les-limites-de-consommation` | Livrée (PR #16) |
| [006-markdown.md](006-markdown.md) | Assemblage de la requête IA : prompts, Markdown de contexte et Markdown complet. **Lot A** : indexation des documents ; **lot B** : assemblage | à créer | `9-exploiter-les-documents-markdown-comme-contexte` | **Lot A livré** (2026-11-20) : `MarkdownSectionizer`, `KeywordNormalizer`, `DocumentIndexer`, table `wpia_document_section` (schéma 1.3.0). **Lot B livré** (2026-11-23) : `PromptAssembler`, `ContextBuilder`, rôle contexte/complet (schéma 1.4.0), branché dans `generateChat()` |
| [007-chat-publique.md](007-chat-publique.md) | Chat public (shortcode et REST) | #12 | `12-fournir-linterface-de-chat-publique-wordpress` | **En cours** (2026-11-24). Plus aucune dépendance bloquante (005, 006, 008 et 009 livrées côté code). **Livré** : `MarkdownRenderer` (`src/Service/`, pur, échappe tout, liens `http`/`https`/`mailto`, 3 tests). **Routes livrées** : `PublicChatHandler` (testé) et `PublicChatController` (non testé, à vérifier dans WordPress), réglage `max_message_length`. Shortcode `[wpia_chat]`, `chat.js` et `chat.css` livrés (non testés au navigateur). Documentation `documentation/chat-public.md` livrée. **Reste** : exécuter la [liste de contrôle manuelle](007-verification-manuelle.md) dans WordPress |
| [008-generation-multitours.md](008-generation-multitours.md) | Génération multi-tours (historique, prompt système, cache de prompt) | à créer | — | **Livrée** (2026-11-23, lots A et B) : `generateChat()`, historique configurable (option « Conserver l'historique »), limiteur sur toutes les entrées, cache Anthropic et comptage des tokens en cache. Le cache ne servira vraiment qu'avec le contexte volumineux de 006 lot B |
| [009-cadrage-garde-fous.md](009-cadrage-garde-fous.md) et [009-cadrage-garde-fous-2.md](009-cadrage-garde-fous-2.md) | Cadrage du sujet, garde-fous, jeu d'évaluation (le fichier `-2` liste le reste à faire après la partie 1) | à créer | — | **Livrée côté code** (lot B livré le 2026-11-24 ; reste l'exécution réelle du jeu d'évaluation, 18/20, et la vérification manuelle dans WordPress). **Partie 1 livrée** : `TokenEstimator`, `CadrageValidator`, `PromptBuilder` (classes pures, non branchées). **Lot A, tâches 1 et 2 livrées** (2026-11-24) : migration 1.5.0 (7 colonnes de cadrage, marqueurs témoins, `ChatbotRepository`) et réglage `prompt_admin_max_tokens`. **Tâche 3 livrée** : 3a (enregistrement : `CadrageSanitizer`, refus par champ) et 3b (formulaire, aperçu, avertissements, compteur JS), à vérifier manuellement dans WordPress. **Lot A terminé** (documentation `cadrage.md` livrée). **Lot B livré** (contrôle de sortie `OutputGuard`, jeu d'évaluation) |
| [010-rgpd-desinstallation.md](010-rgpd-desinstallation.md) | RGPD, `uninstall.php`, clé de chiffrement | à créer | — | **À faire** (aucun `uninstall.php`) |
| [011-cache-reponses.md](011-cache-reponses.md) | Cache des réponses fréquentes | à créer | — | **À faire**, priorité basse (après le MVP) |
| [012-fournisseurs-defauts-bloquants.md](012-fournisseurs-defauts-bloquants.md) | Mistral Small 4 (morceaux `thinking`, `reasoning_effort`) et modèle Anthropic par défaut | à créer | — | **À faire**, priorité haute (créée le 2026-11-27 après la comparaison doc API / fournisseurs) |
| [013-openai-modeles-raisonnement.md](013-openai-modeles-raisonnement.md) | OpenAI : plus de `temperature`, `max_completion_tokens`, `reasoning_effort` | à créer | — | **À faire**, priorité moyenne |
| [014-ollama-contexte.md](014-ollama-contexte.md) | Ollama : `num_ctx` et `keep_alive` | à créer | — | **À faire**, priorité moyenne |
| [015-normaliseur-arret-et-erreurs.md](015-normaliseur-arret-et-erreurs.md) | Normaliseur : réponses tronquées, refus, 429/529, codes d'erreur journalisés | à créer | — | **À faire**, priorité moyenne, après 012 (même fichier) |

> Attention : les fichiers `010-…` et `011-…` **ne correspondent pas** aux issues GitHub #10 et #11. C'est une raison de plus pour nommer les futurs fichiers d'après le numéro GitHub, une fois l'issue créée.

## Avancement au 2026-11-24

| Livré | Reste à faire |
|---|---|
| 0001, 0002, 0003, 005, 008, 006 (lots A et B), 009 (partie 1, lot A, lot B), `MarkdownRenderer` (007, tâche 6) | 009 : exécution réelle du jeu d'évaluation (18/20) et vérification manuelle dans WordPress ; 007 (MVP, en cours) ; 010 ; après le MVP : 0004 et 011 |

Prochaine étape recommandée : **009 : lancer le jeu d'évaluation avec un vrai modèle** (`tests/evaluation/README.md`) et vérifier à la main l'admin dans WordPress ; le code du lot B est livré, puis **009 lot B**.

## Ordre recommandé (MVP d'abord)

```
#11 Limites (005) ─┐
                   ├─► 008 Multi-tours ─┬─► 009 Cadrage ─┐
                   │                    └─► 006 Contexte ┴─► 007 Chat public  ══ MVP
                   │
                   └─────────────────────────────────────────► 010 RGPD / désinstallation
#10 Persistance (0004), 011 Cache ─────────────────────────────► après le MVP
```

Le MVP, c'est un visiteur qui discute du CV de l'admin, dans le sujet, avec une facture plafonnée. Il ne nécessite **pas** de stocker les conversations.

## Gabarit pour les prochaines issues

```markdown
# #<n° GitHub> – <titre>

**Branche :** <n>-<slug> · **Dépend de :** #x, #y

## Contexte
État actuel du code, avec les fichiers et classes cités.

## Objectif
Une phrase, du point de vue de l'admin ou du visiteur.

## Décisions
Tableau sujet → décision. Aucune question ouverte : sinon, l'issue n'est pas prête.

## Tâches
Liste numérotée, une classe ou un fichier par tâche si possible.

## Hors périmètre

## Critères d'acceptation
Vérifiables : « avec telle entrée → tel résultat observable ».

## Tests
```

## Incohérences à corriger dans `claude.md` (fichier protégé : à faire par Yohanan)

- **Stack** : « Vue.js pour l'administration ». L'admin réelle est en PHP, avec l'API Settings et du JavaScript simple (`assets/js/admin.js`).
- **Constante de clé** : `MON_PLUGIN_ENCRYPTION_KEY`, à remplacer par `WPIA_ENCRYPTION_KEY` (voir 010).
- **Format chiffré** : `v1:` + base64, alors que le code utilise `wpia:v1:`.
- **Contexte HKDF** : `'mon-plugin-encryption'`, alors que le code utilise `'wpia-api-secret-v1'`.
