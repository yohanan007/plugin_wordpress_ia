
Issue #8 - Service de génération IA réutilisable
Contexte

Le système actuel permet de configurer un fournisseur IA et de tester la connexion à celui-ci. Cependant, aucun mécanisme ne permet encore de générer une réponse à partir d'un prompt.

L'objectif de cette évolution est de créer une couche de génération IA réutilisable qui s'appuie sur la configuration existante, afin que les futures fonctionnalités du plugin puissent utiliser une API unifiée indépendamment du fournisseur sélectionné.

Objectifs fonctionnels

Mettre en place un service central de génération IA capable :

d'utiliser la configuration déjà enregistrée dans l'administration ;
de sélectionner automatiquement le fournisseur configuré ;
d'envoyer un prompt et récupérer une réponse normalisée ;
d'uniformiser la gestion des erreurs ;
de garantir la sécurité des secrets ;
d'être facilement réutilisable dans les futures fonctionnalités du plugin.
Fournisseurs à supporter

Créer un service dédié par fournisseur dans :

src/Service/Fournisseur/


Fournisseurs à implémenter :

OpenAI
Mistral
Anthropic
Ollama
CustomProvider


Chaque fournisseur doit encapsuler :

construction de la requête HTTP ;
authentification ;
appel API ;
extraction de la réponse ;
remontée des erreurs.
Architecture attendue

Respecter l'architecture existante du projet.

Ne pas introduire de sur-ingénierie ni de découpage excessif.

Objectifs :

code court ;
lisible ;
maintenable ;
réutilisable.

Structure cible :

src/
└── Service/
    ├── AiGenerationService.php
    ├── ResponseNormalizer.php
    └── Fournisseur/
        ├── OpenAiProvider.php
        ├── MistralProvider.php
        ├── AnthropicProvider.php
        ├── OllamaProvider.php
        └── CustomProvider.php

Service central

Créer :

AiGenerationService


Responsabilités :

charger la configuration active ;
sélectionner le fournisseur correspondant ;
déchiffrer la clé API au moment de l'appel ;
transmettre les paramètres de génération ;
récupérer la réponse fournisseur ;
déléguer la normalisation ;
retourner un résultat standardisé.

Toutes les futures fonctionnalités devront passer par ce service.

Service de normalisation

Créer :

ResponseNormalizer


Responsabilités :

Validation des réponses

Vérifier notamment :

réponse vide ;
contenu absent ;
structure invalide ;
contenu non textuel ;
dépassement éventuel des limites attendues.
Normalisation des erreurs

Transformer les erreurs spécifiques des fournisseurs en erreurs cohérentes pour le plugin :

Exemples :

Erreur d'authentification
Erreur réseau
Timeout
Réponse invalide
Erreur fournisseur
Erreur de configuration


Le message retourné à l'utilisateur ne doit jamais exposer :

clé API ;
token ;
endpoint complet ;
contenu sensible.
Gestion des secrets

Utiliser le composant existant :

SecretCipher


Contraintes :

la clé API reste chiffrée en stockage ;
déchiffrement uniquement juste avant l'appel API ;
jamais journaliser la clé ;
jamais exposer la clé dans les exceptions ;
jamais exposer la clé dans les messages utilisateur.
Paramètres de génération

Respecter systématiquement la configuration existante :

Modèle
Température
Nombre maximal de tokens
Timeout


Les valeurs configurées dans l'administration doivent être transmises au fournisseur lorsque celui-ci le permet.

S'appuyer sur la configuration actuelle gérée par :

AdminController

Journalisation

Autorisée uniquement pour :

informations techniques utiles ;
erreurs expurgées ;
diagnostics de développement.

Interdictions :

clés API ;
prompts complets contenant des données sensibles ;
réponses brutes pouvant contenir des secrets.

Exemple acceptable :

OpenAI request failed: timeout


Exemple interdit :

OpenAI request failed with key sk-xxxx

Contraintes techniques
Environnement de développement
Windows 11 Pro
VS Code
WSL Ubuntu
PHP 8.0+
Plugin WordPress

