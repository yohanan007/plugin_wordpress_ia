# Le chat public

Ce guide explique comment afficher le chatbot sur votre site, comment l'adapter
visuellement, et comment il se comporte avec un cache de page. La dernière
section, destinée aux développeurs, décrit l'API REST et les mesures de sécurité.

## Afficher le chat

1. Configurez le chatbot (fournisseur, compte, modèle, cadrage) dans *IA Chatbot →
   Configuration IA*, puis ajoutez au moins un document (voir
   [contexte.md](contexte.md) et [cadrage.md](cadrage.md)).
2. Dans *Limites et affichage*, cochez **Afficher le chatbot**.
3. Collez le shortcode dans une page, un article ou un widget « Shortcode » :

```
[wpia_chat]
```

Le chat apparaît sous la forme d'un bouton **Ouvrir le chat** ; le visiteur clique
dessus pour afficher la conversation.

| Attribut | Rôle |
|---|---|
| `chatbot="ID"` | Désigne un chatbot. Sans attribut, c'est le chatbot actif. Seul le chatbot actif est utilisable pour l'instant : un autre identifiant n'affiche rien. |

### Quand rien ne s'affiche

Le shortcode n'affiche **rien** aux visiteurs si l'affichage est désactivé, si le
chatbot est inactif ou introuvable. Un administrateur connecté voit à la place un
court message gris qui l'explique (les visiteurs ne le voient pas).

Les fichiers `chat.css` et `chat.js` ne sont chargés que sur les pages qui
contiennent le shortcode.

### Plusieurs shortcodes sur une page

C'est possible, mais déconseillé : chaque chat garde sa propre conversation, et
les deux comptent dans les mêmes limites du visiteur.

## Ce que voit le visiteur

- le **message d'accueil** et les **questions suggérées** définis dans le cadrage
  (les suggestions disparaissent après la première question) ;
- la mention « Réponses générées par une IA, elles peuvent contenir des erreurs » ;
- un lien vers votre **politique de confidentialité**, si elle est définie dans
  *Réglages → Confidentialité* ;
- les réponses mises en forme : gras, italique, listes, code, liens. Les titres sont
  affichés en gras. Les liens s'ouvrent dans un nouvel onglet.

Le visiteur écrit avec **Entrée** (envoi) ou **Maj + Entrée** (retour à la ligne).
**Échap** ferme le panneau. Sur mobile (moins de 600 px de large), le panneau
occupe tout l'écran.

### Messages d'erreur

| Situation | Message |
|---|---|
| Service du fournisseur en échec | « Le service est momentanément indisponible. » |
| Quota atteint | « Limite atteinte : vous pourrez écrire de nouveau demain. » La saisie est désactivée. |
| Messages trop rapprochés | « Merci de patienter quelques secondes entre deux messages. » |
| Message trop long | « Votre message est trop long. » |
| Session expirée | « La session a expiré. Rechargez la page puis réessayez. » |

Le détail technique d'une erreur n'est jamais affiché : il se trouve dans les
journaux du plugin.

## Réglages

| Réglage (page *Limites et affichage*) | Effet sur le chat |
|---|---|
| Afficher le chatbot | Interrupteur général du chat public |
| Chat public : longueur maximale d'un message | 500 caractères par défaut (de 20 à 2 000). Contrôlée aussi côté serveur |
| Conserver l'historique | Désactivé, chaque message est traité seul |
| Historique : nombre maximum de messages | Nombre de messages précédents renvoyés à l'IA |
| Messages par visiteur et par jour, tokens par conversation, budget du site | Voir [limites.md](limites.md) |

En plus de ces quotas, une même personne ne peut envoyer **qu'un message toutes les
2 secondes**.

## Cache de page

Aucun réglage particulier n'est nécessaire pour WP Super Cache, LiteSpeed Cache,
W3 Total Cache ou le cache de votre hébergeur.

La page mise en cache ne contient que le bouton. Le **nonce de sécurité** n'est jamais
écrit dans la page : le navigateur le demande au serveur à l'ouverture du chat
(`GET /wp-json/wpia/v1/session`, réponse marquée « ne pas mettre en cache »). Une page
servie depuis le cache plus de 24 heures après sa génération fonctionne donc encore.

Si le chat échoue malgré tout, vérifiez dans l'extension de cache :

- que les adresses `/wp-json/wpia/` **ne sont pas mises en cache** (certaines
  extensions mettent en cache les réponses REST ; ajoutez `wpia/v1` à leurs exclusions) ;
- que `chat.js` n'est pas combiné ou différé d'une façon qui empêche son exécution
  (testez en l'excluant de l'optimisation JavaScript).

## Personnaliser l'apparence

Les couleurs et les angles se règlent avec des variables CSS, à ajouter dans
*Apparence → Personnaliser → CSS additionnel* :

