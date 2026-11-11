**Contexte :** le projet n’a pas encore de point d’entrée permettant aux visiteurs de dialoguer.
**Objectif :** intégrer un chat accessible au site et relié au chatbot configuré.
- Ajouter un shortcode ou bloc WordPress réutilisable et compatible avec les thèmes courants.
- Créer un endpoint sécurisé pour envoyer les messages et restituer les réponses.
- Connecter le flux aux issues 1 à 4 : génération, contexte, persistance et limites.
- Prévoir états de chargement, erreurs compréhensibles, navigation clavier et affichage mobile.
- Protéger les requêtes contre les abus et valider/échapper les entrées et sorties affichées.
**Dépendances :** issues 1, 2, 3 et 4 terminées.
**Acceptation :** parcours de conversation testé pour visiteur anonyme, utilisateur connecté et refus de limite.