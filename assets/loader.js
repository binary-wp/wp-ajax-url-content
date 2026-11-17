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
			return;
		}

		// ⚠ Arrived WITH the hash — a reload while the modal was open, or an old
		// link. There is no entry of ours to step back to, so the hash is
		// dropped in place; without this, closing leaves an address that opens
		// the modal again on the next reload.
		if ( fromHashUrl() ) {
			window.history.replaceState(
				null,
				'',
				window.location.pathname + window.location.search
			);
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
	 * Say that content has landed, so page scripts can look at it.
	 *
	 * ⚠ THIS IS NOT "RUN THE CONTENT'S SCRIPTS", and the difference is the
	 * whole security argument. `applyAssets()` still refuses to execute
	 * anything the response carries. What this does is tell code ALREADY
	 * RUNNING ON THE PAGE that the DOM changed underneath it — the same thing
	 * an infinite-scroll or a filtered loop has to say, and for the same
	 * reason. Nothing new is executed; something already trusted is asked to
	 * look again.
	 *
	 * Without it, anything JS-driven inside a modal is simply dead: bound on
	 * DOMContentLoaded, over a DOM that did not yet contain this content.
	 *
	 * The event is generic on purpose. Mapping it onto whatever a given theme
	 * calls its own re-init signal is an adapter's job, not this file's.
	 *
	 * @param {Element} target Node the content was injected into.
	 * @param {string}  url    URL that was loaded.
	 */
	function announce( target, url ) {
		document.dispatchEvent(
			new CustomEvent( 'wpauc:loaded', {
				bubbles: true,
				detail: { target: target, url: url },
			} )
		);
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
			announce( target, cache[ url ].url || url );
			if ( ! silent ) {
				push( cache[ url ].url || url );
			}
			return;
		}

		// ⚠ OPEN FIRST, THEN FETCH — the order is the whole point.
		//
		// This used to set `aria-busy` here and call `open()` only inside the
		// success handler, so for the entire round trip the shell was still
		// `display:none` and the busy flag sat on an element nobody could see.
		// No stylesheet could express a loading state, because there was
		// nothing on screen to style: the reader clicked and got silence until
		// the content appeared all at once.
		//
		// Opening first costs nothing when the response is quick and is the
		// difference between "broken" and "loading" when it is not.
		if ( ! isOpen( el ) ) {
			open( el );
		}

		// Stale content under a spinner reads as the new post having loaded
		// wrong. Empty it, and let the busy state be the only thing showing.
		target.innerHTML = '';
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

				// Already open — see above. Re-running open() here would only
				// re-focus and re-zero the scroll, which it has to do anyway
				// when the content arrives, so do exactly that much.
				var overlay = el.querySelector( '.wpauc-modal__overlay' );

				if ( overlay ) {
					overlay.scrollTop = 0;
				}

				announce( target, data.url || url );

				if ( ! silent ) {
					push( data.url || url );
				}
			} )
			.catch( function () {
				target.removeAttribute( 'aria-busy' );

				// The trigger is a real link to a real URL. If the fetch fails,
				// let it behave like one rather than doing nothing.
				//
				// Close on the way out: the shell is open now (it opens before
				// the fetch), and leaving an empty modal over the page while
				// the browser navigates is a worse failure than the one that
				// caused it.
				close( el );
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
	/**
	 * The address for an open modal: the page you are ON, plus the post in the
	 * hash.
	 *
	 * ⚠ THE ADDRESS BAR USED TO CLAIM TO BE ON THE DETAIL PAGE, and a reload
	 * made good on the claim. Writing the target's own path meant refreshing
	 * threw the reader onto the detail page as a full page load, losing both the
	 * modal and whatever they had been reading — a list, a cart, a schedule
	 * scrolled halfway down.
	 *
	 * Keeping the host page in the URL and naming the post after a `#` costs
	 * nothing on reload: the browser re-requests the page the reader was already
	 * on, and this file puts the modal back over it. One load, not two.
	 *
	 * ⚠ AND IT WAS TRIED THE OTHER WAY FIRST. Holding the real URL and bouncing
	 * back to the host on reload works, but the browser has already fetched the
	 * post by the time any of this runs — so it costs a SECOND full load with the
	 * detail page visible in between, which is worse than the problem.
	 *
	 * Nothing is lost for sharing or for search: the TRIGGER is still an ordinary
	 * link to the real permalink, and that permalink still renders a real page on
	 * its own. The hash only appears once a modal is open.
	 *
	 * The old site wrote the same shape (`wp_url_to_hash_url()` in astra-ski), so
	 * its links — in e-mails, in content, in bookmarks — keep working.
	 *
	 * @param {string} url Absolute URL of the post being shown.
	 * @return {string|null} Address to write, or null when it cannot be one.
	 */
	function toHashUrl( url ) {
		var a = document.createElement( 'a' );
		a.href = url;

		// Same site only — a hash cannot carry another origin, and a trigger
		// pointing off-site is not ours to intercept.
		if ( a.origin !== window.location.origin ) {
			return null;
		}

		var target = a.pathname.replace( /^\//, '' ) + a.search;

		if ( ! target ) {
			return null;
		}

		return window.location.pathname + window.location.search + '#' + target;
	}

	/**
	 * The post a hash is asking for, if it is asking for one.
	 *
	 * ⚠ A HASH IS USUALLY AN ANCHOR, and anchors must be left alone — `#program`
	 * is a heading on the page. What tells them apart is SHAPE: a post's address
	 * here is always a permalink path, which is at least two non-empty segments
	 * (`workshop/pedro-ve-svete`). An anchor is one word.
	 *
	 * Two segments rather than merely "contains a slash": the looser test also
	 * matches `#section/`, which is an anchor with a stray slash, and would have
	 * sent a fetch after a post that does not exist.
	 *
	 * @return {string|null} Absolute URL, or null when the hash is an anchor.
	 */
	function fromHashUrl() {
		var hash = window.location.hash.slice( 1 );

		if ( ! hash ) {
			return null;
		}

		var path = hash.split( '?' )[ 0 ];
		var segments = path.split( '/' ).filter( function ( part ) {
			return '' !== part;
		} );

		if ( segments.length < 2 ) {
			return null;
		}

		return window.location.origin + '/' + hash.replace( /^\//, '' );
	}

	function push( url ) {
		if ( ! url || false === settings.deepLink ) {
			return;
		}

		var address = toHashUrl( url );

		if ( ! address ) {
			return;
		}

		// ⚠ The STATE keeps the REAL url, not the hash one. popstate reloads
		// from it, and `load()` wants a permalink to fetch, not an address.
		var current = window.history.state;

		if ( current && current.wpauc ) {
			window.history.replaceState(
				{ wpauc: url, from: current.from },
				'',
				address
			);
			return;
		}

		window.history.pushState(
			{ wpauc: url, from: window.location.href },
			'',
			address
		);
	}

	// ⚠ CAPTURE, NOT BUBBLE — the third argument is load-bearing. A host page
	// is free to stop a click before it reaches `document`, and one does:
	// Conference Scheduler binds
	//
	//     .on( 'click', '.workshop a, …', function ( e ) { e.stopPropagation(); } )
	//
	// so that clicking a link inside a workshop row does not also toggle the
	// row's detail panel. Reasonable for the plugin, fatal for a delegated
	// listener sitting on `document`: every workshop link in the schedule
	// navigated to its own page while every other `.popup` link on the same page
	// opened the modal, which reads as the modal being broken for one post type.
	//
	// The capture phase runs on the way DOWN, before any handler on the target,
	// so nothing between the link and the document can hide the click from us.
	// That is the right phase for this listener anyway: intercepting `.popup`
	// links is its whole job, and it should not depend on what a theme or
	// another plugin does with the same event.
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
		true
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

		// ⚠ A LINK TO A HASH ADDRESS IS A HISTORY MOVE TOO, and it carries no
		// state. Content links are written the old site's way —
		// `/#workshop/dolu-z-kopce/` in a speaker's bio — and on the page that
		// already shows the modal, following one is a same-document navigation:
		// no reload, so `restoreFromHash()` never runs, and the browser reports
		// it here with `state === null`. Read as "stepped off a modal" it CLOSED
		// the window and left the address pointing at a post nobody opened.
		// The hash is the request, exactly as on a reload. `silent` — the
		// browser has already written the entry.
		var url = fromHashUrl();

		if ( url ) {
			load( url, el, true );
			return;
		}

		close( el );
	} );

	/**
	 * A modal that was open before a reload is open after it.
	 *
	 * ⚠ THE OTHER HALF OF THE HASH, and without it the hash is only cosmetic:
	 * the reader would land back on the right page with the modal gone, which is
	 * a different bug rather than a fix.
	 *
	 * `silent` — the address already says this. Writing it again would push a
	 * duplicate entry and make the first Back press appear to do nothing.
	 */
	function restoreFromHash() {
		var url = fromHashUrl();
		var el = url && shell();

		if ( el ) {
			load( url, el, true );
		}
	}

	// The shell prints in the footer, so on a slow document this file can run
	// before it exists.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', restoreFromHash );
	} else {
		restoreFromHash();
	}
}() );
