Contexte

L'architecture applicative et les modèles de données du plugin ont été mis en place lors des premières étapes du projet.

Cette issue vise à développer l'interface d'administration WordPress permettant de configurer, piloter et superviser le chatbot IA directement depuis le back-office.

L'interface devra respecter les standards d'administration WordPress, être évolutive et préparer les fonctionnalités futures liées à la gestion documentaire, à la sécurité des données et aux fournisseurs d'IA.

Objectifs

Créer un menu d'administration dédié au plugin permettant à un administrateur WordPress de :

Configurer le chatbot.
Gérer les documents Markdown utilisés comme contexte.
Paramétrer l'accès aux API IA.
Contrôler les limitations de consommation.
Superviser l'activité du chatbot.
Administrer les paramètres de sécurité.
Fonctionnalités attendues



1. Création du menu d'administration

Ajouter une entrée de menu principale :

IA Chatbot


avec les sous-menus :

- Tableau de bord
- Documents Markdown
- Configuration IA
- Sécurité
- Journalisation
- Alerte token 

2. Tableau de bord

Créer une page de synthèse affichant :

Statut du plugin
Fournisseur IA sélectionné
Nombre de documents chargés
Nombre de conversations enregistrées
Consommation de tokens
État de la connexion API

Cette page doit constituer le point d'entrée principal de l'administrateur.

3. Gestion des documents Markdown

Créer une interface permettant :

Téléverser un fichier .md
Lister les documents enregistrés
Consulter les métadonnées :
nom
date d'ajout
taille
statut d'indexation
Supprimer un document
Réindexer un document

Prévoir une architecture permettant ultérieurement :

le découpage (chunking)
la vectorisation
la recherche contextuelle
4. Configuration IA

Créer un écran de configuration permettant de définir :

Fournisseur IA

Exemples :

OpenAI
Mistral
Anthropic
Ollama
fournisseur personnalisé
Paramètres généraux
URL de l'API
Modèle utilisé
température
nombre maximal de tokens
timeout
Gestion des clés API
champ de saisie sécurisé
valeur masquée dans l'interface
stockage chiffré
5. Gestion de la consommation

Créer une section permettant de définir :

nombre maximal de tokens par requête
nombre maximal de tokens par conversation
nombre maximal de messages par utilisateur
limites journalières

Prévoir un affichage statistique futur.

6. Paramètres de sécurité

Créer une interface permettant de gérer :

Chiffrement
activation/désactivation du chiffrement
affichage de l'état du système
Clé de chiffrement

Le système devra utiliser une clé dérivée de la configuration WordPress afin de :

chiffrer les secrets stockés
déchiffrer les informations au moment des appels API
Sécurité WordPress

Prévoir l'utilisation de :

Nonces WordPress
Vérification des capacités utilisateurs
Sanitization des données saisies
Escaping des données affichées
7. Journalisation

Créer un écran affichant :

erreurs API
erreurs de sécurité
erreurs de traitement des documents
logs applicatifs

8. Paramétrage par défault

Pour l'ensemble des ia connu , prévoir un paramétrage par défault 
pemettant de ne pas consommer de maniére excessive les tokens

Fonctionnalités :

filtrage
pagination
export futur
Contraintes techniques
WordPress

Respecter :

WordPress Coding Standards
API Settings
API Options
API Menu
API Capability
Internationalisation

Préparer l'ensemble des chaînes de caractères pour la traduction :

__('Texte', 'wp-ai-chatbot')

Critères d'acceptation
Menu d'administration fonctionnel.
Navigation entre les écrans opérationnelle.
Pages accessibles uniquement aux administrateurs autorisés.
Formulaires sécurisés par nonce.
Paramètres enregistrés via l'API Settings WordPress.
Structure permettant l'ajout futur de nouvelles fonctionnalités sans refonte majeure.
Respect des standards WordPress.
Hors périmètre

Les fonctionnalités suivantes ne sont pas incluses dans cette issue :

Interface publique du chatbot.
Appels réels vers une IA.
Gestion des conversations.
Vectorisation des documents.
Système RAG.
Statistiques avancées.
Gestion des rôles personnalisés.
Résultat attendu

