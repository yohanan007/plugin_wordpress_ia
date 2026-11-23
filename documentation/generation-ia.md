# Génération IA

## Utilisation

La génération passe par `WPIA\Service\Ia\AiGenerationService`. Le service lit la
configuration active enregistrée par l’administration ; aucun identifiant de
fournisseur ni secret ne doit être transmis par l’appelant.

```php
$service = new \WPIA\Service\Ia\AiGenerationService();
$result  = $service->generate( 'Résume le texte suivant...' );

if ( is_wp_error( $result ) ) {
	// Afficher uniquement get_error_message() : le message est expurgé.
	return;
}

$text = $result['content'];
```

Le résultat comporte le texte (`content`), le modèle utilisé (`model`) et les
compteurs de tokens connus (`usage.input_tokens`, `usage.output_tokens` et
`usage.total_tokens`). Les compteurs inconnus valent `null`.

Un second argument optionnel `$contexte` précise l'identité utilisée par les
limites de consommation : `visiteur` (identifiant déjà pseudonymisé, calculé par
défaut depuis la requête) et `session` (jeton brut de conversation, haché avant
stockage). Les limites sont décrites dans [limites.md](limites.md).

## Conversation multi-tours

`generate()` fait un seul échange. Pour envoyer un historique, utiliser
`generateChat()` :

```php
$result = $service->generateChat(
	array(
		'messages'  => array(
			array( 'role' => 'user', 'content' => 'Quelles sont ses compétences ?' ),
			array( 'role' => 'assistant', 'content' => 'PHP, JavaScript…' ),
			array( 'role' => 'user', 'content' => 'Et en base de données ?' ), // la question, toujours en dernier
		),
		'chatbot_id' => 1,                                   // facultatif : doit être le chatbot actif
		'identites'  => array( 'visiteur' => 'u:7', 'session' => $jeton ), // comme `$contexte` de generate()
	)
);
```

- **Validation** : rôles `user` et `assistant` uniquement, contenu non vide, dernier
  message `user`, 40 messages et 8 000 caractères par message au plus. Sinon,
  `wpia_invalid_request` sans appel ni réservation. Le prompt système n'est
  jamais reçu de l'appelant : il est construit par le serveur.
- **Historique** : tous les messages sauf le dernier. Réglages de la section
  consommation : `history_enabled` (désactivé, seule la question est envoyée),
  `history_max_messages` (6 par défaut) et `history_token_budget` (1 500 par
  défaut). La troncature se fait toujours au début, deux messages à la fois, et
  le premier message conservé est un message `user`. La question n'est jamais
  tronquée.
- **Alternance** : deux messages consécutifs de même rôle sont fusionnés
  (séparés par une ligne vide).
- **Ordre envoyé** : prompt système (partie stable, puis partie variable),
  historique, question. Le plus stable vient en premier, car les fournisseurs qui
  mettent le prompt en cache (OpenAI, Anthropic) s'appuient sur le préfixe commun
  entre deux requêtes.
- **Limites** : la réservation porte sur tout ce qui est envoyé, historique
  conservé compris (voir [limites.md](limites.md)).

### Cache de prompt

- **Anthropic** : quand la partie stable du prompt système dépasse 1 024 tokens
  estimés, `system` est envoyé en blocs : le bloc stable porte
  `cache_control: {type: "ephemeral"}` et le bloc variable, s'il existe, le suit.
  Sinon, `system` reste une chaîne (corps identique à l'ancien). Sous le minimum
  propre au modèle (512 à 4 096 tokens selon le modèle), l'API ignore le marquage
  sans erreur : le seuil de 1 024 sert seulement à ne pas marquer les petits
  prompts. À revoir si les modèles configurés changent.
- **OpenAI et autres** : rien à faire, le cache d'OpenAI est automatique ; la
  partie stable est placée en premier pour former un préfixe commun.
- **Comptage** : `usage.input_tokens` contient **tous** les tokens d'entrée
  traités. Anthropic les sépare (`input_tokens` exclut le cache) : le service
  additionne `input_tokens`, `cache_creation_input_tokens` et
  `cache_read_input_tokens`. Chez OpenAI, `prompt_tokens` inclut déjà
  `cached_tokens`. `usage.cache_read_tokens` est ajouté quand le fournisseur le
  renvoie. Le limiteur compte des tokens, pas un coût : lire dans le cache ne
  réduit pas la consommation comptée.

## Flux et paramètres

1. Le service charge les identifiants de fournisseur, compte API et chatbot de
   `wpia_active_configuration`, puis vérifie leurs relations et leur état actif.
2. Il sélectionne le fournisseur depuis `AIProviderCatalog` et lit l’URL, le
   modèle, le prompt système, la température et le nombre de tokens du chatbot.
3. Le nombre maximal de tokens envoyé est le minimum entre la limite du chatbot
   et `max_tokens_request`. Le timeout provient de `api_timeout`.
4. La clé est déchiffrée avec `SecretCipher` juste avant la requête ; elle n’est
   jamais placée dans le résultat, un message d’erreur ou un journal.
   La consommation est ensuite réservée auprès de `UsageLimiter`. Si une limite
   est atteinte, `generate()` renvoie `wpia_limit_reached` (statut 429) sans
   appeler le fournisseur.
5. L’adaptateur réalise la requête HTTP. `ResponseNormalizer` valide le statut,
   le JSON, le texte et les compteurs avant de retourner le résultat standard.

Les fournisseurs OpenAI, Mistral et personnalisés utilisent le format
`/chat/completions` compatible OpenAI. Anthropic utilise `/messages` et Ollama
utilise `/api/chat`. Le fournisseur personnalisé doit donc exposer une API
compatible OpenAI. Les appels externes utilisent l’API HTTP sûre de WordPress ;
Ollama est autorisé en HTTP uniquement sur localhost.

## Erreurs et confidentialité

`generate()` retourne un `WP_Error` en cas d’erreur. Ses codes distinguent les
erreurs de configuration, d’authentification, de réseau, de timeout, de réponse,
de clé absente et de limite de sortie. Son message est générique et peut être
présenté à l’utilisateur. Les journaux ne contiennent que le nom de fournisseur
et le code d’erreur : ni prompt, ni réponse brute, ni URL ni clé.

La réponse fournisseur est plafonnée à 1 Mio. Si l’API fournit un nombre de
tokens de sortie supérieur à la limite demandée, la réponse est rejetée. Les
fournisseurs sans compteur de tokens renvoient `null` pour cette mesure.
Une ancienne clé stockée en clair est refusée par le service : réenregistrez la
configuration depuis l’administration pour que `CompteApiRepository` la chiffre.

## Ajouter un fournisseur

1. Ajouter sa configuration par défaut dans `AIProviderCatalog`.
2. Ajouter un adaptateur dans `src/Service/Ia/Fournisseur/` qui étend
   `AbstractProvider` et implémente `generate()`.
3. Construire l’URL, l’authentification et le payload dans cet adaptateur ; ne
   jamais journaliser la requête ou la clé.
4. Ajouter son extraction de contenu et de tokens dans `ResponseNormalizer`.
5. L’enregistrer dans la sélection de `AiGenerationService` et couvrir le
   format de requête et de réponse par des tests.

## Vérification

Lancer la suite autonome sans dépendance de test externe avec :

```sh
php tests/run.php
```

Elle couvre les cinq formats de requête (corps identique à l'ancien pour un seul message, historique, troncature, fusion des rôles), la normalisation des succès et les
erreurs réseau, timeout, authentification, structure/JSON, contenu vide,
limite de sortie et absence de clé.
