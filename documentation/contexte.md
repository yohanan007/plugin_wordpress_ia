# Documents et contexte envoyés à l'IA

Ce document explique comment les documents Markdown de l'admin arrivent dans le
prompt de l'IA. La spécification complète est dans `docs/issues/006-markdown.md`.

## Les trois parties d'une requête

| Partie | Où l'écrire | Envoyé |
|---|---|---|
| Règles fixes et cadrage (sujet, ton, langue) | Écrites par le plugin ; sujet, ton et langue se règlent dans la page du chatbot | Toujours |
| Consignes de l'admin | Champ « Consignes complémentaires » du chatbot | Toujours (peut être vide) |
| **Contexte** | Un document au rôle **Contexte** | **Toujours en entier**, jamais tronqué |
| **Documents complets** | Un ou plusieurs documents au rôle **Complet** | En entier si le budget le permet, sinon par sections |

Le contenu des documents est traité comme une **donnée** : il est placé dans des
blocs `<contexte>` et `<documents>`, échappé, et une règle fixe précise que ces
blocs ne contiennent jamais d'instructions.

### Consignes ou contexte ?

- **Consignes** : *comment* répondre (« mets en avant mes projets WordPress »).
- **Contexte** : *de qui et de quoi* on parle (« Marie Durand, développeuse web
  freelance, pour les recruteurs »).
- **Document complet** : le détail (le CV entier, le parcours, les projets).

Le rôle est porté par l'**association** document / chatbot : un même fichier peut
être le contexte d'un chatbot et un document complet d'un autre. Il se choisit dans
la page « Documents » (colonne « Rôle », ou à l'association).

## Les deux modes

Budget des documents : réglage `context_token_budget` (6 000 tokens par défaut).

1. Le **contexte** est envoyé en entier. Il consomme une part du budget.
2. Budget restant = budget − contexte (jamais négatif).
3. **Mode complet** : si tous les documents complets tiennent dans le budget
   restant, toutes leurs sections sont envoyées, dans l'ordre des documents puis
   des sections. C'est le cas d'un CV (1 000 à 8 000 tokens).
4. **Mode sélection** : sinon, chaque section reçoit un score (termes de la question
   présents dans la section, titre compté double). Les meilleures sections sont
   prises jusqu'au budget, puis remises dans l'ordre du document. Une section de
   score nul n'est jamais envoyée : si rien ne correspond, seul le contexte part.
   C'est pourquoi le contexte doit présenter l'essentiel (« Parle-moi de lui »).

La page « Documents » affiche une jauge (contexte, documents complets transmis,
place restante) et le mode prévu.

## Limite du contexte

Réglage `context_fixed_max_tokens` (1 500 par défaut).

- **Refus** quand l'admin provoque le dépassement (association ou passage d'un
  document en rôle Contexte) : « Le contexte doit rester court ; placez le détail
  dans un document complet ».
- **Avertissement** quand le dépassement vient d'une réindexation ou d'une limite
  abaissée : le contexte est quand même envoyé **en entier**, et il réduit d'autant
  la place des documents complets.
- Un document non indexé ne peut pas devenir contexte (sa taille est inconnue).

## Format recommandé d'un document

- Des **titres explicites** (`#`, `##`, `###`) : le découpage se fait dessus, et le
  titre sert à la sélection.
- **Une section par thème** (« Expériences », « Formation », « Compétences »).
- Des sections courtes (moins de 800 tokens, réglage `context_section_max_tokens`) ;
  les plus longues sont recoupées par paragraphes.
- Pas de tableau géant : un tableau est un seul bloc, difficile à découper.
- Écrire les termes tels que le visiteur les cherchera (« PHP », « WordPress »).

### Modèle de document de contexte

```markdown
# Marie Durand

Développeuse web freelance (PHP, WordPress), 8 ans d'expérience, basée à Lyon.
Ce site présente son parcours aux recruteurs et aux clients potentiels.
Disponible pour des missions de 3 à 6 mois.
```

## Indexation et coût

- Le découpage, le chiffrement et les mots-clés se calculent **une seule fois**, à
  l'import et au bouton « Réindexer ». Poser une question ne lit jamais le fichier
  sur le disque ; seules les sections envoyées sont déchiffrées.
- Les mots-clés sont des empreintes HMAC : aucun terme du document n'est stocké en
  clair. Si la normalisation change (`KeywordNormalizer::VERSION`), tous les
  documents repassent en « En attente » pour être réindexés.
- Après un changement de réglage de taille de section, réindexer les documents.

## Classes

- `ContextBuilder` : blocs [3] et [4] ; ne lit que `wpia_document_section`.
- `PromptAssembler` : assemble [1] à [4] et renvoie `{systeme:{stable,variable}, releve}`.
  Mode complet : tout est dans `stable`. Mode sélection : les documents sont dans
  `variable`, `stable` reste identique d'une question à l'autre (cache de prompt).
- `ContextVersion` : empreinte de tout ce qui change le contexte (clé du cache 011),
  calculée à la demande.
- `ContextBudget` : totaux, contrôle de la limite du contexte et données de la jauge.
- Chaque question journalise un relevé sans contenu : mode, version, tokens par
  bloc, identifiants de sections.
