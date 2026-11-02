# Génération IA

## Utilisation

La génération passe par `WPIA\Service\AiGenerationService`. Le service lit la
configuration active enregistrée par l’administration ; aucun identifiant de
fournisseur ni secret ne doit être transmis par l’appelant.

```php
$service = new \WPIA\Service\AiGenerationService();
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

## Flux et paramètres

1. Le service charge les identifiants de fournisseur, compte API et chatbot de
   `wpia_active_configuration`, puis vérifie leurs relations et leur état actif.
2. Il sélectionne le fournisseur depuis `AIProviderCatalog` et lit l’URL, le
   modèle, le prompt système, la température et le nombre de tokens du chatbot.
3. Le nombre maximal de tokens envoyé est le minimum entre la limite du chatbot
   et `max_tokens_request`. Le timeout provient de `api_timeout`.
4. La clé est déchiffrée avec `SecretCipher` juste avant la requête ; elle n’est
   jamais placée dans le résultat, un message d’erreur ou un journal.
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
2. Ajouter un adaptateur dans `src/Service/Fournisseur/` qui étend
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

Elle couvre les cinq formats de requête, la normalisation des succès et les
erreurs réseau, timeout, authentification, structure/JSON, contenu vide,
limite de sortie et absence de clé.
