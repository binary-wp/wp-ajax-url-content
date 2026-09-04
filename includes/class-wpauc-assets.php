<?php
/**
 * Working out which assets the rendered content needs.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diffs the enqueue registry around a render.
 *
 * WHY A DIFF RATHER THAN A LIST
 *
 * The ancestor plugin hand-rolled this: force-fire `wp_enqueue_scripts`, read
 * `wp_styles()->registered`, hardcode a few base handles, then regex the HTML
 * for `data-elementor-id` to guess the per-document stylesheet. Every part of
 * that knows about Elementor, and none of it survives a change of builder.
 *
 * Snapshotting the registry either side of the render answers the same question
 * without knowing anything: whatever appeared is, by definition, what the
 * content asked for. Kadence Blocks goes through `wp_enqueue_style()` and
 * `wp_add_inline_style()`, both of which land in the registry, so it works for
 * free.
 *
 * What a diff cannot see is an asset created lazily rather than enqueued —
 * Elementor's per-document CSS files are written on demand — so an adapter can
 * add to the result through `wpauc/assets`.
 */
class WPAUC_Assets {

	/** @var array{styles:string[],scripts:string[]} */
	private array $before = array( 'styles' => array(), 'scripts' => array() );

	/**
	 * Snapshot what is already queued.
	 *
	 * @return void
	 */
	public function before(): void {
		$this->establish_baseline();

		$this->before = array(
			'styles'  => $this->queued( wp_styles() ),
			'scripts' => $this->queued( wp_scripts() ),
		);
	}

	/**
	 * Let the site queue its global assets before the snapshot is taken.
	 *
	 * ⚠ WITHOUT THIS THE DIFF IS MEANINGLESS. A REST request never fires
	 * `wp_enqueue_scripts`, so nothing is queued when `before()` runs and the
	 * diff afterwards returns EVERYTHING the render touched — measured here:
	 * 45 stylesheets and 61 scripts for one FAQ answer, including WP Event
	 * Manager, WooCommerce, Elementor and the emoji styles. None of that is
	 * what the content needs; it is what the site loads on every page, and the
	 * page hosting the modal already has it.
	 *
	 * Firing the lifecycle first puts the globals in the baseline, so the diff
	 * returns what the CONTENT added and nothing else.
	 *
	 * @return void
	 */
	private function establish_baseline(): void {
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			return;
		}

		// wp_enqueue_scripts listeners expect the front-end query flags to make
		// sense; this runs before the renderer switches context, so they see
		// the site's home state, which is the right baseline.
		do_action( 'wp_enqueue_scripts' );
	}

	/**
	 * What the render added.
	 *
	 * @return array{styles:array,scripts:array}
	 */
	public function after(): array {
		$styles  = array_diff( $this->queued( wp_styles() ), $this->before['styles'] );
		$scripts = array_diff( $this->queued( wp_scripts() ), $this->before['scripts'] );

		$assets = array(
			'styles'  => $this->describe( wp_styles(), $styles, 'after' ),
			'scripts' => $this->describe( wp_scripts(), $scripts, 'data' ),
		);

		/**
		 * Filter the collected assets.
		 *
		 * For anything a registry diff cannot see.
		 *
		 * @param array $assets Collected assets.
		 */
		return (array) apply_filters( 'wpauc/assets', $assets );
	}

	/**
	 * Handles currently queued or already printed.
	 *
	 * `done` matters as much as `queue`: an asset printed during the render is
	 * exactly one the content needed, and it has already left the queue by the
	 * time we look.
	 *
	 * @param WP_Dependencies $deps Registry.
	 * @return string[]
	 */
	private function queued( $deps ): array {
		if ( ! $deps instanceof WP_Dependencies ) {
			return array();
		}

		return array_values( array_unique( array_merge( (array) $deps->queue, (array) $deps->done ) ) );
	}

	/**
	 * Turn handles into something a browser can act on.
	 *
	 * @param WP_Dependencies $deps    Registry.
	 * @param string[]        $handles Handles.
	 * @param string          $inline  Data key holding inline content.
	 * @return array<int,array{handle:string,src:string,inline:string}>
	 */
	private function describe( $deps, array $handles, string $inline ): array {
		$out = array();

		foreach ( $handles as $handle ) {
			$item = $deps->registered[ $handle ] ?? null;

			if ( ! $item ) {
				continue;
			}

			$src = (string) ( $item->src ?? '' );

			// Relative srcs are relative to the site root, not to whatever page
			// the modal happens to be open on.
			if ( '' !== $src && ! preg_match( '#^(https?:)?//#', $src ) ) {
				$src = site_url( $src );
			}

			$extra = $deps->get_data( $handle, $inline );

			$out[] = array(
				'handle' => $handle,
				'src'    => $src ? add_query_arg( 'ver', $item->ver ?: null, $src ) : '',
				'inline' => is_array( $extra ) ? implode( "\n", $extra ) : (string) $extra,
			);
		}

		return $out;
	}
}