À l'issue de cette tâche, le plugin doit disposer d'un back-office complet permettant de configurer et administrer l'ensemble des fonctionnalités du chatbot IA, même si certaines fonctionnalités métier seront implémentées dans les issues suivantes.

---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Statut :** livrée (GitHub #5, PR #6 et #7, commit `0227f23`).

- **Mise en forme perdue** : titres, listes et blocs de code sont aplatis (copier-coller depuis un rendu HTML). C'est difficile à lire, pour un humain comme pour un agent.
- **Erreur de copier-coller** : au point 8, « Paramétrage par défaut » est suivi de « Fonctionnalités : filtrage, pagination, export futur ». Ces trois éléments appartiennent au point 7 « Journalisation ».
- **« Alerte token » n'est pas spécifié** : le sous-menu existe, et `token_alert_threshold` aussi. Dans le code, le seuil sert seulement à un calcul affiché au tableau de bord (`AdminController.php`, à partir de `SUM(tokens)` sur `wpia_message`). Rien ne dit ce que doit faire l'alerte : un e-mail, un bandeau, un blocage ? C'est repris dans l'issue [005](005-appliquer_limite.md).
- **Le tableau de bord compte les tokens dans `wpia_message`**. Si les conversations ne sont pas stockées (voir la revue de 0004), le compteur restera à 0. La source de vérité de la consommation doit être la table des limites (voir 005).
- **Text domain incohérent** : l'issue cite `wp-ai-chatbot`, alors que le plugin utilise `plugin-wordpress-ia`.
- **Écart avec `claude.md`** : `claude.md` impose Vue.js pour l'administration, mais l'admin livrée est en PHP avec un `assets/js/admin.js` en JavaScript simple. Il faut trancher, puis aligner `claude.md`, sinon un agent produira du Vue au prochain écran.
- **Anticipation excessive** : « chunking, vectorisation, recherche contextuelle » sont annoncés pour les documents. Pour un contexte de type CV (quelques milliers de tokens), c'est très probablement inutile (voir 006).
- **Ce qui manque pour le produit visé** : l'admin ne permet pas de **cadrer la discussion** (persona, sujet autorisé, message hors sujet, message d'accueil, questions suggérées). C'est pourtant la fonction centrale. Voir la nouvelle issue [009](009-cadrage-garde-fous.md).
- **Critères impossibles à vérifier** : « Respect des standards WordPress », « Structure permettant l'ajout futur… sans refonte majeure ».

## Issue proposée (reliquat)

**Titre :** #5 – Clôture de l'interface d'administration
**Dépend de :** 005 pour l'alerte, 009 pour le cadrage

### Décisions

- L'administration reste en **PHP + API Settings + JavaScript simple** (déjà en place, aucun build npm nécessaire). `claude.md` est à mettre à jour en conséquence (par Yohanan, car le fichier est protégé).
- Le text domain de référence est `plugin-wordpress-ia`.

### Tâches

- [ ] Remettre en forme le texte d'origine en Markdown (titres, listes) et déplacer « filtrage / pagination / export » sous « Journalisation ».
- [ ] Remplacer la source du compteur de tokens du tableau de bord par celle définie dans l'issue 005.
- [ ] Le sous-menu « Alerte token » est spécifié dans l'issue 005 : pas de développement ici.
- [ ] Ajouter `composer` + `phpcs` avec WPCS (`wp-coding-standards/wpcs`), si l'ajout de cette dépendance de développement est accepté, afin de rendre « respect des standards WordPress » vérifiable.

### Critères d'acceptation

- `vendor/bin/phpcs --standard=WordPress src/Admin` ne remonte aucune erreur, ou les exceptions sont documentées.
- Un utilisateur avec le rôle « Éditeur » reçoit une erreur de droits sur chaque page `wpia-*`.
- Soumettre un formulaire d'admin avec un nonce invalide n'enregistre rien et affiche une erreur.
- `grep -rn "wp-ai-chatbot" src` ne renvoie rien.
