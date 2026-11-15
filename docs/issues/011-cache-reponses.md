# Cache des réponses aux questions fréquentes

> Nouvelle issue créée lors de la revue du 2026-11-11. Elle porte une exigence de `claude.md` (« réponses et résultats de recherche mis en cache… invalidé quand un document change ») qu'aucune issue ne couvrait.
> **Correspondance GitHub :** à créer. **Priorité :** basse, après le MVP.

## Pourquoi cette issue

Sur un chat de CV, une grande partie des échanges commence par les **questions suggérées** (009) ou des questions très proches. Chaque fois, le plugin paie un appel complet. Mettre en cache les **premières questions** d'une conversation réduit la facture sans dégrader les réponses.

**Dépend de :** [005](005-appliquer_limite.md), [006](006-markdown.md), [008](008-generation-multitours.md), [009](009-cadrage-garde-fous.md)

## Décisions

| Sujet | Décision |
|---|---|
| Quoi | Uniquement les requêtes **sans historique** : une réponse qui dépend de l'historique n'est pas réutilisable. |
| Clé | `wpia_rep_` + `sha256( chatbot_id \| version du contexte (006) \| version du cadrage (009) \| modèle \| question normalisée )`. La normalisation passe en minuscules, retire les accents, la ponctuation et les espaces multiples. |
| Stockage | Transients (donc l'object cache s'il est présent). Durée réglable (`response_cache_ttl`, **24 h** par défaut, 0 pour désactiver). La réponse en cache est le HTML **déjà nettoyé** par `wp_kses` (007). |
| Invalidation | Elle découle de la clé : un changement de document, de cadrage ou de modèle change la clé. Un bouton « Vider le cache » est ajouté dans l'admin. |
| Limites | Un succès du cache compte **1 message** pour le visiteur (anti-abus), mais **0 token** pour le budget site. |
| Confidentialité | La question n'est stockée que sous forme de hash. La réponse ne provient que des documents publics du chatbot. |

## Tâches

1. `ResponseCache` (`src/Service/`) : `lire()`, `ecrire()`, `vider( int $chatbot_id )`.
2. Branchement dans l'endpoint du chat (007), avant la réservation de tokens (005).
3. Réglage `response_cache_ttl`, bouton « Vider le cache » et compteur de succès et d'échecs du cache au tableau de bord.
4. Documentation dans `documentation/limites.md`.

## Critères d'acceptation

- Deux visiteurs posent « Quelles sont ses compétences ? » puis « quelles sont ses compétences » : 1 seul appel au fournisseur.
- La même question en 2e position d'une conversation (avec historique) appelle le fournisseur.
- Après la réindexation d'un document, la même question appelle de nouveau le fournisseur.
- Un succès du cache n'augmente pas le compteur de tokens du site.
- Avec `response_cache_ttl` = 0, aucun transient `wpia_rep_*` n'est créé.
