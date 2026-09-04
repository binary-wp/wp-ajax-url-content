<?php
/**
 * Plugin Name: WP AJAX URL Content
 * Description: Serves the rendered content of any URL on this site without page chrome, plus the assets that content needs. Platform-independent core; theme and frontend behaviour come from adapters.
 * Version:     0.2.2
 * Author:      wasilosos
 * License:     GPL-2.0-or-later
 * Text Domain: wp-ajax-url-content
 * Requires PHP: 8.1
 *
 * WHAT THIS IS FOR
 *
 * A modal that shows another page's content. Click a workshop, a speaker, a
 * venue or an FAQ, and its detail view opens in place instead of navigating.
 *
 * The generic half is: *give me the content of this URL without the header and
 * footer, plus the assets it needs*. That question has nothing to do with
 * Elementor, Kadence, Astra or any modal library, which is why it lives in a
 * plugin of its own rather than inside a brand plugin — see
 * docs/task-dynamic-popup.md in the SkialpujFest project.
 *
 * HOW IT GETS THE CONTENT
 *
 * Not by fetching itself over HTTP. It re-runs WordPress against the target URL
 * in-process — rewrite `REQUEST_URI`, `$wp->main()`, resolve the template
 * through the full hierarchy, render into a buffer. That keeps template
 * fidelity: whatever the site would show at that URL is what comes back.
 *
 * Chrome is RECONSTRUCTED, not stripped. A theme adapter says how to render the
 * content region — for Kadence that is the two lines between `get_header()` and
 * `get_footer()` — so header and footer never render at all. Stripping them out
 * of finished HTML afterwards is the fallback, not the design.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAUC_VERSION', '0.2.2' );
define( 'WPAUC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPAUC_URL', plugin_dir_url( __FILE__ ) );

require_once WPAUC_PATH . 'includes/class-wpauc-endpoint.php';
require_once WPAUC_PATH . 'includes/class-wpauc-renderer.php';
require_once WPAUC_PATH . 'includes/class-wpauc-assets.php';
require_once WPAUC_PATH . 'includes/class-wpauc-frontend.php';

/**
 * Load whichever integrations apply to this site.
 *
 * Adapters are additive and independent: a site can run Astra with Kadence
 * Blocks, or Kadence with Elementor, and each surface answers its own question.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		foreach ( (array) glob( WPAUC_PATH . 'integrations/*.php' ) as $integration ) {
			require_once $integration;
		}

		( new WPAUC_Endpoint() )->register();
		( new WPAUC_Frontend() )->register();
	}
);
