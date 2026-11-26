/**
 * Chat public (issue 007). JavaScript simple, sans build.
 *
 * - Aucun nonce dans le HTML : il est demandé à `GET session` à la première ouverture
 *   (réponse jamais mise en cache), puis renouvelé une seule fois s'il est périmé.
 * - Le texte du visiteur est inséré avec `textContent`. Le HTML des réponses vient du
 *   serveur (déjà filtré) et est filtré de nouveau ici avant insertion.
 * - L'historique reste dans `sessionStorage` : le serveur ne stocke rien (MVP).
 */
( function () {
	'use strict';

	var cfg = window.wpiaChat;
	if ( ! cfg || ! cfg.sessionUrl || ! cfg.chatUrl ) {
		return;
	}
	var T = cfg.textes || {};

	/* ---------- Outils ---------- */

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	var ALLOWED_TAGS = { P: 1, BR: 1, STRONG: 1, EM: 1, UL: 1, OL: 1, LI: 1, CODE: 1, PRE: 1, A: 1 };

	/** Filtre de liste blanche : copie les balises autorisées, le reste devient du texte. */
	function safeFragment( html ) {
		var doc = new DOMParser().parseFromString( '<body>' + html + '</body>', 'text/html' );
		var out = document.createDocumentFragment();

		function copy( from, to ) {
			Array.prototype.forEach.call( from.childNodes, function ( child ) {
				if ( 3 === child.nodeType ) {
					to.appendChild( document.createTextNode( child.nodeValue ) );
				} else if ( 1 === child.nodeType && ALLOWED_TAGS[ child.tagName ] ) {
					var node = document.createElement( child.tagName );
					if ( 'A' === child.tagName ) {
						var href = child.getAttribute( 'href' ) || '';
						if ( /^(https?:\/\/|mailto:)/i.test( href ) ) {
							node.setAttribute( 'href', href );
							node.setAttribute( 'rel', 'nofollow noopener' );
							node.setAttribute( 'target', '_blank' );
						}
					}
					copy( child, node );
					to.appendChild( node );
				} else if ( 1 === child.nodeType && ! /^(SCRIPT|STYLE)$/.test( child.tagName ) ) {
					copy( child, to ); // Balise inconnue : on garde son texte, pas la balise.
				}
			} );
		}

		copy( doc.body, out );
		return out;
	}

	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + ( d.getMonth() + 1 ) + '-' + d.getDate();
	}

	function store( area, key, value ) {
		try {
			if ( null === value ) {
				window[ area ].removeItem( key );
			} else {
				window[ area ].setItem( key, value );
			}
		} catch ( e ) {} // Stockage indisponible (navigation privée) : la conversation reste en mémoire.
	}

	function load( area, key ) {
		try {
			return window[ area ].getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	/** Ajoute un paramètre de requête : `&` si l'URL en a déjà un (permaliens « Simple » : `?rest_route=…`). */
	function withParam( url, key, value ) {
		return url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + encodeURIComponent( key ) + '=' + encodeURIComponent( value );
	}

	function request( method, url, nonce, body ) {
		var headers = { Accept: 'application/json' };
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		return fetch( url, {
			method: method,
			headers: headers,
			credentials: 'same-origin',
			cache: 'no-store',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { status: response.status, data: data || {} };
			} );
		} );
	}

	/* ---------- Un widget ---------- */

	var counter = 0;

	function Chat( root ) {
		var self = this;
		counter += 1;
		this.id = parseInt( root.getAttribute( 'data-wpia-chatbot' ), 10 );
		this.uid = 'wpia-chat-' + counter;
		this.root = root;
		this.config = null;
		this.nonce = '';
		this.messages = []; // { role, text, html? }.
		this.busy = false;
		this.limited = load( 'localStorage', this.limitKey() ) === today();
		this.restore();
		this.build();

		this.toggle.addEventListener( 'click', function () {
			self.open();
		} );
	}

	Chat.prototype.storageKey = function () {
		return 'wpia_chat_' + this.id;
	};

	Chat.prototype.limitKey = function () {
		return 'wpia_chat_limit_' + this.id;
	};

	Chat.prototype.restore = function () {
		try {
			var saved = JSON.parse( load( 'sessionStorage', this.storageKey() ) || '[]' );
			this.messages = Array.isArray( saved ) ? saved.filter( function ( m ) {
				return m && ( 'user' === m.role || 'assistant' === m.role ) && 'string' === typeof m.text;
			} ) : [];
		} catch ( e ) {
			this.messages = [];
		}
	};

	Chat.prototype.save = function () {
		store( 'sessionStorage', this.storageKey(), JSON.stringify( this.messages.slice( -40 ) ) );
	};

	Chat.prototype.build = function () {
		var self = this;

		this.toggle = el( 'button', { type: 'button', 'class': 'wpia-chat__toggle', 'aria-expanded': 'false', 'aria-controls': this.uid + '-panel' }, T.ouvrir );

		this.panel = el( 'div', { 'class': 'wpia-chat__panel', id: this.uid + '-panel', role: 'dialog', 'aria-labelledby': this.uid + '-title', hidden: 'hidden' } );

		var header = el( 'div', { 'class': 'wpia-chat__header' } );
		header.appendChild( el( 'h2', { 'class': 'wpia-chat__title', id: this.uid + '-title' }, T.titre ) );
		this.close = el( 'button', { type: 'button', 'class': 'wpia-chat__close', 'aria-label': T.fermer }, '×' );
		header.appendChild( this.close );

		this.log = el( 'div', { 'class': 'wpia-chat__log', role: 'log', 'aria-live': 'polite', 'aria-label': T.messages, tabindex: '0' } );
		this.suggestions = el( 'div', { 'class': 'wpia-chat__suggestions', role: 'group', 'aria-label': T.suggestions } );
		this.status = el( 'p', { 'class': 'wpia-chat__status', role: 'status' } );

		this.form = el( 'form', { 'class': 'wpia-chat__form' } );
		this.input = el( 'textarea', { 'class': 'wpia-chat__input', rows: '2', 'aria-label': T.saisie, placeholder: T.placeholder } );
		this.send = el( 'button', { type: 'submit', 'class': 'wpia-chat__send' }, T.envoyer );
		this.form.appendChild( this.input );
		this.form.appendChild( this.send );

		var footer = el( 'p', { 'class': 'wpia-chat__footer' }, T.avertissement );
		if ( cfg.confidentialite ) {
			footer.appendChild( document.createTextNode( ' ' ) );
			footer.appendChild( el( 'a', { href: cfg.confidentialite, target: '_blank', rel: 'noopener' }, T.confidentialite ) );
		}

		[ header, this.log, this.suggestions, this.status, this.form, footer ].forEach( function ( node ) {
			self.panel.appendChild( node );
		} );
		this.root.appendChild( this.toggle );
		this.root.appendChild( this.panel );

		this.close.addEventListener( 'click', function () {
			self.shut();
		} );
		this.panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
				self.shut();
			}
		} );
		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.submit( self.input.value );
		} );
		this.input.addEventListener( 'keydown', function ( event ) {
			// Entrée envoie, Maj+Entrée passe à la ligne ; pas pendant une composition (IME).
			if ( 'Enter' === event.key && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				self.submit( self.input.value );
			}
		} );

		this.refreshControls();
	};

	/* ----- Ouverture et fermeture ----- */

	Chat.prototype.open = function () {
		var self = this;
		this.panel.hidden = false;
		this.toggle.setAttribute( 'aria-expanded', 'true' );
		this.root.classList.add( 'is-open' );
		document.documentElement.classList.add( 'wpia-chat-lock' );

		if ( this.config ) {
			this.input.focus();
			return;
		}

		this.setStatus( T.attente );
		this.fetchSession().then( function ( ok ) {
			if ( ! ok ) {
				self.setStatus( T.indisponible );
				return;
			}
			self.setStatus( '' );
			self.input.maxLength = self.config.longueur_max || 500;
			self.renderAll();
			self.input.focus();
		} );
	};

	Chat.prototype.shut = function () {
		this.panel.hidden = true;
		this.toggle.setAttribute( 'aria-expanded', 'false' );
		this.root.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'wpia-chat-lock' );
		this.toggle.focus();
	};

	/** Nonce et configuration : toujours depuis le serveur, jamais depuis la page (cache de page). */
	Chat.prototype.fetchSession = function () {
		var self = this;
		return request( 'GET', withParam( cfg.sessionUrl, 'chatbot', this.id ), '' ).then( function ( result ) {
			if ( 200 !== result.status || ! result.data.nonce ) {
				return false;
			}
			self.config = result.data;
			self.nonce = result.data.nonce;
			return true;
		} ).catch( function () {
			return false;
		} );
	};

	/* ----- Affichage ----- */

	Chat.prototype.renderAll = function () {
		var self = this;
		this.log.textContent = '';
		if ( this.config.accueil ) {
			this.addBubble( 'assistant', this.config.accueil );
		}
		this.messages.forEach( function ( m ) {
			self.addBubble( m.role, m.text, m.html );
		} );
		this.renderSuggestions();
	};

	Chat.prototype.renderSuggestions = function () {
		var self = this;
		this.suggestions.textContent = '';
		if ( this.messages.length > 0 || this.limited ) {
			return;
		}
		( this.config.questions || [] ).forEach( function ( question ) {
			var button = el( 'button', { type: 'button', 'class': 'wpia-chat__suggestion' }, question );
			button.addEventListener( 'click', function () {
				self.submit( question );
			} );
			self.suggestions.appendChild( button );
		} );
	};

	Chat.prototype.addBubble = function ( role, text, html ) {
		var bubble = el( 'div', { 'class': 'wpia-chat__msg wpia-chat__msg--' + role } );
		bubble.appendChild( el( 'span', { 'class': 'wpia-chat__who' }, 'user' === role ? T.vous : T.assistant ) );
		var body = el( 'div', { 'class': 'wpia-chat__body' } );
		if ( 'assistant' === role && html ) {
			body.appendChild( safeFragment( html ) );
		} else {
			body.textContent = text; // Texte du visiteur (ou accueil) : jamais interprété comme du HTML.
		}
		bubble.appendChild( body );
		this.log.appendChild( bubble );
		this.log.scrollTop = this.log.scrollHeight;
		return bubble;
	};

	Chat.prototype.setStatus = function ( text, isError ) {
		this.status.textContent = text || '';
		this.status.classList.toggle( 'is-error', !! isError );
	};

	Chat.prototype.refreshControls = function () {
		var off = this.busy || this.limited;
		this.send.disabled = off;
		this.input.disabled = this.limited;
		this.panel.setAttribute( 'aria-busy', this.busy ? 'true' : 'false' );
		if ( this.limited ) {
			this.setStatus( T.limite, true );
		}
	};

	/* ----- Envoi ----- */

	Chat.prototype.submit = function ( raw ) {
		var self = this;
		var text = String( raw || '' ).trim();
		if ( this.busy || this.limited || ! this.config || '' === text ) {
			return;
		}
		if ( text.length > ( this.config.longueur_max || 500 ) ) {
			this.setStatus( T.erreur_long, true );
			return;
		}

		var max = this.config.historique_actif ? this.config.historique_max : 0;
		var history = max > 0 ? this.messages.slice( -max ).map( function ( m ) {
			return { role: m.role, content: m.text };
		} ) : [];

		this.messages.push( { role: 'user', text: text } );
		this.save();
		this.addBubble( 'user', text );
		this.input.value = '';
		this.suggestions.textContent = '';
		this.setStatus( '' );
		this.busy = true;
		this.refreshControls();
		var waiting = this.addBubble( 'assistant', T.attente );
		waiting.classList.add( 'is-waiting' );

		this.post( text, history, true ).then( function ( result ) {
			self.log.removeChild( waiting );
			self.busy = false;
			self.finish( text, result );
		} ).catch( function () {
			self.log.removeChild( waiting );
			self.busy = false;
			self.finish( text, { status: 0, data: {} } );
		} );
	};

	/** Un seul renvoi transparent si le nonce est périmé (403). */
	Chat.prototype.post = function ( text, history, canRetry ) {
		var self = this;
		return request( 'POST', cfg.chatUrl, this.nonce, { chatbot: this.id, message: text, historique: history } ).then( function ( result ) {
			if ( 403 === result.status && canRetry ) {
				return self.fetchSession().then( function ( ok ) {
					return ok ? self.post( text, history, false ) : result;
				} );
			}
			return result;
		} );
	};

	Chat.prototype.finish = function ( text, result ) {
		if ( 200 === result.status && result.data && 'string' === typeof result.data.texte ) {
			this.messages.push( { role: 'assistant', text: result.data.texte, html: result.data.html || '' } );
			this.save();
			this.addBubble( 'assistant', result.data.texte, result.data.html );
			this.refreshControls();
			this.setStatus( '' );
			this.input.focus();
			return;
		}

		// Échec : la question n'a pas reçu de réponse, on la retire de l'historique.
		this.messages.pop();
		this.save();

		var code = result.data && result.data.code;
		var message = T.erreur_service;
		if ( 429 === result.status && 'wpia_limit_reached' === code ) {
			// Seules les portées valables toute la journée verrouillent le widget ; la limite d'une
			// conversation (portée « session ») n'empêche pas d'en démarrer une autre.
			if ( 'session' === result.data.portee ) {
				message = result.data.message || T.limite;
			} else {
				this.limited = true;
				store( 'localStorage', this.limitKey(), today() );
				message = T.limite;
			}
		} else if ( 429 === result.status ) {
			message = T.erreur_rapide;
		} else if ( 400 === result.status ) {
			message = T.erreur_long;
		} else if ( 403 === result.status ) {
			message = T.erreur_session;
		} else if ( 404 === result.status ) {
			message = T.indisponible;
		}

		this.refreshControls();
		this.setStatus( message, true );
		if ( ! this.limited ) {
			this.input.value = text; // Le visiteur peut renvoyer sans retaper.
			this.input.focus();
		}
		this.log.textContent = '';
		this.renderAll();
	};

	/* ---------- Démarrage ---------- */

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wpia-chat[data-wpia-chatbot]' ), function ( root ) {
			new Chat( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
