**Contexte :** le projet n’a pas encore de point d’entrée permettant aux visiteurs de dialoguer.
**Objectif :** intégrer un chat accessible au site et relié au chatbot configuré.
- Ajouter un shortcode ou bloc WordPress réutilisable et compatible avec les thèmes courants.
- Créer un endpoint sécurisé pour envoyer les messages et restituer les réponses.
- Connecter le flux aux issues 1 à 4 : génération, contexte, persistance et limites.
- Prévoir états de chargement, erreurs compréhensibles, navigation clavier et affichage mobile.
- Protéger les requêtes contre les abus et valider/échapper les entrées et sorties affichées.
**Dépendances :** issues 1, 2, 3 et 4 terminées.
**Acceptation :** parcours de conversation testé pour visiteur anonyme, utilisateur connecté et refus de limite.

---
---

# Revue du 2026-11-11

> Section ajoutée après coup. Le texte d'origine ci-dessus est conservé tel quel.

## Critique

**Correspondance GitHub :** à créer ou à vérifier.

- **Dépendances fausses** : « issues 1, 2, 3 et 4 » renvoient à une numérotation interne qui ne correspond ni aux fichiers ni à GitHub. Les vraies dépendances sont 005 (limites), 006 (contexte), 008 (multi-tours) et 009 (cadrage). La persistance (0004 / #10) **n'est pas** nécessaire au MVP.
- **Piège non anticipé : cache de page et nonce.** `claude.md` impose un nonce `wp_rest`. Si le nonce est écrit dans le HTML de la page et que le site utilise un cache de page (WP Super Cache, LiteSpeed, cache de l'hébergeur), les visiteurs reçoivent un nonce périmé au bout de 12 à 24 h, et le chat renvoie des erreurs 403 sans raison apparente. Il faut récupérer le nonce **à l'ouverture du chat**, par une requête non mise en cache.
- **L'historique n'est pas défini** : sans persistance, d'où vient l'historique de la conversation ? Il faut trancher.
- **L'affichage des réponses n'est pas défini** : les modèles répondent en Markdown (listes, gras). Afficher du texte brut est peu lisible ; convertir en HTML impose `wp_kses` et une liste blanche (voir `claude.md`).
- **Il manque des éléments propres au produit** : message d'accueil et questions suggérées (très efficaces pour un CV : « Quelles sont ses compétences ? »), mention « l'IA peut se tromper », information RGPD.
- **Le choix du chatbot n'est pas prévu** : le shortcode doit pouvoir désigner un chatbot.
- **« Shortcode ou bloc »** : il faut choisir. Le shortcode suffit pour le MVP ; un bloc demande un build JS.
- **Les critères d'acceptation sont à compléter** : les trois parcours cités sont bons, mais il manque les cas d'erreur, l'accessibilité et le mobile.

## Issue proposée

**Titre :** Chat public : shortcode et endpoint REST
**Dépend de :** [005](005-appliquer_limite.md), [006](006-markdown.md), [008](008-generation-multitours.md), [009](009-cadrage-garde-fous.md). [0004](0004-prompt-initiale.md) est facultative.

### Objectif

Un visiteur peut discuter, depuis n'importe quelle page du site, avec le chatbot cadré par l'admin, sur mobile comme sur ordinateur, au clavier comme à la souris.

### Décisions

| Sujet | Décision |
|---|---|
| Intégration | Shortcode `[wpia_chat chatbot="ID"]`. Sans attribut, c'est le chatbot actif par défaut. Si `chatbot_enabled` = 0 ou si le chatbot est inactif, rien n'est affiché (un administrateur connecté voit un message explicatif). Le bloc Gutenberg est hors périmètre. |
| Assets | `assets/js/chat.js` et `assets/css/chat.css` en JavaScript simple, sans build, chargés **uniquement** si le shortcode est présent. |
| Routes REST | `GET wpia/v1/session` : renvoie un nonce frais, la configuration publique (message d'accueil, questions suggérées) et pose le cookie `wpia_session`. **Réponse non cachable** (`Cache-Control: no-store`).<br>`POST wpia/v1/chat` : envoi d'un message. |
| Nonce | Récupéré par `GET wpia/v1/session` à la première ouverture du chat, jamais écrit dans le HTML. Il est envoyé dans l'en-tête `X-WP-Nonce`. Si le nonce a expiré (403 `rest_cookie_invalid_nonce`), le script en demande un nouveau et renvoie le message **une seule fois**. |
| `permission_callback` | Vérifie que le chatbot demandé est actif et public. Le nonce est contrôlé par WordPress. Le refus de quota est géré par 005 (429). |
| Arguments | `chatbot` (`absint`), `message` (`sanitize_textarea_field`, de 1 à `max_message_length` caractères, réglage **500** par défaut), `historique` (voir ligne suivante). |
| Historique | **Stockage désactivé (MVP)** : le navigateur garde l'historique dans `sessionStorage` et envoie les `history_max_messages` derniers échanges (voir 008). Le serveur valide : rôles `user`/`assistant` uniquement, longueur de chaque message bornée, nombre borné. Un visiteur qui falsifie son historique ne trompe que sa propre conversation : risque accepté et documenté.<br>**Stockage activé** (#10) : le serveur ignore l'historique envoyé et lit la base. |
| Anti-rafale | En plus des quotas de 005 : au maximum 1 requête toutes les 2 secondes par identité, par un transient de courte durée. |
| Affichage des réponses | Conversion Markdown → HTML **côté serveur**, puis `wp_kses()` avec une liste blanche : `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `code`, `pre`, `a[href]` (avec `rel="nofollow noopener"` et `target="_blank"` imposés, schémas `http`/`https`/`mailto` seulement). La conversion utilise un convertisseur minimal interne (gras, italique, listes, liens, code), **sans nouvelle dépendance** ; une bibliothèque comme Parsedown serait à proposer et à justifier à part. Le message de l'utilisateur est inséré avec `textContent`. |
| Erreurs | Les messages côté client sont génériques et traduisibles : limite atteinte (429), service indisponible, message trop long, session expirée. Le détail n'apparaît que dans les logs serveur. |

### Tâches

1. **`PublicChatController`** (`src/Front/`, nouveau dossier pour séparer front-office et admin) : shortcode, enregistrement conditionnel des assets, routes REST.
2. **`chat.js`** :
   - widget (bouton d'ouverture, panneau, liste de messages, zone de saisie) ;
   - états : chargement (indicateur, bouton désactivé), erreur, limite atteinte (saisie désactivée jusqu'au lendemain) ;
   - historique dans `sessionStorage` ;
   - renouvellement du nonce.
3. **Accessibilité** : liste des messages en `role="log"` avec `aria-live="polite"` ; `Entrée` envoie, `Maj+Entrée` passe à la ligne ; `Échap` ferme le panneau et rend le focus au bouton d'ouverture ; libellés sur tous les contrôles ; contraste AA.
4. **Mobile** : panneau en plein écran sous 600 px de large ; la zone de saisie reste visible quand le clavier virtuel est ouvert.
5. **Contenu** : message d'accueil et questions suggérées (cliquables) issus de 009 ; mention « Réponses générées par une IA, elles peuvent contenir des erreurs » ; lien vers la politique de confidentialité si elle est définie.
6. **Convertisseur Markdown** minimal (`src/Service/MarkdownRenderer.php`), suivi de `wp_kses`.
7. **Documentation** : `documentation/chat-public.md` (shortcode, attributs, compatibilité avec le cache de page, personnalisation CSS par variables).

### Hors périmètre

Bloc Gutenberg, réponse en streaming, envoi de fichiers, widget flottant présent sur toutes les pages (à voir plus tard, par une option).

### Critères d'acceptation

- **Visiteur anonyme** : il ouvre le chat, clique une question suggérée et reçoit une réponse qui s'appuie sur le CV ; une deuxième question qui fait référence à la première (« et avant ça ? ») est comprise.
- **Utilisateur connecté** : même parcours ; le quota est compté sur `u:<id>` et non sur l'IP.
- **Limite atteinte** : message explicite, saisie désactivée, aucun appel au fournisseur.
- **Cache de page** : une page servie depuis le cache plus de 24 h après sa génération fonctionne quand même (le nonce est récupéré à l'ouverture).
- **Nonce expiré** pendant la conversation : un seul renvoi transparent, puis un message d'erreur si le renvoi échoue.
- **Sécurité de l'affichage** : un message utilisateur `<img src=x onerror=alert(1)>` s'affiche comme du texte ; une réponse IA contenant `<script>` ou `javascript:` est neutralisée.
- **Validation** : un message de 501 caractères est refusé (400) côté serveur, même si la limite côté navigateur est contournée.
- **Clavier** : tout le parcours est faisable sans souris ; le lecteur d'écran annonce les nouvelles réponses.
- **Mobile** : utilisable à 360 px de large, sans défilement horizontal.
- **Assets** : `chat.js` n'est pas chargé sur une page sans shortcode.
- **Secrets** : aucune réponse REST ni le HTML ne contiennent la clé API, le prompt système ou le contenu brut des documents.