Contraintes de réalisation
Respecter l'architecture actuelle du projet.
Ne pas introduire de framework supplémentaire.
Limiter le nombre de classes au strict nécessaire.
Éviter les couches d'abstraction inutiles.
Favoriser la simplicité et la lisibilité.
Mutualiser le maximum de logique commune.
Conserver un code facilement testable.
Critères d'acceptation
Génération
Génération valide avec OpenAI.
Génération valide avec Mistral.
Génération valide avec Anthropic.
Génération valide avec Ollama.
Génération valide avec fournisseur personnalisé.
Gestion des erreurs
Clé API absente.
Clé API invalide.
Timeout fournisseur.
Erreur réseau.
Réponse JSON invalide.
Réponse vide.
Fournisseur indisponible.
Sécurité
Aucune clé API journalisée.
Aucun secret exposé à l'utilisateur.
Déchiffrement uniquement lors de l'appel.
Validation
Respect du modèle configuré.
Respect de la température configurée.
Respect du timeout configuré.
Respect de la limite de tokens configurée.
Tests

Ajouter des tests couvrant :

Succès
Timeout
Erreur réseau
Authentification
Absence de clé
Réponse invalide
Réponse vide
Limite de sortie

Documentation demandée

Documenter dans le dépôt :

Documentation développeur
architecture retenue ;
responsabilité de chaque service ;
procédure pour ajouter un nouveau fournisseur ;
exemples d'utilisation du service central.
Exemple
$response = $aiGenerationService->generate(
    'Rédige un résumé du texte suivant...'
);

Points à documenter
flux complet de génération ;
gestion des erreurs ;
gestion des secrets ;
convention de développement pour les futurs fournisseurs.

Cette version est plus précise pour un agent IA de développement tout en restant compatible avec une architecture WordPress/PHP 8 simple et sans surdécoupage.

---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Statut :** livrée (GitHub #8, commit `ac94b4f`). C'est l'issue la plus solide du lot : responsabilités claires, règles sur les secrets précises, liste des erreurs à couvrir.

- **Mise en forme perdue**, comme pour 0002.
- **Reste de rédaction** : la dernière phrase (« Cette version est plus précise pour un agent IA… ») est un commentaire de l'outil qui a rédigé l'issue, pas une exigence.
- **Limite de conception, la plus importante** : le service livré expose `generate( string $prompt )`.
  - Il ne fait **qu'un seul échange** : pas d'historique de conversation, donc pas de vrai chat.
  - Le **contexte documentaire** n'a pas de place à lui. Il faudrait le concaténer au prompt utilisateur, ce qui mélange données et instructions, à l'inverse de ce que demande `claude.md` (« sécurité de l'IA »).
  - Il charge **un chatbot unique** depuis la configuration. Un shortcode `[wpia_chat id="2"]` ne pourra pas choisir son chatbot.
  - Il ne permet pas aux limites (005) de **réserver** des tokens avant l'appel, puis de les corriger après.
  
  **Aucune issue ne prévoit cette évolution**, alors que 005, 006 et 007 en dépendent. Elle est l'objet de la nouvelle issue [008](008-generation-multitours.md).
- **Critère coûteux** : « Génération valide avec OpenAI, Mistral, Anthropic, Ollama et fournisseur personnalisé » suppose 4 comptes payants et des tests manuels répétés. Mieux vaut : des tests unitaires avec réponses HTTP simulées pour les 5 fournisseurs, plus un test réel sur 1 ou 2 fournisseurs.
- **Environnement de développement** (Windows 11, VS Code) : ce n'est pas une contrainte technique de l'issue, cela relève de `claude.md`.

## Issue proposée (reliquat)

**Titre :** #8 – Clôture du service de génération IA
**Dépend de :** aucune · **Suite :** [008 – Génération multi-tours](008-generation-multitours.md)

### Tâches

- [ ] Remettre en forme le texte d'origine en Markdown et retirer la phrase finale parasite.
- [ ] Vérifier que `tests/run.php` couvre, pour **chaque** fournisseur, une réponse simulée de succès, une erreur 401, un timeout (`WP_Error`), un JSON invalide et une réponse vide.
- [ ] Documenter dans `documentation/generation-ia.md` les fournisseurs testés en réel et la date du test.

### Critères d'acceptation

- `php tests/run.php` passe, avec au moins 5 cas par fournisseur.
- Une recherche dans les logs après un échec 401 ne trouve ni la clé API, ni l'URL complète, ni le prompt.
