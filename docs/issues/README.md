# Index des issues

> Créé lors de la revue du 2026-11-11. Les fichiers existants ne sont ni renommés ni supprimés ; chacun contient à la fin une section « Revue » avec une critique et une issue proposée.

## Correspondance

Les noms de fichiers, les titres internes et les numéros GitHub ne suivent pas la même numérotation. Ce tableau fait foi. La colonne GitHub est à compléter ou vérifier : le serveur GitHub n'était pas joignable pendant la revue.

| Fichier | Sujet | GitHub | Branche | Statut |
|---|---|---|---|---|
| [0001-branche-initiale.md](0001-branche-initiale.md) | Architecture, tables, entités | #1 | `1-branche-initiale` | Livrée (PR #4) |
| [0002-interface-admin.md](0002-interface-admin.md) | Interface d'administration | #5 | `5-mis-en-place-de-linterface-admin-du-plugin` | Livrée (PR #6, #7) |
| [0003-service-ia.md](0003-service-ia.md) | Service de génération IA | #8 | `8-ajouter-la-génération-ia-via-les-fournisseurs-configurés` | Livrée |
| [0004-prompt-initiale.md](0004-prompt-initiale.md) | **Persistance des conversations** (malgré le nom) | #10 | `10-persister-et-protéger-les-conversations` | À faire, facultative pour le MVP |
| [005-appliquer_limite.md](005-appliquer_limite.md) | Limites de consommation | #11 | `11-appliquer-les-limites-de-consommation` | Livrée (PR #16) |
| [006-markdown.md](006-markdown.md) | Assemblage de la requête IA : prompts, Markdown de contexte et Markdown complet. **Lot A** : indexation des documents ; **lot B** : assemblage | à créer | `9-exploiter-les-documents-markdown-comme-contexte` | **En cours** : **lot A fait le 2026-11-20** (indexation : `MarkdownSectionizer`, `KeywordNormalizer`, `DocumentIndexer`, table `wpia_document_section`) ; lot B après 008 et 009 lot A |
| [007-chat-publique.md](007-chat-publique.md) | Chat public (shortcode et REST) | à créer | — | À faire |
| [008-generation-multitours.md](008-generation-multitours.md) | Génération multi-tours (historique, prompt système `{stable, variable}`, cache, chatbot) | à créer | — | Nouvelle, **prérequis** de 006 (lot B), 007 et 009 (lot B) |
| [009-cadrage-garde-fous.md](009-cadrage-garde-fous.md) | Cadrage du sujet, garde-fous (blocs [1] et [2] du prompt), consignes de l'admin, jeu d'évaluation. **Lot A** : cadrage et `PromptBuilder` ; **lot B** : contrôle de sortie et évaluation | à créer | — | **Partie 1 faite le 2026-11-18** (`TokenEstimator`, `CadrageValidator`, `PromptBuilder`) ; suite dans 009-2 |
| [009-cadrage-garde-fous-2.md](009-cadrage-garde-fous-2.md) | Partie 2 de 009 : migration, section d'administration, documentation (**lot A**) ; branchement, contrôle de sortie et évaluation (**lot B**) | à créer | — | Créée le 2026-11-18 ; lot A prêt, lot B après 008 et 006 lot B |
| [010-rgpd-desinstallation.md](010-rgpd-desinstallation.md) | RGPD, `uninstall.php`, clé de chiffrement | à créer | — | Nouvelle |
| [011-cache-reponses.md](011-cache-reponses.md) | Cache des réponses fréquentes | à créer | — | Nouvelle, priorité basse |

> Attention : les fichiers `010-…` et `011-…` **ne correspondent pas** aux issues GitHub #10 et #11. C'est une raison de plus pour nommer les futurs fichiers d'après le numéro GitHub, une fois l'issue créée.

## Ordre recommandé (MVP d'abord)

```
006 lot A Indexation (aucune dépendance) ──────┐
009 partie 1 faite ; 009-2 lot A Migration, admin (aucune) ─┤
                                               │
#11 Limites (005) ─┐                           │
                   ├─► 008 Multi-tours ────────┴─► 006 lot B Assemblage ─► 009-2 lot B Contrôle de sortie ─► 007 Chat public  ══ MVP
                   │                                                         et évaluation
                   │
                   └─────────────────────────────────────────► 010 RGPD / désinstallation
#10 Persistance (0004), 011 Cache ───────────────────────────► après le MVP
```

Le MVP, c'est un visiteur qui discute du CV de l'admin, dans le sujet, avec une facture plafonnée. Il ne nécessite **pas** de stocker les conversations.

### Répartition du prompt système (révision du 2026-11-15)

| Bloc | Contenu | Produit par |
|---|---|---|
| [1] | Prompt de base : règles fixes et cadrage du chatbot | 009 (`PromptBuilder`) |
| [2] | Consignes de l'admin (`wpia_chatbot.system_prompt`) | 009 (`PromptBuilder`) |
| [3] | `<contexte>` : documents de rôle « contexte », toujours en entier | 006 (`ContextBuilder`) |
| [4] | `<documents>` : documents de rôle « complet », en entier ou par sections | 006 (`ContextBuilder`) |

006 assemble les quatre blocs (`PromptAssembler`) et remet `systeme = {stable, variable}` à 008, qui le transmet à chaque fournisseur et pose le point de cache.

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
- **Sélection des sections** : « Pour chaque question : sélectionner uniquement les N sections les plus pertinentes ». La révision de 006 ajoute un **mode complet** : tous les documents sont envoyés quand ils tiennent dans le budget (cas nominal du CV). La sélection n'est utilisée qu'au-delà. À reformuler avant de livrer le lot B de 006.
