**Contexte :** les seuils sont configurables, mais ne bloquent ni ne réduisent les requêtes réelles.
**Objectif :** appliquer les limites avant chaque génération et comptabiliser l’usage après celle-ci.
- Contrôler les tokens par requête, par conversation, par jour et les messages quotidiens.
- Identifier les utilisateurs et visiteurs anonymes sans dépendre uniquement d’une IP brute.
- Mettre à jour les compteurs de manière atomique afin d’éviter les dépassements concurrents.
- Utiliser les métriques fournisseur quand disponibles et documenter l’estimation de secours.
- Retourner un refus explicite sans envoyer de requête IA lorsque le seuil est atteint.
**Dépendances :** issues 1 et 3 ; s’appuyer sur `AdminSettings` et `LimiteUtilisationRepository`.
**Acceptation :** tests des seuils, réinitialisations journalières, appels concurrents et refus explicites.