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

---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Statut :** terminée (GitHub #1, PR #4, commit `3f21913`).

- **Fichier abîmé** : dans le texte d'origine, les critères « Qualité » et « Hors périmètre » contiennent des astérisques parasites (`d*ns`, `API*`, `[*]`). Une seule case est « cochée », et sous une forme invalide (`[*]` au lieu de `[x]`).
- **Cases non cochées** alors que l'issue est livrée : on ne sait pas ce qui a été réellement vérifié.
- **Critères impossibles à vérifier** : « Respect des principes SOLID lorsque cela apporte une valeur réelle », « Le code est documenté et maintenable ». Personne ne peut dire objectivement que c'est fait.
- **Prévision généreuse** : 8 tables, 8 entités et 8 repositories pour un chat qui, dans son usage principal (un CV ou un parcours), n'a besoin que d'un chatbot, d'un compte API et de quelques documents. Ce n'est pas grave, puisque c'est fait, mais les issues suivantes ne doivent pas reproduire ce niveau d'anticipation.
- **Écart avec `claude.md`** : `claude.md` prévoit une constante de clé de chiffrement surchargeable dans `wp-config.php`. `SecretCipher::getKey()` dérive toujours la clé des sels, sans constante. À traiter dans l'issue [010 – RGPD, désinstallation et clé de chiffrement](010-rgpd-desinstallation.md).
- **`uninstall.php`** figure dans l'arborescence cible mais n'existe pas. Également repris dans l'issue 010.
- **Version du schéma** : `WPIA_DB_VERSION` est passée à `1.1.0` mais l'issue indique `1.0.0`. La règle de montée de version n'est écrite nulle part (qui l'incrémente, quand, et comment `MigrationManager` réagit).

## Issue proposée (reliquat)

> L'issue étant livrée, il ne s'agit pas de la réécrire, mais de lister ce qui reste à faire pour la clore proprement.

**Titre :** #1 – Clôture de l'architecture initiale
**Dépend de :** aucune

### Tâches

- [ ] Corriger les caractères abîmés du texte d'origine et cocher les critères réellement vérifiés.
- [ ] Documenter dans `documentation/architecture.md` la règle de versionnement du schéma : toute modification du `CREATE TABLE` incrémente `WPIA_DB_VERSION` ; `MigrationManager` compare `wpia_db_version` à la constante et rejoue `dbDelta()` ; une migration de données (et pas seulement de structure) doit être écrite à la main, dans une méthode dédiée et idempotente.
- [ ] Noter dans `documentation/architecture.md` les tables effectivement utilisées et celles qui sont prévues mais encore inutilisées.

### Critères d'acceptation

- Mettre `wpia_db_version` à `1.0.0`, puis charger l'admin : la version enregistrée repasse à la valeur de `WPIA_DB_VERSION`, et aucune donnée n'est perdue.
- Désactiver puis réactiver le plugin : le nombre de lignes de chaque table `wpia_*` est inchangé.
