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
| [005-appliquer_limite.md](005-appliquer_limite.md) | Limites de consommation | #11 | `11-appliquer-les-limites-de-consommation` | **En cours** |
| [006-markdown.md](006-markdown.md) | Contexte documentaire envoyé à l'IA | à créer | — | À faire |
| [007-chat-publique.md](007-chat-publique.md) | Chat public (shortcode et REST) | à créer | — | À faire |
| [008-generation-multitours.md](008-generation-multitours.md) | Génération multi-tours (historique, contexte, chatbot) | à créer | — | Nouvelle, **prérequis** de 006, 007 et 009 |
| [009-cadrage-garde-fous.md](009-cadrage-garde-fous.md) | Cadrage du sujet, garde-fous, jeu d'évaluation | à créer | — | Nouvelle |
| [010-rgpd-desinstallation.md](010-rgpd-desinstallation.md) | RGPD, `uninstall.php`, clé de chiffrement | à créer | — | Nouvelle |
| [011-cache-reponses.md](011-cache-reponses.md) | Cache des réponses fréquentes | à créer | — | Nouvelle, priorité basse |

> Attention : les fichiers `010-…` et `011-…` **ne correspondent pas** aux issues GitHub #10 et #11. C'est une raison de plus pour nommer les futurs fichiers d'après le numéro GitHub, une fois l'issue créée.

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
