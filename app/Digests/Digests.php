<?php
/**
 * This file contains the main initialization for digest formats.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Formats\Narrative_Format;
use ThreadiNowPages\Digests\Formats\Verbatim_Format;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Plugin\Log;
use WP_Error;

/**
 * Object to initialize the digest formats.
 */
class Digests implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Registered formats, keyed by slug.
	 *
	 * @var array<string,Digest_Format_Interface>
	 */
	private array $formats = array();

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'threadi_now_pages_digest_error', array( $this, 'log_error' ), 10, 3 );

		// register our own, built-in formats.
		$this->register_format( new Verbatim_Format() );
		$this->register_format( new Narrative_Format() );

		$interface = $this;
		/**
		 * Fires once the built-in digest formats are registered.
		 *
		 * Use this to add further formats from your own code, without
		 * touching this plugin:
		 * add_action( 'threadi_now_pages_register_digest_formats', function ( $formats ) {
		 *     $formats->register_format( new My_Format() );
		 * } );
		 *
		 * @param Digests $interface The digests object.
		 */
		do_action( 'threadi_now_pages_register_digest_formats', $interface );
	}

	/**
	 * Register a single format.
	 *
	 * @param Digest_Format_Interface $format The format to register.
	 *
	 * @return void
	 */
	public function register_format( Digest_Format_Interface $format ): void {
		$this->formats[ $format->get_slug() ] = $format;
	}

	/**
	 * Return all registered formats.
	 *
	 * @return array<string,Digest_Format_Interface>
	 */
	public function get_formats(): array {
		return $this->formats;
	}

	/**
	 * Return a single format by its slug.
	 *
	 * @param string $slug The format slug.
	 *
	 * @return Digest_Format_Interface|null
	 */
	public function get_format( string $slug ): ?Digest_Format_Interface {
		return $this->formats[ $slug ] ?? null;
	}

	/**
	 * Log a failed narrative summary.
	 *
	 * @param WP_Error $error           The resulting error.
	 * @param string   $summarizer_slug The summarizer which failed.
	 * @param string   $label           Category label, empty if ungrouped.
	 *
	 * @return void
	 */
	public function log_error( WP_Error $error, string $summarizer_slug, string $label ): void {
		// log the same error only once per hour: it is reported on every page view showing the digest.
		$throttle_key = 'threadi_now_pages_digest_error_' . md5( $summarizer_slug . '|' . $error->get_error_code() . '|' . $label );
		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		Log::get_instance()->add(
			sprintf(
			/* translators: %1$s will be replaced by a summarizer slug, %2$s by a category label. */
				__( 'The summarizer %1$s failed for the block %2$s:', 'threadi-now-pages' ),
				'<code>' . esc_html( $summarizer_slug ) . '</code>',
				'<code>' . esc_html( '' !== $label ? $label : __( 'ungrouped', 'threadi-now-pages' ) ) . '</code>'
			) . ' <code>' . esc_html( $error->get_error_message() ) . '</code>',
			'error',
			'system'
		);
	}

	/**
	 * Return the periods a digest can cover.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	public function get_periods(): array {
		return Periods::get_instance()->get_periods_for_settings();
	}

	/**
	 * Return the HTML a rendered digest may contain.
	 *
	 * Page builders often run the output through wp_kses_post(), which
	 * would drop the data attributes our formats add for debugging.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public function get_allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['ul']   = array( 'class' => true );
		$allowed['li']   = array(
			'class'       => true,
			'data-entry'  => true,
			'data-source' => true,
		);
		$allowed['time'] = array(
			'class'    => true,
			'datetime' => true,
		);
		$allowed['cite'] = array( 'class' => true );
		$allowed['p']    = array(
			'class'        => true,
			'data-entries' => true,
		);

		/**
		 * Filter the HTML a rendered digest may contain.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,array<string,bool>> $allowed The allowed tags and attributes.
		 */
		return apply_filters( 'threadi_now_pages_digest_allowed_html', $allowed );
	}

	/**
	 * Return the period a new widget should start with.
	 *
	 * @return string
	 */
	public function get_default_period(): string {
		return (string) get_option( 'threadi_now_pages_frontend_period', 'live' );
	}

	/**
	 * Return the format a new widget should start with.
	 *
	 * @return string
	 */
	public function get_default_format(): string {
		return (string) get_option( 'threadi_now_pages_frontend_format', 'verbatim' );
	}
}
