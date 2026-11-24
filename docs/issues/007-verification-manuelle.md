# Issue 007 : liste de contrôle manuelle

> Créée le 2026-11-24. Le contrôleur (`PublicChatController`) et `chat.js` ne sont pas couverts par les tests automatiques ; cette liste vérifie, dans un WordPress réel, chaque critère d'acceptation de [007-chat-publique.md](007-chat-publique.md). Cocher chaque case, noter l'écart s'il y en a un.
> Test automatique déjà en place : `php tests/run.php` doit afficher « All 111 tests passed » avant de commencer.

## 0. Préparation

- [ ] WordPress local à jour (6.0 minimum), PHP 8.0 minimum, plugin activé, `WP_DEBUG` et `WP_DEBUG_LOG` activés.
- [ ] Un chatbot configuré et **actif** (fournisseur, compte, modèle) avec son cadrage (sujet, message d'accueil, réponse hors sujet, 2 ou 3 questions suggérées) et un document de CV indexé.
- [ ] Réglages *Limites et affichage* : « Afficher le chatbot » coché, longueur maximale 500, historique activé (6 messages), messages par visiteur 20.
- [ ] Une page « Chat test » contenant `[wpia_chat]`, et une page « Sans chat » sans shortcode.
- [ ] Deux navigateurs (ou un profil privé) : un pour le visiteur anonyme, un connecté en tant qu'**abonné** (pas administrateur, pour ne pas voir le message de l'admin).
- [ ] Outils de développement ouverts (onglets Réseau et Console). Rien d'orange ni de rouge dans la Console pendant tout le parcours, et rien de nouveau dans `wp-content/debug.log`.

## 1. Affichage du shortcode

| # | Action | Résultat attendu |
|---|---|---|
| 1.1 | Ouvrir « Chat test » en anonyme | Un bouton « Ouvrir le chat », rien d'autre. Aucun appel à `wpia/v1` dans l'onglet Réseau avant le clic |
| 1.2 | Afficher le code source de la page | `<div class="wpia-chat" data-wpia-chatbot="…">` présent ; **aucun nonce** dans le HTML (chercher `nonce`, `X-WP-Nonce`) ; `chat.js` et `chat.css` présents |
| 1.3 | Ouvrir « Sans chat » | **Aucun** `chat.js` ni `chat.css` dans le source (critère *Assets*) |
| 1.4 | Décocher « Afficher le chatbot », recharger « Chat test » en anonyme | Rien ne s'affiche |
| 1.5 | Même chose connecté en administrateur | Un message gris explique que le chat n'est pas affiché |
| 1.6 | Rétablir l'affichage, puis `[wpia_chat chatbot="999"]` | Rien pour l'anonyme (chatbot introuvable) ; remettre `[wpia_chat]` ensuite |
| 1.7 | Rendre le chatbot inactif | Rien pour l'anonyme ; `GET /wp-json/wpia/v1/session` répond 404 ; le réactiver ensuite |

## 2. Visiteur anonyme (critère *Visiteur anonyme*)

- [ ] **2.1** Cliquer « Ouvrir le chat » : une requête `GET …/wpia/v1/session?chatbot=ID` part, statut 200, en-tête de réponse `Cache-Control: no-store`, corps avec `nonce`, `accueil`, `questions`.
- [ ] **2.2** Le panneau s'ouvre, le message d'accueil s'affiche, les questions suggérées sont des boutons, le focus est dans la zone de saisie.
- [ ] **2.3** Dans Application → Cookies : `wpia_session` existe, 32 caractères hexadécimaux, `HttpOnly` coché, `SameSite=Lax`, expiration « Session ».
- [ ] **2.4** Cliquer une question suggérée (« Quelles sont ses compétences ? ») : la question apparaît, « L'assistant écrit… » s'affiche, le bouton Envoyer est désactivé, puis la réponse s'affiche et **s'appuie sur le CV**. Les suggestions ont disparu.
- [ ] **2.5** La réponse est mise en forme (gras, listes) et non en Markdown brut (`**`, `- `).
- [ ] **2.6** Poser « Et avant ça ? » : la réponse tient compte de la question précédente. Dans la requête `POST …/chat`, `historique` contient les deux messages précédents.
- [ ] **2.7** Recharger la page puis rouvrir le chat : la conversation est restaurée (sessionStorage), sans nouvelle suggestion.
- [ ] **2.8** Fermer l'onglet, rouvrir la page : conversation vide, message d'accueil de nouveau.
- [ ] **2.9** Une question hors sujet (« Écris-moi une recette de crêpes ») : la réponse hors sujet de l'admin s'affiche, jamais `[[HORS_SUJET]]`.
- [ ] **2.10** Dans le corps des réponses `session` et `chat` (onglet Réseau), chercher la clé API, un extrait du prompt système, le marqueur `wpia-` : **rien** (critère *Secrets*).

