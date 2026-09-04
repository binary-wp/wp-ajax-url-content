/**
 * Intercept a trigger, fetch the URL's content, show it in a modal.
 *
 * No jQuery, no build step. The modal shell is whatever the page provides —
 * on Kadence that is a `kadence/modal`, driven through MicroModal.
 */
( function () {
	'use strict';

	var settings = window.wpaucSettings || {};
	var cache = {};

	if ( ! settings.endpoint ) {
		return;
	}

	/**
	 * The modal to fill. One shared shell, filled per trigger — per-type
	 * differences are a CSS problem, not a second modal.
	 */
	function shell() {
		return document.querySelector( '[data-wpauc-modal]' ) ||
			document.querySelector( '.wp-block-kadence-modal' );
	}

	function body( el ) {
		return el.querySelector( '[data-wpauc-content]' ) ||
			el.querySelector( '.kt-modal-container' ) ||
			el;
	}

	/**
	 * Add the assets the content asked for.
	 *
	 * Styles are applied by handle so a second modal does not add them twice.
	 * Scripts are deliberately NOT executed: injected content that runs
	 * arbitrary script is a different security question, and nothing needs it
	 * yet. Inline styles come through because Kadence emits per-block CSS that
	 * way and the content is unstyled without them.
	 */
	function applyAssets( assets ) {
		( ( assets && assets.styles ) || [] ).forEach( function ( style ) {
			var id = 'wpauc-style-' + style.handle;

			if ( document.getElementById( id ) ) {
				return;
			}

			if ( style.src ) {
				var link = document.createElement( 'link' );
				link.id = id;
				link.rel = 'stylesheet';
				link.href = style.src;
				document.head.appendChild( link );
			}

			if ( style.inline ) {
				var tag = document.createElement( 'style' );
				tag.id = id + '-inline';
				tag.textContent = style.inline;
				document.head.appendChild( tag );
			}
		} );
	}

	function open( el ) {
		var id = el.getAttribute( 'id' );

		if ( window.MicroModal && id ) {
			window.MicroModal.show( id );
			return;
		}

		// No MicroModal (or no id): fall back to making it visible, so the
		// content is reachable rather than fetched into a hidden box.
		el.classList.add( 'is-open' );
		el.removeAttribute( 'hidden' );
	}

	function load( url, el ) {
		var target = body( el );

		if ( cache[ url ] ) {
			target.innerHTML = cache[ url ].html;
			applyAssets( cache[ url ].assets );
			open( el );
			return;
		}

		target.setAttribute( 'aria-busy', 'true' );

		fetch( settings.endpoint + '?url=' + encodeURIComponent( url ), {
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'wpauc: ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				cache[ url ] = data;
				target.innerHTML = data.html;
				applyAssets( data.assets );
				target.removeAttribute( 'aria-busy' );
				open( el );

				// Deep links and the back button. Kadence reads the hash on
				// load but never writes it, so that half is ours.
				if ( data.url ) {
					window.history.pushState( { wpauc: url }, '', data.url );
				}
			} )
			.catch( function () {
				target.removeAttribute( 'aria-busy' );

				// The trigger is a real link to a real URL. If the fetch fails,
				// let it behave like one rather than doing nothing.
				window.location.href = url;
			} );
	}

	document.addEventListener(
		'click',
		function ( event ) {
			var trigger = event.target.closest( settings.trigger || '.popup a, a.popup' );

			if ( ! trigger || ! trigger.href ) {
				return;
			}

			// Modified clicks mean "open it properly" — never hijack those.
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button ) {
				return;
			}

			var el = shell();

			if ( ! el ) {
				return; // No modal on this page: navigate as normal.
			}

			event.preventDefault();
			load( trigger.href, el );
		},
		false
	);

	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.wpauc ) {
			return;
		}

		var el = shell();

		if ( el && window.MicroModal && el.getAttribute( 'id' ) ) {
			window.MicroModal.close( el.getAttribute( 'id' ) );
		}
	} );
}() );
