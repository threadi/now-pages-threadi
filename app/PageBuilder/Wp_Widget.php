<?php
/**
 * This file contains the support for classic widgets.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Registers and handles the support for classic widgets.
 */
class Wp_Widget extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'wp_widget';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'widgets_init', array( $this, 'activate' ) );
	}

	/**
	 * Enable our own widgets if Block widgets are disabled.
	 *
	 * @return void
	 */
	public function activate(): void {
		register_widget( 'ThreadiNowPages\PageBuilder\WpWidgets\Digest' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Classic WordPress Widget', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
