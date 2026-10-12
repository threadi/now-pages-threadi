<?php
/**
 * File for initialize the Divi Renderer.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Divi4;

// prevent direct access.
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;

defined( 'ABSPATH' ) || exit;

/**
 * Init the Renderer for Divi.
 */
class Render {

	/**
	 * Instance of this object.
	 *
	 * @var ?Render
	 */
	private static ?Render $instance = null;

	/**
	 * Constructor for Init-Handler.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Render {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return the list of entries.
	 *
	 * @param array<string,mixed> $attributes List of attributes.
	 *
	 * @return string
	 */
	public function get_entries( array $attributes ): string {
		$args = array(
			'period' => ! empty( $attributes['period'] ) ? $attributes['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $attributes['format'] ) ? $attributes['format'] : Digests::get_instance()->get_default_format(),
		);
		if ( 'on' === ( $attributes['override_display'] ?? '' ) ) {
			$args['show_time']         = 'on' === ( $attributes['show_time'] ?? '' );
			$args['show_source']       = 'on' === ( $attributes['show_source'] ?? '' );
			$args['link_source']       = 'on' === ( $attributes['link_source'] ?? '' );
			$args['show_period']       = 'on' === ( $attributes['show_period'] ?? '' );
			$args['group_by_category'] = 'on' === ( $attributes['group_by_category'] ?? '' );
		}

		return ( new Digest_Builder() )->build( $args );
	}
}
