**Contexte :** les documents sont stockés et associés au chatbot, mais ne sont pas transmis à l’IA.
**Objectif :** sélectionner et inclure un contexte documentaire utile dans les requêtes de génération.
- Lire les fichiers via `DocumentStorage` après déchiffrement côté serveur uniquement.
- Découper les textes en segments bornés et conserver les références au document source.
- Sélectionner les segments selon la question, l’ordre existant et un budget de tokens configurable.
- Définir les états de traitement, les erreurs et le comportement quand aucun contexte n’est pertinent.
- Traiter le contenu documentaire comme une donnée non fiable, jamais comme une instruction système.
**Dépendances :** issue 1 pour l’intégration à la génération IA.
**Acceptation :** tests prouvant que le contexte sélectionné respecte le budget et reste traçable.