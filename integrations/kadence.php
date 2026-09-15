<?php
/**
 * Kadence adapter — the theme surface only.
 *
 * THEME: reconstructing the content region
 *
 * Every Kadence top-level template is the same four lines:
 *
 *     get_header();
 *     kadence()->print_styles( 'kadence-content' );
 *     do_action( 'kadence_single' );      // or kadence_archive
 *     get_footer();
 *
 * So the content region is the MIDDLE TWO. Calling them directly means the
 * header and footer never render — nothing to strip afterwards, and no reliance
 * on the theme marking its main landmark.
 *
 * ⚠ THE FRONTEND IS NOT HERE, and it was, which was a mistake worth naming.
 * The loader and the modal shell are theme-agnostic — `WPAUC_Frontend` owns
 * them. Keeping them here gated a generic feature on `wpauc_is_kadence()`, so
 * every other theme got a working endpoint and nothing to call it.
 *
 * The original reasoning was that `kadence/modal` would supply the shell, the
 * overlay, the focus trap and the scroll lock through MicroModal. It does not:
 * Kadence enqueues that runtime only for pages containing the block, which a
 * loop page does not. So the shell has to stand on its own, and standing on its
 * own has nothing to do with Kadence.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this a Kadence site?
 *
 * Checks the TEMPLATE, not the stylesheet, so a child theme counts.
 *
 * @return bool
 */
function wpauc_is_kadence(): bool {
	// ⚠ The theme's helper is NAMESPACED — `Kadence\kadence()`, not a global.
	// Its own templates call it from inside `namespace Kadence;`, so it reads
	// like a global and is not one. Checking `function_exists('kadence')` is
	// always false and silently disables the whole adapter.
	return 'kadence' === get_template() && function_exists( 'Kadence\\kadence' );
}

add_filter( 'wpauc/render', 'wpauc_kadence_render' );

/**
 * Render the content region the Kadence way.
 *
 * @param string|null $html Rendered content so far.
 * @return string|null
 */
function wpauc_kadence_render( $html ) {
	if ( null !== $html || ! wpauc_is_kadence() ) {
		return $html;
	}

	// Kadence branches its own hook by query type, exactly as its templates do.
	$hook = is_singular() ? 'kadence_single' : 'kadence_archive';

	ob_start();

	// print_styles() is what registers the content stylesheet Kadence expects,
	// and it is inside the region — so the asset diff picks it up.
	\Kadence\kadence()->print_styles( 'kadence-content' );

	do_action( $hook );

	return (string) ob_get_clean();
}

add_action( 'wp_enqueue_scripts', 'wpauc_kadence_reinit_bridge', 20 );

/**
 * Tell Kadence's own scripts to look at the content a modal just loaded.
 *
 * ⚠ THE FRONTEND WARNING ABOVE DOES NOT APPLY HERE, and the distinction
 * matters. What was wrong before was gating the GENERIC feature — the shell,
 * the loader — on `wpauc_is_kadence()`, so other themes got an endpoint they
 * could not use. This is the opposite: a bridge that is meaningless anywhere
 * else, because `kadenceJSInitReload` is Kadence's own event name. Generic
 * behaviour in the plugin, theme-specific translation in the adapter.
 *
 * ⚠ AND IT EXECUTES NOTHING FROM THE RESPONSE. Six of Kadence's front-end
 * scripts already listen for this event so they can rescan after an infinite
 * scroll or a filtered loop — the theme's lightbox, and Blocks' splide,
 * show-more, tippy and search. A modal is the same situation: the DOM grew
 * after those scripts bound to it. Re-dispatching asks code that is already
 * loaded and already trusted to look again.
 *
 * Which of them actually do anything depends on what the site enables — the
 * lightbox, for instance, only enqueues when the Customizer's Lightbox option
 * is on. Nothing here turns those on; it only stops them missing the modal.
 *
 * @return void
 */
function wpauc_kadence_reinit_bridge(): void {
	if ( ! wpauc_is_kadence() || ! wp_script_is( 'wpauc-loader', 'enqueued' ) ) {
		return;
	}

	wp_add_inline_script(
		'wpauc-loader',
		"document.addEventListener('wpauc:loaded',function(){"
			. "document.dispatchEvent(new CustomEvent('kadenceJSInitReload'));"
			. '});'
	);
}
