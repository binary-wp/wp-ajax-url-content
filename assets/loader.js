/**
 * Intercept a trigger, fetch the URL's content, show it in a modal.
 *
 * No jQuery, no build step.
 *
 * ⚠ MicroModal is USUALLY NOT THERE. Kadence enqueues its modal runtime only
 * for pages that contain a `kadence/modal` block, so on an ordinary loop page
 * this file owns opening, closing, focus and the scroll lock itself. The first
 * version delegated all of that to MicroModal and shipped `data-micromodal-close`
 * attributes that nothing was listening to — the modal opened and could not be
 * closed by any gesture.
 */
( function () {
	'use strict';

	var settings = window.wpaucSettings || {};
	var cache = {};
	var returnFocusTo = null;

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

	function isOpen( el ) {
		return el.classList.contains( 'is-open' );
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

		if ( window.MicroModal && id && el.classList.contains( 'wp-block-kadence-modal' ) ) {
			window.MicroModal.show( id );
			return;
		}

		returnFocusTo = document.activeElement;

		el.hidden = false;
		el.classList.add( 'is-open' );
		el.setAttribute( 'aria-hidden', 'false' );
		document.documentElement.classList.add( 'wpauc-modal-open' );

		// ⚠ preventScroll, and reset the overlay by hand. Focusing an element
		// taller than the viewport makes the browser scroll it flush to the top
		// of its scroll container — eating the overlay's top gutter, so a long
		// answer opens already scrolled a little way in.
		var frame = el.querySelector( '[role="dialog"]' ) || el;
		frame.focus( { preventScroll: true } );

		var overlay = el.querySelector( '.wpauc-modal__overlay' );

		if ( overlay ) {
			overlay.scrollTop = 0;
		}
	}

	/**
	 * Put the address bar back where it was.
	 *
	 * ⚠ Only when the entry we are sitting on is one WE pushed. A blind
	 * `history.back()` on close walks off the site entirely when the modal was
	 * opened without pushing — the cached-content path, or a page entered
	 * directly at the detail URL.
	 */
	function restoreUrl() {
		if ( window.history.state && window.history.state.wpauc ) {
			window.history.back();
		}
	}

	function close( el ) {
		el = el || shell();

		if ( ! el || ! isOpen( el ) ) {
			return;
		}

		var id = el.getAttribute( 'id' );

		if ( window.MicroModal && id && el.classList.contains( 'wp-block-kadence-modal' ) ) {
			window.MicroModal.close( id );
			return;
		}

		el.classList.remove( 'is-open' );
		el.setAttribute( 'aria-hidden', 'true' );
		el.hidden = true;
		document.documentElement.classList.remove( 'wpauc-modal-open' );

		if ( returnFocusTo && returnFocusTo.focus ) {
			returnFocusTo.focus();
			returnFocusTo = null;
		}
	}

	/**
	 * @param {string}  url    Target.
	 * @param {Element} el     Shell.
	 * @param {boolean} silent Leave the address bar alone — set when the load
	 *                         was itself caused by a history move.
	 */
	function load( url, el, silent ) {
		var target = body( el );

		if ( cache[ url ] ) {
			target.innerHTML = cache[ url ].html;
			applyAssets( cache[ url ].assets );
			open( el );
			if ( ! silent ) {
				push( cache[ url ].url || url );
			}
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
				if ( ! silent ) {
					push( data.url || url );
				}
			} )
			.catch( function () {
				target.removeAttribute( 'aria-busy' );

				// The trigger is a real link to a real URL. If the fetch fails,
				// let it behave like one rather than doing nothing.
				window.location.href = url;
			} );
	}

	/**
	 * Deep links and the back button.
	 *
	 * The entry the modal replaced is remembered so closing can step back to it
	 * — otherwise the address bar keeps claiming to be on the detail page after
	 * the modal is gone.
	 *
	 * ⚠ Opening a SECOND modal while one is open REPLACES rather than pushes.
	 * Pushing would bury the page the reader came from one entry deeper per
	 * question opened, so closing after browsing five FAQs would step back
	 * through five detail URLs instead of returning to the list. The earlier
	 * version bailed out entirely in this case, which left the address bar
	 * showing the first question while the modal showed the second.
	 */
	function push( url ) {
		if ( ! url ) {
			return;
		}

		var current = window.history.state;

		if ( current && current.wpauc ) {
			window.history.replaceState(
				{ wpauc: url, from: current.from },
				'',
				url
			);
			return;
		}

		window.history.pushState(
			{ wpauc: url, from: window.location.href },
			'',
			url
		);
	}

	document.addEventListener(
		'click',
		function ( event ) {
			var el;

			// Close first: a close control inside the modal must not be read as
			// a trigger. The overlay IS a close control, and it wraps the frame
			// — so it only counts when the click landed on the overlay itself,
			// never when it bubbled up from the content.
			var closer = event.target.closest( 'button[data-wpauc-close]' ) ||
				( event.target.hasAttribute && event.target.hasAttribute( 'data-wpauc-close' )
					? event.target
					: null );

			if ( closer ) {
				event.preventDefault();
				close( closer.closest( '[data-wpauc-modal]' ) );
				restoreUrl();
				return;
			}

			var trigger = event.target.closest( settings.trigger || '.popup a, a.popup' );

			if ( ! trigger || ! trigger.href ) {
				return;
			}

			// Modified clicks mean "open it properly" — never hijack those.
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button ) {
				return;
			}

			el = shell();

			if ( ! el ) {
				return; // No modal on this page: navigate as normal.
			}

			event.preventDefault();
			load( trigger.href, el );
		},
		false
	);

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}

		var el = shell();

		if ( el && isOpen( el ) ) {
			close( el );
			restoreUrl();
		}
	} );

	window.addEventListener( 'popstate', function ( event ) {
		var el = shell();

		if ( ! el ) {
			return;
		}

		// Stepping BACK onto a modal state re-opens it; stepping off one closes.
		if ( event.state && event.state.wpauc ) {
			load( event.state.wpauc, el, true );
			return;
		}

		close( el );
	} );
}() );
