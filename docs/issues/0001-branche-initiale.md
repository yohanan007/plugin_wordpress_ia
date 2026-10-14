# Issue #1 - Mise en place de l'architecture initiale du plugin WordPress IA

## Contexte

Le plugin a pour objectif d'intégrer un chatbot IA dans WordPress capable :

- d'utiliser des documents Markdown comme contexte ;
- de limiter la consommation de tokens ;
- de gérer plusieurs fournisseurs IA ;
- de proposer une interface d'administration ;
- de stocker les conversations ;
- de sécuriser les données sensibles.

Cette première issue a pour but de mettre en place les fondations techniques du projet afin de permettre le développement des futures fonctionnalités dans un cadre maintenable et évolutif.

---

## Objectifs

### Architecture du plugin

Mettre en place une architecture inspirée des principes SOLID tout en restant adaptée au fonctionnement de WordPress.

Arborescence cible :

```text
plugin-wordpress-ia/
│
├── plugin-wordpress-ia.php
├── uninstall.php
├── composer.json
│
├── src/
│   ├── Admin/
│   ├── Database/
│   ├── Domain/
│   ├── Repository/
│   ├── Service/
│   └── Infrastructure/
│
├── assets/
│
├── languages/
│
└── vendor/
```

---

### Mise en place de Composer et PSR-4

Créer le fichier `composer.json`.

Configurer l'autoload PSR-4 :

```json
{
    "autoload": {
        "psr-4": {
            "WPIA\\": "src/"
        }
    }
}
```

Le plugin devra charger automatiquement les classes via :

```php
require_once __DIR__ . '/vendor/autoload.php';
```

---

### Gestion du cycle de vie du plugin

Mettre en place :

- activation du plugin ;
- désactivation du plugin ;
- installation de la base de données ;
- système de versionnement du schéma SQL.

Constantes à prévoir :

```php
define('WPIA_VERSION', '1.0.0');
define('WPIA_DB_VERSION', '1.0.0');
```

---

### Installation de la base de données

Créer un service dédié :

```text
DatabaseInstaller
```

Responsabilités :

- vérifier l'existence des tables ;
- créer les tables manquantes ;
- enregistrer la version du schéma ;
- préparer les futures migrations.

L'installation devra utiliser :

```php
dbDelta()
```

afin de respecter les recommandations WordPress.

---

### Création des tables métier

Créer les tables du plugin avec un préfixe propre au projet afin d'éviter tout conflit avec d'autres plugins.

Format :

```php
$wpdb->prefix . 'wpia_*'
```

Tables à créer :

```text
wpia_fournisseur_ia
wpia_compte_api
wpia_chatbot
wpia_document
wpia_chatbot_document
wpia_conversation
wpia_message
wpia_limite_utilisation
```

Exemples :

```text
wp_wpia_chatbot
wp_wpia_document
wp_wpia_message
```

---

### Entités métier

Créer les entités correspondant au modèle métier :

```text
FournisseurIA
CompteApi
Chatbot
Document
ChatbotDocument
Conversation
Message
LimiteUtilisation
```

Les entités doivent uniquement contenir :

- les propriétés métier ;
- les getters/setters ;
- les validations simples.

Aucune logique SQL ne doit être présente dans les entités.

---

### Repositories

Créer les repositories de base :

```text
FournisseurIARepository
CompteApiRepository
ChatbotRepository
DocumentRepository
ConversationRepository
MessageRepository
LimiteUtilisationRepository
```

Responsabilités :

- création ;
- lecture ;
- modification ;
- suppression ;
- recherches simples.

Les accès SQL devront être centralisés dans les repositories.

---

### Services techniques

Créer les services suivants :

```text
PluginActivator
DatabaseInstaller
MigrationManager
```

Objectif :

- centraliser la logique d'installation ;
- centraliser la logique de migration ;
- préparer les évolutions futures.

---

## Contraintes

### Réactivation

Si le plugin est désactivé puis réactivé :

- aucune donnée ne doit être supprimée ;
- aucune table existante ne doit être recréée.

### Réinstallation

Lors d'une nouvelle installation :

- seules les tables manquantes doivent être créées ;
- les données existantes doivent être conservées.

### Compatibilité

Toutes les tables, classes, options WordPress et namespaces doivent être préfixés avec :

```text
WPIA
```

afin d'éviter tout risque de collision avec d'autres plugins.

---

## Critères d'acceptation

### Installation

- [ ] Le plugin s'active sans erreur.
- [ ] Les tables sont créées lors de la première activation.
- [ ] Les tables existantes ne sont pas recréées.
- [ ] La version du schéma est enregistrée.

### Réactivation

- [ ] Une désactivation ne supprime aucune donnée.
- [ ] Une réactivation conserve les données existantes.

### Architecture

- [ ] Composer est configuré.
- [ ] L'autoload PSR-4 fonctionne.
- [ ] L'arborescence cible est créée.
- [ ] Les entités métier sont créées.
- [ ] Les repositories sont créés.
- [ ] Les services techniques sont créés.

### Qualité

- [ ] Respect des principes SOLID lorsque cela apporte une valeur réelle.
- [ ] Les entités métier ne dépendent pas directement de WordPress.
- [*] Les accès SQL sont centralisés d*ns les repositories.
- [ ] Le code*est documenté et maintenable.

---*
## Hors périmètre

Cette issue ne*couvre pas :

- les appels aux API* IA ;
- le chiffrement des API Key* ;
- l'interface d'administration *
- le widget de chat ;
- l'upload de documents Markdown ;
- la gestion des conversations ;
- le calcul et la limitation des tokens ;
- les migrations avancées ;
- la documentation utilisateur.

---

## Livrable attendu

À la fin de cette issue, le projet doit disposer :

- d'une architecture technique stable ;
- d'un système de chargement automatique des classes ;
- d'un mécanisme d'installation de la base de données ;
- des tables métier initiales ;
- des entités métier ;
- des repositories ;
- des services techniques nécessaires aux futures évolutions.