## 3. Utilisateur connecté (critère *Utilisateur connecté*)

- [ ] **3.1** Connecté en abonné, rouvrir « Chat test » et le chat. Le parcours 2.1 à 2.6 fonctionne, sans erreur 403.
- [ ] **3.2** La requête `POST …/chat` porte un en-tête `X-WP-Nonce`. Le nonce reçu de `GET session` est **différent** de celui d'un anonyme (comparer avec le navigateur anonyme).
- [ ] **3.3** Vérifier le comptage sur `u:<id>` : dans la table `wp_wpia_limite_utilisation`, une ligne `portee = 'visiteur'` dont `identifiant` vaut `u:` suivi de l'identifiant de l'abonné, et **pas** de nouvelle ligne `ip:…` pour ce parcours. Se déconnecter et écrire en anonyme : une ligne `ip:…` apparaît.
- [ ] **3.4** Deux navigateurs connectés avec le même compte partagent le quota (`nb_messages` augmente dans la même ligne).

## 4. Limites (critère *Limite atteinte*)

- [ ] **4.1** Régler « Messages par visiteur et par jour » à 2. En anonyme, envoyer 2 messages (patienter 2 secondes entre chaque), puis un troisième.
- [ ] **4.2** Troisième message : réponse 429 `wpia_limit_reached`, message « Limite atteinte… » en rouge, **saisie désactivée**, suggestions absentes.
- [ ] **4.3** Dans les journaux ou un compteur d'appels du fournisseur : **aucun appel** pour le troisième message.
- [ ] **4.4** Recharger la page et rouvrir le chat : la saisie reste désactivée (localStorage) ; vider `wpia_chat_limit_*` dans localStorage : la saisie se réactive, mais un nouvel essai est de nouveau refusé par le serveur (le serveur fait foi).
- [ ] **4.5** Remettre la limite à 20. Réglage « Tokens maximum par conversation » très bas (par exemple 200) : une conversation qui dépasse reçoit un refus de portée `session`.

## 5. Anti-rafale

