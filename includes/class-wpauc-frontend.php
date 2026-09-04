<?php
/**
 * The trigger surface: loader, shell, settings.
 *
 * ⚠ THIS IS THEME-AGNOSTIC and used to live in the Kadence adapter, which was
 * wrong twice over. A modal shell and a click handler have nothing to do with
 * Kadence — and gating them on `wpauc_is_kadence()` meant every other theme got
 * a working endpoint with nothing to call it.
 *
 * WHY THE FALLBACK SHELL IS THE NORMAL PATH, not the exception
 *
 * The first design assumed the page would carry a `kadence/modal` block and
 * MicroModal would drive it. It does not: Kadence enqueues its modal runtime
 * only for pages that actually contain the block, so on an ordinary loop page
 * `window.MicroModal` is undefined and this shell is what runs. It therefore
 * has to be complete on its own — styled, closable by three separate gestures,
 * and correct for assistive technology — rather than a stopgap.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the loader and prints the modal shell.
 */
class WPAUC_Frontend {

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'shell' ), 5 );
	}

	/**
	 * Should the frontend load at all?
	 *
	 * @return bool
	 */
	protected function enabled(): bool {
		/**
		 * Filter whether to ship the trigger surface on this request.
		 *
		 * @param bool $enabled Whether to load.
		 */
		return (bool) apply_filters( 'wpauc/enabled', ! is_admin() );
	}

	/**
	 * Loader plus the shell's structural CSS.
	 *
	 * @return void
	 */
	public function assets(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		wp_enqueue_style(
			'wpauc-modal',
			WPAUC_URL . 'assets/modal.css',
			array(),
			WPAUC_VERSION
		);

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

				/**
				 * Filter the click targets.
				 *
				 * The default is the contract SkialpujFest already uses —
				 * `.popup` stamped on every link to a modal-able post type.
				 *
				 * @param string $selector CSS selector.
				 */
				'trigger'  => apply_filters( 'wpauc/trigger_selector', '.popup a, a.popup' ),

				'i18n'     => array(
					'close' => __( 'Close', 'wp-ajax-url-content' ),
					'error' => __( 'Could not load that content.', 'wp-ajax-url-content' ),
				),
			)
		);
	}

	/**
	 * Print a shell when the page does not provide one.
	 *
	 * The spec calls for ONE shared modal, filled per trigger. A `kadence/modal`
	 * block in the content would do, but a trigger can appear anywhere, and that
	 * would mean editing every template that might host one. Printing the shell
	 * in the footer makes the feature work with no content changes at all.
	 *
	 * ⚠ `aria-hidden` and `hidden` are BOTH set here and both cleared on open.
	 * The first version set them here and cleared only `hidden`, leaving the
	 * open modal announced as hidden — invisible to a screen reader while
	 * perfectly visible on screen.
	 *
	 * @return void
	 */
	public function shell(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		/**
		 * Filter whether to print the fallback shell.
		 *
		 * Return false when the page carries its own — an author's shell is the
		 * one they styled.
		 *
		 * @param bool $print Whether to print it.
		 */
		if ( ! apply_filters( 'wpauc/print_shell', true ) ) {
			return;
		}

		printf(
			'<div class="wpauc-modal" id="wpauc-modal" data-wpauc-modal hidden aria-hidden="true">'
			. '<div class="wpauc-modal__overlay" data-wpauc-close>'
			. '<div class="wpauc-modal__frame" role="dialog" aria-modal="true" aria-label="%1$s" tabindex="-1">'
			. '<button type="button" class="wpauc-modal__close" aria-label="%2$s" data-wpauc-close>&times;</button>'
			. '<div class="wpauc-modal__body" data-wpauc-content></div>'
			. '</div></div></div>',
			esc_attr__( 'Detail', 'wp-ajax-url-content' ),
			esc_attr__( 'Close', 'wp-ajax-url-content' )
		);
	}
}
