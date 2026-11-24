<?php

namespace WPIA\Service;

/**
 * Convertisseur Markdown → HTML minimal pour les réponses du chatbot (issue 007).
 *
 * Gère : paragraphes, retours à la ligne, **gras**, *italique*, `code`, blocs de code
 * (```), listes à puces et numérotées (sans imbrication), titres (rendus en gras) et
 * liens `[texte](url)`.
 *
 * Sécurité : tout le texte est échappé (`htmlspecialchars`) AVANT d'être balisé, donc
 * aucun HTML venant du modèle ne survit. Seuls les liens `http`, `https` et `mailto`
 * sont conservés, avec `rel` et `target` imposés ; sinon seul le texte du lien reste.
 * Le contrôleur applique en plus `wp_kses()` avec la liste blanche de l'issue 007
 * (ceinture et bretelles). Classe pure, sans WordPress, sans dépendance.
 */
final class MarkdownRenderer {
	private const NUL = "\0";

	/** @var array<int, string> Fragments HTML déjà prêts, protégés du reste du traitement. */
	private array $fragments = array();

	public function render( string $markdown ): string {
		$this->fragments = array();
		$markdown        = str_replace( array( "\r\n", "\r", self::NUL ), array( "\n", "\n", '' ), $markdown );
		$lines           = explode( "\n", $markdown );

		$html      = array();
		$paragraph = array();
		$list      = null; // array{tag: string, items: string[]}|null.
		$code      = null; // string[]|null : lignes du bloc de code ouvert.

		$flush_paragraph = function () use ( &$paragraph, &$html ): void {
			if ( array() !== $paragraph ) {
				$html[]    = '<p>' . implode( '<br>', array_map( array( $this, 'inline' ), $paragraph ) ) . '</p>';
				$paragraph = array();
			}
		};
		$flush_list      = function () use ( &$list, &$html ): void {
			if ( null !== $list ) {
				$html[] = '<' . $list['tag'] . '><li>' . implode( '</li><li>', $list['items'] ) . '</li></' . $list['tag'] . '>';
				$list   = null;
			}
		};

		foreach ( $lines as $line ) {
			if ( null !== $code ) {
				if ( preg_match( '/^\s*```\s*$/', $line ) ) {
					$html[] = '<pre><code>' . $this->escape( implode( "\n", $code ) ) . '</code></pre>';
					$code   = null;
				} else {
					$code[] = $line;
				}
				continue;
			}

			if ( preg_match( '/^\s*```/', $line ) ) {
				$flush_paragraph();
				$flush_list();
				$code = array();
				continue;
			}

			if ( '' === trim( $line ) ) {
				$flush_paragraph();
				$flush_list();
				continue;
			}

			if ( preg_match( '/^\s*(?:[-*+]|(\d{1,9})[.)])\s+(.*)$/', $line, $m ) ) {
				$tag = '' !== $m[1] ? 'ol' : 'ul';
				$flush_paragraph();
				if ( null !== $list && $list['tag'] !== $tag ) {
					$flush_list();
				}
				$list          = $list ?? array(
					'tag'   => $tag,
					'items' => array(),
				);
				$list['items'][] = $this->inline( $m[2] );
				continue;
			}

			if ( preg_match( '/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/', $line, $m ) ) {
				$flush_paragraph();
				$flush_list();
				$html[] = '<p><strong>' . $this->inline( $m[1] ) . '</strong></p>';
				continue;
			}

			$flush_list();
			$paragraph[] = trim( $line );
		}

		if ( null !== $code ) { // Bloc de code jamais refermé : on le ferme.
			$html[] = '<pre><code>' . $this->escape( implode( "\n", $code ) ) . '</code></pre>';
		}
		$flush_paragraph();
		$flush_list();

		return implode( "\n", $html );
	}

	private function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private function protect( string $html ): string {
		$this->fragments[] = $html;

		return self::NUL . ( count( $this->fragments ) - 1 ) . self::NUL;
	}

	private function restore( string $text ): string {
		// Boucle : le texte d'un lien peut contenir un fragment (code) déjà protégé.
		$guard = 0;
		while ( false !== strpos( $text, self::NUL ) && $guard++ < 5 ) {
			$text = (string) preg_replace_callback(
				'/\0(\d+)\0/',
				fn( array $m ): string => $this->fragments[ (int) $m[1] ] ?? '',
				$text
			);
		}

		return $text;
	}

	private function inline( string $text ): string {
		// 1. Code en ligne : protégé avant tout (son contenu n'est pas interprété).
		$text = (string) preg_replace_callback(
			'/`([^`]+)`/',
			fn( array $m ): string => $this->protect( '<code>' . $this->escape( $m[1] ) . '</code>' ),
			$text
		);

		// 2. Tout le reste est échappé.
		$text = $this->escape( $text );

		// 3. Liens : le résultat est protégé, l'URL n'est donc pas touchée par l'emphase.
		$text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( array $m ): string {
				$label = $this->emphasis( $m[1] );
				$url   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				if ( ! preg_match( '#^(?:https?://|mailto:)[^\s\x00-\x1f]+$#i', $url ) ) {
					return $label;
				}

				return $this->protect(
					'<a href="' . $this->escape( $url ) . '" rel="nofollow noopener" target="_blank">' . $label . '</a>'
				);
			},
			$text
		);

		return $this->restore( $this->emphasis( $text ) );
	}

	private function emphasis( string $text ): string {
		$text = (string) preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text );

		return (string) preg_replace( '/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![*\w])/', '<em>$1</em>', $text );
	}
}
