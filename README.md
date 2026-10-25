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

## Administration

Le menu **IA Chatbot** donne accès au tableau de bord, aux documents Markdown,
à la configuration IA, à la sécurité, aux journaux et aux limites/alertes.
L'accès est réservé aux utilisateurs disposant de `manage_options`.

Les réglages globaux ont des valeurs prudentes par défaut et la sauvegarde ne
remplace pas les champs absents d'une configuration existante. Les clés API et
le contenu des documents sont chiffrés avec AES-256-GCM, à partir des salts
WordPress. Une rotation de `AUTH_KEY` ou `SECURE_AUTH_KEY` sans déchiffrement et
re-chiffrement préalable rend ces valeurs illisibles.

La configuration IA utilise l'API Settings de WordPress pour la validation et
la sauvegarde de la référence active ; les fournisseurs, comptes et chatbots
restent dans leurs tables métier. La clé API n'est jamais conservée dans cette
option WordPress.

La migration du schéma `1.1.0` ajoute aux documents leur taille en octets, leur
date d'ajout et l'état de validation du texte, ainsi qu'une table applicative
de journaux. Les anciennes lignes de documents conservent une date inconnue
jusqu'à leur mise à jour.

Les documents sont stockés chiffrés sous `wp-content/uploads/wpia-private` et
des règles de protection Apache/IIS sont installées dans ce répertoire. La
protection HTTP peut dépendre de la configuration du serveur, notamment avec
Nginx ; le chiffrement au repos évite toutefois de révéler le Markdown par un
accès direct au fichier. La limite d'envoi est de 2 Mo.

Le bouton de test API appelle uniquement le point de terminaison de liste des
modèles. Il ne demande pas de génération IA. Les tailles en tokens des
documents sont des estimations et le statut « prêt » signifie que le texte est
validé, pas vectorisé. L'affichage public du chatbot, l'application effective
des limites, la vectorisation et le RAG ne sont pas fournis par cette
administration. Le réglage d'affichage et le template associé sont enregistrés
pour préparer le rendu public, mais ne modifient pas encore le front-office.

Les journaux sont purgés par WP-Cron après 7 ou 30 jours, selon le choix
hebdomadaire ou mensuel. Comme tout événement WP-Cron, l'exécution dépend de
l'activité du site ; la conservation « jamais » désactive la purge automatique.
