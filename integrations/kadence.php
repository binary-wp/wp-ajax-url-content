<?php
/**
 * Kadence adapter — theme surface and frontend surface.
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
 * FRONTEND: which modal to drive
 *
 * `kadence/modal` is MicroModal underneath. It gives the shell, the overlay,
 * the focus trap, the scroll lock and hash deep-linking; it has no notion of
 * loading another URL. That split is a good one — the shell is the tedious,
 * easy-to-get-wrong part, and the loading is ours.
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

add_action( 'wp_enqueue_scripts', 'wpauc_kadence_frontend' );

/**
 * Ship the loader, told where the endpoint is and which modal to fill.
 *
 * @return void
 */
function wpauc_kadence_frontend(): void {
	if ( ! wpauc_is_kadence() ) {
		return;
	}

	wp_enqueue_script(
		'wpauc-loader',
		WPAUC_URL . 'assets/loader.js',
		array(),
		WPAUC_VERSION,
		true
	);

	wp_localize_script(
		'wpauc-loader',
		'wpaucSettings',
		array(
			'endpoint' => rest_url( WPAUC_Endpoint::NAMESPACE . WPAUC_Endpoint::ROUTE ),

			// The trigger contract the site already uses. SkialpujFest stamps
			// `.popup` + `data-id` onto every link to a modal-able post type.
			'trigger'  => apply_filters( 'wpauc/trigger_selector', '.popup a, a.popup' ),
		)
	);
}

add_action( 'wp_footer', 'wpauc_kadence_shell', 5 );

/**
 * Put a modal shell on the page when the content does not provide one.
 *
 * The spec calls for ONE shared modal, filled per trigger. A `kadence/modal`
 * block placed in the content would do, but that means editing every template
 * that might host a trigger — and a trigger can appear anywhere. Printing a
 * shell in the footer makes the feature work with no content changes at all.
 *
 * If the page already carries a modal (a `kadence/modal` block, or anything
 * marked `data-wpauc-modal`), that one wins and this prints nothing — the
 * author's shell is the one they styled.
 *
 * Deliberately plain: `hidden` until opened, `aria-modal`, a labelled close
 * button. MicroModal drives it when Kadence's runtime is on the page; the
 * loader falls back to toggling `hidden` when it is not, so the content is
 * always reachable.
 *
 * @return void
 */
function wpauc_kadence_shell(): void {
	if ( ! wpauc_is_kadence() || is_admin() ) {
		return;
	}

	/**
	 * Filter whether to print the fallback shell.
	 *
	 * @param bool $print Whether to print it.
	 */
	if ( ! apply_filters( 'wpauc/print_shell', true ) ) {
		return;
	}

	printf(
		'<div class="wpauc-modal" id="wpauc-modal" data-wpauc-modal hidden aria-hidden="true">'
		. '<div class="wpauc-modal__overlay" data-micromodal-close>'
		. '<div class="wpauc-modal__frame" role="dialog" aria-modal="true" aria-label="%1$s">'
		. '<button class="wpauc-modal__close" aria-label="%2$s" data-micromodal-close>&times;</button>'
		. '<div class="wpauc-modal__body" data-wpauc-content></div>'
		. '</div></div></div>',
		esc_attr__( 'Detail', 'wp-ajax-url-content' ),
		esc_attr__( 'Close', 'wp-ajax-url-content' )
	);
}
