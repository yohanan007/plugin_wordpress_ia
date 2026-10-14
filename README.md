# Plugin WordPress IA

Plugin WordPress permettant d'intégrer un chatbot IA.

## Prérequis

- PHP 8.0 ou supérieur
- WordPress 6.0 ou supérieur
- Composer

## Installation

À la racine du plugin, générer l'autoload Composer :

```sh
composer install
```

Activez ensuite le plugin depuis l'administration WordPress. Les tables métier
manquantes sont créées à l'activation et la version du schéma est enregistrée
dans l'option `wpia_db_version`. Les données ne sont pas supprimées à la
désactivation.
