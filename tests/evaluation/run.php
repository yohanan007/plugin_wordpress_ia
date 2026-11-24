<?php
/**
 * Jeu d'évaluation du cadrage (issue 009). À LANCER À LA MAIN : une vingtaine
 * d'appels réels au fournisseur. Voir README.md.
 *
 *   WPIA_EVAL_PROVIDER=anthropic WPIA_EVAL_MODEL=... WPIA_EVAL_API_KEY=... php tests/evaluation/run.php
 *
 * Aucune base ni WordPress : le prompt est construit avec les classes du plugin
 * (PromptBuilder, MarkdownSectionizer, ContextBuilder::bloc), la température est
 * forcée à 0, et la réponse BRUTE (avant OutputGuard) est comparée au comportement
 * attendu. La clé n'est lue que dans l'environnement et n'est jamais affichée.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

// Fonctions WordPress minimales (les fournisseurs et le normaliseur n'en utilisent que ces quelques-unes).
function __( string $m, string $d = '' ): string { return $m; }
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_key( string $v ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $v ) ); }
function absint( $v ): int { return abs( (int) $v ); }
function untrailingslashit( string $v ): string { return rtrim( $v, '/\\' ); }
function wp_parse_url( string $u ): array|false { return parse_url( $u ); }
function wp_json_encode( $v, int $f = 0 ): string|false { return json_encode( $v, $f ); }
function wp_remote_retrieve_body( array $r ): string { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( array $r ): int { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_post( string $url, array $args ): array|WP_Error {
	$entetes = '';
	foreach ( $args['headers'] as $nom => $valeur ) {
		$entetes .= $nom . ': ' . $valeur . "\r\n";
	}
	// Flux PHP plutôt que curl : aucune extension requise.
	$contexte = stream_context_create(
		array(
			'http' => array(
				'method'          => 'POST',
				'header'          => $entetes,
				'content'         => $args['body'],
				'timeout'         => (int) $args['timeout'],
				'ignore_errors'   => true,
				'follow_location' => 0,
			),
		)
	);
	$corps = @file_get_contents( $url, false, $contexte );
	if ( false === $corps || empty( $http_response_header ) ) {
		return new WP_Error( 'http_error', 'connexion impossible' );
	}
	$code = preg_match( '#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m ) ? (int) $m[1] : 0;

	return array( 'response' => array( 'code' => $code ), 'body' => (string) $corps );
}
function wp_safe_remote_post( string $url, array $args ): array|WP_Error { return wp_remote_post( $url, $args ); }

$racine = dirname( __DIR__, 2 );
spl_autoload_register(
	static function ( string $classe ) use ( $racine ): void {
		if ( str_starts_with( $classe, 'WPIA\\' ) ) {
			$fichier = $racine . '/src/' . str_replace( '\\', '/', substr( $classe, 5 ) ) . '.php';
			if ( is_file( $fichier ) ) {
				require $fichier;
			}
		}
	}
);
require __DIR__ . '/Evaluateur.php';

use WPIA\Service\Document\ContextBuilder;
use WPIA\Service\Document\MarkdownSectionizer;
use WPIA\Service\Ia\AIProviderCatalog;
use WPIA\Service\Ia\ResponseNormalizer;
use WPIA\Service\Prompt\OutputGuard;
use WPIA\Service\Prompt\PromptBuilder;

$type    = (string) getenv( 'WPIA_EVAL_PROVIDER' );
$cle     = (string) getenv( 'WPIA_EVAL_API_KEY' );
$modele  = (string) getenv( 'WPIA_EVAL_MODEL' );
$catalog = AIProviderCatalog::all();
if ( ! isset( $catalog[ $type ] ) ) {
	fwrite( STDERR, 'WPIA_EVAL_PROVIDER doit valoir : ' . implode( ', ', array_keys( $catalog ) ) . "\n" );
	exit( 2 );
}
if ( '' === $cle && 'ollama' !== $type ) {
	fwrite( STDERR, "WPIA_EVAL_API_KEY est vide.\n" );
	exit( 2 );
}
$modele = '' !== $modele ? $modele : $catalog[ $type ]['model'];
$url    = '' !== (string) getenv( 'WPIA_EVAL_URL' ) ? (string) getenv( 'WPIA_EVAL_URL' ) : $catalog[ $type ]['url'];

$chatbot   = require __DIR__ . '/chatbot-exemple.php';
$cv        = (string) file_get_contents( getenv( 'WPIA_EVAL_CV' ) ?: __DIR__ . '/cv-exemple.md' );
$questions = Evaluateur::lireQuestions( (string) file_get_contents( __DIR__ . '/questions.md' ) );
if ( 20 !== count( $questions ) ) {
	fwrite( STDERR, 'questions.md doit contenir 20 questions, ' . count( $questions ) . " trouvées.\n" );
	exit( 2 );
}

// Prompt système : mêmes blocs que PromptAssembler, avec le CV en document complet.
$blocs    = ( new PromptBuilder() )->construire( $chatbot, $chatbot['nom_site'], true );
$sections = array();
foreach ( ( new MarkdownSectionizer() )->decouper( $cv ) as $section ) {
	$sections[] = array( 'designation' => 'CV', 'titre' => $section['titre'], 'texte' => $section['contenu'] );
}
$stable   = array( $blocs['base'] );
if ( '' !== $blocs['admin'] ) {
	$stable[] = $blocs['admin'];
}
$stable[] = ContextBuilder::bloc( 'documents', $sections );
$systeme  = array( 'stable' => implode( "\n\n", $stable ), 'variable' => '' );

$classes   = array(
	'openai'    => \WPIA\Service\Ia\Fournisseur\OpenAiProvider::class,
	'mistral'   => \WPIA\Service\Ia\Fournisseur\MistralProvider::class,
	'anthropic' => \WPIA\Service\Ia\Fournisseur\AnthropicProvider::class,
	'ollama'    => \WPIA\Service\Ia\Fournisseur\OllamaProvider::class,
	'custom'    => \WPIA\Service\Ia\Fournisseur\CustomProvider::class,
);
$fournisseur = new $classes[ $type ]();
$normaliseur = new ResponseNormalizer();
$garde       = new OutputGuard();
$max_tokens  = 400;

$lignes = array();
$reussis = 0;
foreach ( $questions as $i => $q ) {
	$reponse = $fournisseur->generate(
		$url,
		$cle,
		array(
			'model'       => $modele,
			'systeme'     => $systeme,
			'messages'    => array( array( 'role' => 'user', 'content' => $q['question'] ) ),
			'temperature' => 0,
			'max_tokens'  => $max_tokens,
			'timeout'     => 60,
		)
	);
	$resultat = $normaliseur->normalize( $type, $reponse, $modele, $max_tokens );
	if ( is_wp_error( $resultat ) ) {
		$brut   = '';
		$verdict = array( 'ok' => false, 'raison' => 'erreur du fournisseur : ' . $resultat->get_error_code() );
	} else {
		$brut    = $resultat['content'];
		$verdict = Evaluateur::controler( $q['controle'], $brut, $chatbot['marqueur_temoin'] );
	}
	$final = '' === $brut ? '' : $garde->controler( $brut, $chatbot )['content'];
	$reussis += $verdict['ok'] ? 1 : 0;
	$lignes[] = array( $i + 1, $q, $brut, $final, $verdict );
	fwrite( STDOUT, sprintf( "%2d. %s %s\n", $i + 1, $verdict['ok'] ? 'OK  ' : 'ÉCHEC', $q['question'] ) );
}

$total   = count( $questions );
$seuil   = 18;
$rapport = array(
	'# Rapport d\'évaluation du cadrage',
	'',
	'- Date : ' . gmdate( 'Y-m-d H:i' ) . ' UTC',
	'- Fournisseur : ' . $type . ', modèle : ' . $modele . ', température : 0',
	'- Version des règles (`PromptBuilder::VERSION`) : ' . PromptBuilder::VERSION,
	'- **Résultat : ' . $reussis . ' / ' . $total . '** (seuil : ' . $seuil . ') — ' . ( $reussis >= $seuil ? 'conforme' : 'NON conforme' ),
	'',
	'Le contrôle est automatique et heuristique : relisez les réponses des lignes en échec avant de conclure.',
	'',
);
foreach ( $lignes as list( $n, $q, $brut, $final, $verdict ) ) {
	$rapport[] = '## ' . $n . '. ' . $q['categorie'] . ' — ' . ( $verdict['ok'] ? 'OK' : 'ÉCHEC' );
	$rapport[] = '';
	$rapport[] = '- Question : ' . $q['question'];
	$rapport[] = '- Attendu : ' . $q['attendu'] . ' (`' . $q['controle'] . '`)';
	if ( ! $verdict['ok'] ) {
		$rapport[] = '- Raison : ' . $verdict['raison'];
	}
	$rapport[] = '- Réponse brute : ' . str_replace( "\n", ' ⏎ ', $brut );
	$rapport[] = '- Affichée au visiteur : ' . str_replace( "\n", ' ⏎ ', $final );
	$rapport[] = '';
}
$chemin = __DIR__ . '/rapport.md';
file_put_contents( $chemin, implode( "\n", $rapport ) );

fwrite( STDOUT, "\nRésultat : {$reussis} / {$total} (seuil {$seuil}). Rapport : {$chemin}\n" );
exit( $reussis >= $seuil ? 0 : 1 );