```css
.wpia-chat {
	--wpia-accent: #0b6b3a;      /* boutons, en-tête, bulle du visiteur */
	--wpia-accent-text: #ffffff; /* texte sur la couleur d'accent */
	--wpia-bg: #ffffff;          /* fond du panneau */
	--wpia-text: #0f172a;        /* texte */
	--wpia-bubble: #f1f5f9;      /* bulle de l'assistant */
	--wpia-radius: 12px;         /* arrondis */
}
```

Gardez un contraste suffisant (au moins 4,5:1 entre le texte et son fond) : les valeurs
par défaut respectent le niveau AA des recommandations d'accessibilité.

Classes utiles pour aller plus loin : `.wpia-chat__panel`, `.wpia-chat__msg--user`,
`.wpia-chat__msg--assistant`, `.wpia-chat__input`, `.wpia-chat__send`.

## Vie privée

- Le plugin pose un cookie technique **`wpia_session`** (valeur aléatoire, durée de la
  session du navigateur, `HttpOnly`) qui sert à compter la longueur d'une conversation.
  Il n'est enregistré que sous forme hachée.
- L'adresse IP n'est jamais enregistrée en clair : seul un identifiant haché sert à
  appliquer les quotas.
- Le navigateur garde la conversation dans `sessionStorage` (jusqu'à la fermeture de
  l'onglet) et, après une limite atteinte, la date du jour dans `localStorage`.
- Le serveur **ne stocke pas** les conversations tant que la persistance (issue 0004)
  n'est pas activée.

Mentionnez le chat, le fournisseur d'IA utilisé et ce cookie dans votre politique de
confidentialité. Le contenu des messages est envoyé au fournisseur d'IA configuré.

## Pour les développeurs

### Routes REST

| Route | Rôle |
|---|---|
| `GET /wp-json/wpia/v1/session?chatbot=ID` | Renvoie `nonce`, `chatbot`, `accueil`, `questions`, `longueur_max`, `historique_actif`, `historique_max` et pose le cookie `wpia_session`. En-tête `Cache-Control: no-store`. 404 si le chat est indisponible. |
| `POST /wp-json/wpia/v1/chat` | Corps JSON : `chatbot`, `message`, `historique` (liste de `{role, content}`). En-tête `X-WP-Nonce`. Renvoie `{texte, html}`. |

Codes d'erreur : `400` (`wpia_invalid_message`, `wpia_invalid_history`), `403`
(nonce périmé, géré par WordPress : `rest_cookie_invalid_nonce`), `404`
(`wpia_unavailable`), `429` (`wpia_limit_reached` avec `portee`, ou `wpia_too_fast` avec
`Retry-After`), `503` (`wpia_service_unavailable`).

### Organisation du code

| Fichier | Rôle |
|---|---|
| `src/Front/PublicChatController.php` | Lien avec WordPress : routes, shortcode, assets, cookie, nonce, `wp_kses`, anti-rafale |
| `src/Front/PublicChatHandler.php` | Logique du chat, sans objet REST (testée dans `tests/run.php`) |
| `src/Service/MarkdownRenderer.php` | Markdown → HTML, tout est échappé avant d'être balisé |
| `assets/js/chat.js`, `assets/css/chat.css` | Interface, sans étape de build |

### Chaîne de traitement d'une réponse

1. `AiGenerationService::generateChat()` : limites, appel au fournisseur, puis
   `OutputGuard` (fuite du prompt, hors sujet).
2. `MarkdownRenderer::render()` : échappe tout le texte, puis ajoute les balises.
3. `wp_kses()` avec la liste blanche `p, br, strong, em, ul, ol, li, code, pre, a[href,
   rel, target]` et les schémas `http`, `https`, `mailto`.
4. `chat.js` filtre encore le HTML avant insertion ; le texte du visiteur est inséré
   avec `textContent`.

### Points de sécurité

- **Nonce** : récupéré à l'ouverture, jamais dans le HTML. `GET session` valide le
  cookie `logged_in` avant de créer le nonce : sans cela, WordPress traite la requête
  comme anonyme et un utilisateur connecté recevrait un nonce refusé ensuite (403).
- **Identité des quotas** : `u:<id>` pour un utilisateur connecté (nonce valide), sinon
  l'adresse IP hachée. Derrière un proxy de confiance, le filtre `wpia_client_ip`
  permet de lire la vraie adresse.
- **Historique** : fourni par le navigateur, borné et validé côté serveur. Un visiteur
  qui le falsifie ne trompe que sa propre conversation : risque accepté.
- **Secrets** : aucune réponse ne contient la clé API, le prompt système ni le contenu
  brut des documents.

### Limites connues

- Le contrôleur et `chat.js` ne sont pas couverts par les tests automatiques.
- Un lien Markdown dont l'URL contient des parenthèses n'est pas converti en lien.
- Pas de bloc Gutenberg, de streaming, d'envoi de fichiers ni de widget flottant
  présent sur toutes les pages.
