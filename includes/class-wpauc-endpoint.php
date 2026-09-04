<?php
/**
 * The REST route.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves rendered URL content.
 */
class WPAUC_Endpoint {

	public const NAMESPACE = 'wpauc/v1';
	public const ROUTE     = '/content';

	/**
	 * Hook the route up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Declare the route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'handle' ),

				// Public, because the content it serves is public — and it
				// serves nothing else: see resolve(), which refuses anything
				// that is not a publicly viewable post. A capability check here
				// would only break the anonymous visitor this exists for.
				'permission_callback' => '__return_true',

				'args' => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Resolve, render, respond.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$url  = (string) $request->get_param( 'url' );
		$post = $this->resolve( $url );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$renderer = new WPAUC_Renderer();
		$assets   = new WPAUC_Assets();

		$assets->before();
		$html = $renderer->render( $url );
		$new  = $assets->after();

		if ( '' === trim( $html ) ) {
			return new WP_Error(
				'wpauc_empty',
				__( 'That URL rendered nothing.', 'wp-ajax-url-content' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Filter the payload before it is sent.
		 *
		 * @param array   $payload Response body.
		 * @param WP_Post $post    The resolved post.
		 */
		return new WP_REST_Response(
			apply_filters(
				'wpauc/payload',
				array(
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'url'     => get_permalink( $post ),
					'html'    => $html,
					'assets'  => $new,
				),
				$post
			),
			200
		);
	}

	/**
	 * The post a URL points at, if this endpoint may serve it.
	 *
	 * ⚠ THIS IS THE WHOLE SECURITY MODEL, and the ancestor plugin only had half
	 * of it. `elementor-popup-ajax-loader` checked that the host matched and
	 * then rendered whatever came back — so a draft's or a private post's URL
	 * was rendered to anyone who asked, and a URL resolving to nothing rendered
	 * the site's 404 template into a modal.
	 *
	 * Three gates:
	 *
	 *   1. same origin — an off-site URL is not ours to render
	 *   2. it resolves to a real post
	 *   3. that post is publicly viewable — which covers status, post-type
	 *      visibility and password protection in one call
	 *
	 * @param string $url Requested URL.
	 * @return WP_Post|WP_Error
	 */
	protected function resolve( string $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host || 0 !== strcasecmp( (string) $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return new WP_Error(
				'wpauc_external',
				__( 'Only URLs on this site can be loaded.', 'wp-ajax-url-content' ),
				array( 'status' => 403 )
			);
		}

		$post_id = url_to_postid( $url );

		if ( ! $post_id ) {
			return new WP_Error(
				'wpauc_unresolved',
				__( 'That URL does not point at a post.', 'wp-ajax-url-content' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! is_post_publicly_viewable( $post ) ) {
			return new WP_Error(
				'wpauc_not_public',
				__( 'That content is not publicly viewable.', 'wp-ajax-url-content' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Filter whether a resolved post may be served.
		 *
		 * The place to narrow this to specific post types, which most sites
		 * will want — a modal is for detail views, not for every page.
		 *
		 * @param bool    $allowed Whether to serve it.
		 * @param WP_Post $post    Resolved post.
		 */
		if ( ! apply_filters( 'wpauc/allow_post', true, $post ) ) {
			return new WP_Error(
				'wpauc_not_allowed',
				__( 'That content is not available this way.', 'wp-ajax-url-content' ),
				array( 'status' => 403 )
			);
		}

		return $post;
	}
}