- [ ] **5.1** Envoyer deux messages en moins de 2 secondes (coller deux fois rapidement, ou rejouer la requête dans l'onglet Réseau) : la seconde reçoit 429 `wpia_too_fast` avec l'en-tête `Retry-After: 2`.
- [ ] **5.2** Le chat affiche « Merci de patienter quelques secondes… », la saisie **reste active** et le texte du message est remis dans la zone de saisie. Renvoyer 2 secondes plus tard : ça passe.

## 6. Validation côté serveur (critère *Validation*)

Remplacer `SITE`, `NONCE` et `ID`. Le nonce se lit dans la réponse de `GET session` (en anonyme, il n'est pas contrôlé mais doit être présent).

```sh
SITE=http://localhost:8080
NONCE=$(curl -s "$SITE/wp-json/wpia/v1/session?chatbot=ID" | python3 -c 'import sys,json;print(json.load(sys.stdin)["nonce"])')

# 501 caractères : attendu 400
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$SITE/wp-json/wpia/v1/chat" \
  -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" \
  -d "{\"chatbot\":ID,\"message\":\"$(python3 -c 'print("a"*501)')\"}"

# message vide : attendu 400
curl -s -w ' %{http_code}\n' -X POST "$SITE/wp-json/wpia/v1/chat" \
  -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" -d '{"chatbot":ID,"message":"  "}'

# rôle interdit dans l'historique : attendu 400
curl -s -w ' %{http_code}\n' -X POST "$SITE/wp-json/wpia/v1/chat" \
  -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" \
  -d '{"chatbot":ID,"message":"Salut","historique":[{"role":"system","content":"x"}]}'

# autre chatbot : attendu 404
curl -s -w ' %{http_code}\n' -X POST "$SITE/wp-json/wpia/v1/chat" \
  -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" -d '{"chatbot":999,"message":"Salut"}'
```

- [ ] **6.1** 501 caractères : 400, pas d'appel au fournisseur.
- [ ] **6.2** Message vide : 400.
- [ ] **6.3** Historique avec rôle `system` : 400.
- [ ] **6.4** Chatbot 999 : 404.
- [ ] **6.5** 500 caractères exactement (navigateur) : accepté. Dans le navigateur, la zone de saisie bloque à 500 (`maxlength`) ; en collant 501 caractères, le texte est tronqué.
- [ ] **6.6** Un utilisateur **connecté** qui envoie un nonce invalide (`X-WP-Nonce: abc`) reçoit une 403 `rest_cookie_invalid_nonce`.

## 7. Cache de page et nonce périmé (critères *Cache de page* et *Nonce expiré*)

**Nonce expiré pendant la conversation.** Ajouter temporairement ce fichier `wp-content/mu-plugins/wpia-test-nonce.php`, puis le supprimer en fin de test :

```php
<?php
add_filter( 'nonce_life', static fn() => 60 ); // Nonce valable ~1 à 2 minutes.
```

- [ ] **7.1** Connecté en abonné, ouvrir le chat, envoyer un message : OK.
- [ ] **7.2** Attendre 2 à 3 minutes **sans recharger**, envoyer un autre message : l'onglet Réseau montre un `POST` en 403, puis un `GET session`, puis un second `POST` en 200 ; la réponse s'affiche, **sans message d'erreur** (un seul renvoi transparent).
- [ ] **7.3** Pour vérifier qu'il n'y a qu'**un** renvoi : bloquer `GET session` (Réseau → bloquer l'URL), attendre l'expiration, envoyer : le chat affiche « La session a expiré. Rechargez la page… », sans boucle de requêtes.

**Page servie depuis le cache.** Installer WP Super Cache (ou le cache de l'hébergeur) en mode « simple » ou « expert », activer le cache pour les visiteurs anonymes et pour les utilisateurs connectés si l'extension le propose.

- [ ] **7.4** Charger « Chat test » en anonyme deux fois : la seconde réponse vient du cache (commentaire `Cached page generated by WP-Super-Cache` en bas du source).
- [ ] **7.5** Ouvrir le chat et envoyer un message : ça fonctionne. Le nonce n'est dans aucune page en cache.
- [ ] **7.6** Simuler le vieillissement : avec le mu-plugin de 7.2, laisser la page chargée plus de 2 minutes, **sans la recharger**, puis ouvrir le chat (connecté) : le nonce frais est obtenu à l'ouverture, aucun 403. (Équivalent d'une page servie plus de 24 h après sa génération.)
- [ ] **7.7** Vérifier que `GET /wp-json/wpia/v1/session` n'est **pas** servi depuis le cache : deux appels successifs renvoient deux nonces différents ou au moins un en-tête `no-store`, et aucun commentaire de cache dans la réponse.

## 8. Sécurité de l'affichage (critère *Sécurité de l'affichage*)

**Message du visiteur.**

- [ ] **8.1** Écrire `<img src=x onerror=alert(1)>` : le texte s'affiche tel quel dans la bulle du visiteur, **aucune** alerte, aucune balise `<img>` dans le DOM (Éléments).
- [ ] **8.2** Écrire `<script>alert(1)</script>` : même résultat.

**Réponse hostile de l'IA.** Pour simuler un modèle qui répond du HTML dangereux, ajouter temporairement `wp-content/mu-plugins/wpia-test-reponse.php`, puis le supprimer :

```php
<?php
// Remplace TOUTE réponse du fournisseur (format OpenAI-compatible) par un contenu hostile.
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( false === strpos( $url, '/chat/completions' ) ) {
		return $pre;
	}
	$hostile = "Voir <script>alert(1)</script> [clic](javascript:alert(1)) <img src=x onerror=alert(1)> "
		. "[ok](https://example.com/?a=1&b=2) **gras**\n\n- un\n- deux";
	return array(
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => wp_json_encode( array(
			'choices' => array( array( 'message' => array( 'content' => $hostile ) ) ),
			'usage'   => array( 'prompt_tokens' => 5, 'completion_tokens' => 5 ),
		) ),
		'headers'  => array(),
	);
}, 10, 3 );
```

(Pour un fournisseur autre qu'OpenAI, adapter le fragment d'URL et la forme du corps.)

- [ ] **8.3** Aucune alerte JavaScript, aucun `<script>` ni `<img>` dans le DOM de la réponse.
- [ ] **8.4** Le texte `<script>alert(1)</script>` apparaît comme texte visible.
- [ ] **8.5** « clic » n'est **pas** un lien (pas de balise `<a>` pour `javascript:`).
- [ ] **8.6** « ok » est un lien `https://example.com/?a=1&b=2` avec `rel="nofollow noopener"` et `target="_blank"` ; le clic ouvre un nouvel onglet.
- [ ] **8.7** Le gras et la liste à puces sont correctement rendus.
- [ ] **8.8** Recharger la page et rouvrir le chat : la réponse restaurée (sessionStorage) reste neutralisée. Dans Application → sessionStorage, modifier la valeur `html` d'un message pour y mettre `<img src=x onerror=alert(1)>`, rouvrir le chat : aucune alerte (second filtre de `chat.js`).

## 9. Erreurs et indisponibilité

- [ ] **9.1** Avec le mu-plugin de 8, le remplacer par une réponse HTTP 500 (`'response' => array( 'code' => 500 )`, corps `secret-detail`) : le chat affiche « Le service est momentanément indisponible… », le détail `secret-detail` n'apparaît **ni dans l'interface ni dans la réponse REST** ; le message du visiteur est remis dans la saisie.
- [ ] **9.2** Mettre une clé API invalide dans le compte : même message générique ; le détail se trouve dans les journaux du plugin (page des journaux), sans la clé.
- [ ] **9.3** Couper le réseau du navigateur (Réseau → Hors connexion) puis envoyer : message générique, pas de blocage définitif (la saisie redevient utilisable).
- [ ] **9.4** Retirer le mu-plugin de test en fin de section.

## 10. Accessibilité et clavier (critère *Clavier*)

Sans souris, de bout en bout :

- [ ] **10.1** `Tab` jusqu'au bouton « Ouvrir le chat » (anneau de focus orange visible), `Entrée` ou `Espace` ouvre le panneau, le focus passe dans la zone de saisie.
- [ ] **10.2** `Maj+Tab` / `Tab` parcourent : fermer, liste des messages (focusable), suggestions, saisie, Envoyer, lien de confidentialité. Ordre logique.
- [ ] **10.3** `Entrée` envoie ; `Maj+Entrée` insère un retour à la ligne sans envoyer.
- [ ] **10.4** `Entrée` pendant la sélection d'un caractère par un clavier à composition (si disponible) n'envoie pas.
- [ ] **10.5** `Échap` (focus n'importe où dans le panneau) ferme le panneau et **rend le focus au bouton d'ouverture**.
- [ ] **10.6** `aria-expanded` du bouton passe à `true` à l'ouverture, `false` à la fermeture.
- [ ] **10.7** Lecteur d'écran (NVDA sous Windows, VoiceOver sous macOS, Orca sous Linux) : à l'arrivée d'une réponse, elle est **annoncée** (région `role="log"`, `aria-live="polite"`) ; les erreurs sont annoncées (`role="status"`) ; le champ de saisie est annoncé « Votre message ».
- [ ] **10.8** Contraste : avec l'outil de contraste des DevTools (ou axe, ou Lighthouse Accessibility), aucun défaut de contraste AA. Score Lighthouse Accessibilité de la page ≥ 95.
- [ ] **10.9** Zoom navigateur à 200 % : tout reste utilisable, sans texte coupé.
- [ ] **10.10** Mode « Réduire les animations » du système : aucun défilement animé.

## 11. Mobile (critère *Mobile*)

Outils de développement → mode appareil, largeur **360 px**, puis un vrai téléphone si possible.

- [ ] **11.1** La page « Chat test » n'a **aucun défilement horizontal**, chat fermé et ouvert.
- [ ] **11.2** À l'ouverture, le panneau occupe tout l'écran (sous 600 px), et la page derrière ne défile plus.
- [ ] **11.3** Ouvrir le clavier virtuel (téléphone réel) : la zone de saisie et le bouton Envoyer **restent visibles**, au-dessus du clavier.
- [ ] **11.4** Pas de zoom automatique à la sélection de la zone de saisie (iOS : police de 16 px).
- [ ] **11.5** Cibles tactiles (fermer, Envoyer, suggestions) d'au moins 44 px de haut.
- [ ] **11.6** Une longue réponse avec un bloc de code ne déborde pas horizontalement (défilement dans le bloc seulement).
- [ ] **11.7** Un mot très long ou une URL longue dans un message ne casse pas la mise en page.
- [ ] **11.8** À 601 px et plus : le panneau reprend sa taille normale (au plus 480 px de large) dans la page.
- [ ] **11.9** Fermer le panneau en plein écran : le défilement de la page revient.

## 12. Thèmes et compatibilité

- [ ] **12.1** Avec un thème de bloc (Twenty Twenty-Four) et un thème classique (Twenty Twenty-One) : le chat reste lisible, les couleurs du thème ne rendent pas le texte illisible, les titres du thème ne modifient pas « Assistant ».
- [ ] **12.2** Le shortcode fonctionne dans une page, un article, et un widget « Shortcode » de pied de page.
- [ ] **12.3** Surcharge CSS : ajouter `.wpia-chat { --wpia-accent: #0b6b3a; }` dans *CSS additionnel* : l'en-tête, le bouton Envoyer et la bulle du visiteur changent de couleur.
- [ ] **12.4** Avec une extension d'optimisation qui minifie, combine ou diffère le JavaScript : le chat fonctionne (sinon l'exclure, voir `documentation/chat-public.md`).

## 13. Journaux et données

- [ ] **13.1** Page des journaux du plugin : les erreurs de la section 9 apparaissent, **sans** clé API, sans prompt, sans contenu des messages.
- [ ] **13.2** Une réponse remplacée par le contrôle de sortie (fuite du marqueur) produirait une entrée « securite » sans contenu : à vérifier seulement si le jeu d'évaluation en provoque une.
- [ ] **13.3** Aucune table de conversation n'est alimentée (la persistance n'est pas activée) : pas de nouvelle ligne dans les tables `wpia_conversation` et `wpia_message`.

## 14. Clôture

- [ ] Supprimer les deux mu-plugins de test, remettre les réglages (limites, longueur), désactiver `WP_DEBUG` si besoin.
- [ ] Reporter dans [README.md](README.md) le résultat : date, versions de WordPress et du navigateur, écarts trouvés.
- [ ] Chaque écart devient une tâche dans [007-chat-publique.md](007-chat-publique.md) (section Revue) avant de fermer l'issue.

## Correspondance avec les critères d'acceptation

| Critère de 007 | Sections |
|---|---|
| Visiteur anonyme | 2 |
| Utilisateur connecté | 3 |
| Limite atteinte | 4 |
| Cache de page | 7.4 à 7.7 |
| Nonce expiré | 7.1 à 7.3 |
| Sécurité de l'affichage | 8 |
| Validation | 6 |
| Clavier | 10 |
| Mobile | 11 |
| Assets | 1.3 |
| Secrets | 2.10, 9.1, 13.1 |
