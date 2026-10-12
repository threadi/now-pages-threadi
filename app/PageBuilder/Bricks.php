<?php
/**
 * This file contains the support for Bricks.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Bricks\Elements;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Registers and handles the support for Bricks.
 */
class Bricks extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'bricks';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Bricks is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// set Bricks specific hooks.
		add_action( 'init', array( $this, 'register_elements' ), 11 );
		add_filter( 'bricks/builder/i18n', array( $this, 'add_category_to_builder' ) );
	}

	/**
	 * Register our Bricks elements.
	 *
	 * @return void
	 */
	public function register_elements(): void {
		// bail if the Bricks class is not available.
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}

		Elements::register_element( Plugin::get_plugin_path() . 'app/PageBuilder/Bricks/Digest.php' );
	}

	/**
	 * Add our custom category to the Bricks builder.
	 *
	 * @param array<string,string> $i18n The translations.
	 *
	 * @return array<string,string>
	 */
	public function add_category_to_builder( array $i18n ): array {
		$i18n['threadi-now-pages'] = esc_html__( 'Now Pages', 'threadi-now-pages' );
		return $i18n;
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Bricks keeps its elements in post meta, post_content stays empty.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			'_bricks_editor_mode'    => 'bricks',
			'_bricks_template_type'  => 'content',
			'_bricks_page_content_2' => array(
				array(
					'id'          => $this->get_element_id(),
					'name'        => 'threadi-now-pages-digest',
					'parent'      => 0,
					'children'    => array(),
					'settings'    => array(
						'period'           => get_option( 'threadi_now_pages_frontend_period', 'live' ),
						'format'           => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
						'override_display' => 'no',
					),
					'themeStyles' => array(),
				),
			),
		);
	}

	/**
	 * Return an element ID in the format Bricks uses.
	 *
	 * @return string
	 */
	private function get_element_id(): string {
		return strtolower( wp_generate_password( 6, false, false ) );
	}

	/**
	 * Check if Bricks and its necessary plugins are active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// check for the theme.
		$is_bricks = false;
		$theme     = wp_get_theme();
		if ( 'Bricks' === $theme->get( 'Name' ) ) {
			$is_bricks = true;
		}
		if ( $theme->parent() && 'Bricks' === $theme->parent()->get( 'Name' ) ) {
			$is_bricks = true;
		}

		// return resulting value.
		return $is_bricks;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Bricks', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
