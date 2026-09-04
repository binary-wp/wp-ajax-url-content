<?php
/**
 * Rendering a URL's content region.
 *
 * @package WP_Ajax_Url_Content
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-runs WordPress for another URL and captures the content region.
 */
class WPAUC_Renderer {

	/**
	 * Render the content at a URL, without page chrome.
	 *
	 * @param string $url Same-origin URL, already validated.
	 * @return string
	 */
	public function render( string $url ): string {
		$this->enter( $url );

		/**
		 * Render the content region for the current query.
		 *
		 * A theme adapter answers this by calling the theme's own content hooks,
		 * so the header and footer never render in the first place. Returning
		 * null falls through to the generic path below.
		 *
		 * @param string|null $html Rendered content.
		 */
		$html = apply_filters( 'wpauc/render', null );

		if ( null === $html ) {
			$html = $this->render_generic();
		}

		$this->leave();

		return $this->tidy( (string) $html );
	}

	/**
	 * Point WordPress at another URL and re-run the query.
	 *
	 * ⚠ This runs long after `wp` has already fired once for the real request,
	 * so anything that hooked `wp`, `template_redirect` or the query filters
	 * runs a SECOND time. That is the price of template fidelity, and it is why
	 * `wpauc/render` exists — an adapter that calls the content hooks directly
	 * avoids re-running the whole template.
	 *
	 * @param string $url Target.
	 * @return void
	 */
	protected function enter( string $url ): void {
		global $wp, $wp_query, $wp_the_query;

		$this->previous = array(
			'uri'   => $_SERVER['REQUEST_URI'] ?? '/',
			'query' => $wp_query,
		);

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$args = (string) wp_parse_url( $url, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = $path . ( $args ? '?' . $args : '' );

		$wp->main( ltrim( $path, '/' ) );

		// $wp->main() replaces the global query; keep the canonical pointer in
		// step or conditional tags disagree with each other.
		$wp_the_query = $wp_query;
	}

	/**
	 * Put the real request's context back.
	 *
	 * Without this the rest of the response — anything hooked after the REST
	 * callback — sees the modal's post as the current one.
	 *
	 * @return void
	 */
	protected function leave(): void {
		global $wp_query, $wp_the_query;

		$_SERVER['REQUEST_URI'] = $this->previous['uri'];

		$wp_query     = $this->previous['query'];
		$wp_the_query = $wp_query;

		wp_reset_postdata();
	}

	/**
	 * The fallback: render the template, then remove the chrome from the HTML.
	 *
	 * Used when no theme adapter claims the site. It is strictly worse than an
	 * adapter — it renders the header and footer only to throw them away, and
	 * it depends on the theme marking its content region — so an adapter is
	 * worth writing for any theme that will see real traffic.
	 *
	 * @return string
	 */
	protected function render_generic(): string {
		$template = apply_filters( 'template_include', get_single_template() );

		if ( ! $template || ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		include $template;
		$html = (string) ob_get_clean();

		return $this->extract_main( $html );
	}

	/**
	 * Pull the content region out of a full page.
	 *
	 * @param string $html Whole document.
	 * @return string
	 */
	protected function extract_main( string $html ): string {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		$doc = new DOMDocument();

		// Suppress the parser's complaints about real-world HTML; the meta
		// forces UTF-8, which libxml otherwise guesses wrong for Czech.
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );
		$found = $xpath->query( '//main | //*[@id="primary"] | //*[@role="main"]' );

		if ( $found && $found->length ) {
			return $this->inner_html( $found->item( 0 ) );
		}

		// No content region marked: take the body and drop the landmarks.
		foreach ( array( 'banner', 'contentinfo', 'navigation' ) as $role ) {
			$nodes = $xpath->query( sprintf( '//*[@role="%s"]', $role ) );

			foreach ( $nodes ? iterator_to_array( $nodes ) : array() as $node ) {
				$node->parentNode?->removeChild( $node );
			}
		}

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );

		return $body ? $this->inner_html( $body ) : $html;
	}

	/**
	 * A node's children as HTML.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	protected function inner_html( DOMNode $node ): string {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Normalise whitespace without touching the markup.
	 *
	 * ⚠ NOT the ancestor's `preg_replace( '/\s+/u', ' ', $content )`. Collapsing
	 * every run of whitespace also collapses it inside `<pre>` — and inside the
	 * inline `<style>` and `<script>` that Kadence emits per block, where a
	 * newline can be load-bearing after a `//` comment.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	protected function tidy( string $html ): string {
		// Non-breaking spaces confuse consumers that trim() the result.
		return trim( str_replace( "\xC2\xA0", ' ', $html ) );
	}

	/**
	 * Saved request context.
	 *
	 * @var array{uri:string,query:WP_Query|null}
	 */
	private array $previous = array( 'uri' => '/', 'query' => null );
}
