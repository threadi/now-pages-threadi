<?php
/**
 * File to handle a widget for Brizy.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\Brizy;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Brizy_Content_Placeholders_Simple;
use Brizy_Content_Providers_AbstractProvider;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;

/**
 * Object for a widget for Brizy.
 */
class Digest extends Brizy_Content_Providers_AbstractProvider {
	/**
	 * The placeholder.
	 *
	 * @var string
	 */
	protected string $placeholder = 'now_pages_threadi_digest';

	/**
	 * The name for REST API.
	 *
	 * @var string
	 */
	protected string $rest_name = 'digest';

	/**
	 * Initialize this object.
	 */
	public function __construct() {
		/**
		 * Register our own description placeholder.
		 */
		$this->registerPlaceholderName(
			$this->get_placeholder(),
			function ( $name ) {
				return new Brizy_Content_Placeholders_Simple(
					$this->get_title(),
					$name,
					$this->get_callback()
				);
			}
		);
	}

	/**
	 * Return the placeholder.
	 *
	 * @return string
	 */
	private function get_placeholder(): string {
		return $this->placeholder;
	}

	/**
	 * Return the title.
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Digest', 'now-pages-threadi' );
	}

	/**
	 * Return the callback.
	 *
	 * @return callable
	 */
	protected function get_callback(): callable {
		return static function ( $context, $content_placeholder ) {
			// get the attributes.
			$attributes = $content_placeholder->getAttributes();
			if ( ! is_array( $attributes ) ) {
				$attributes = array();
			}

			// the view writes "true"/"false", older layouts may contain "on".
			$is_on = static function ( mixed $value ): bool {
				return in_array( $value, array( true, 1, '1', 'on', 'true', 'yes' ), true );
			};

			// only pass known attributes: the placeholder may contain anything.
			$args = array(
				'period' => ! empty( $attributes['period'] ) && is_string( $attributes['period'] ) ? $attributes['period'] : Digests::get_instance()->get_default_period(),
				'format' => ! empty( $attributes['format'] ) && is_string( $attributes['format'] ) ? $attributes['format'] : Digests::get_instance()->get_default_format(),
			);

			// only pass the display settings when the block was told to override
			// them. otherwise every block would beat the settings of the website.
			if ( isset( $attributes['override_display'] ) && $is_on( $attributes['override_display'] ) ) {
				$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

				foreach ( $names as $name ) {
					if ( ! isset( $attributes[ $name ] ) ) {
						continue;
					}

					$args[ $name ] = $is_on( $attributes[ $name ] );
				}
			}

			// return the rendered HTML.
			return ( new Digest_Builder() )->build( $args );
		};
	}
}
