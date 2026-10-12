<?php
/**
 * This file contains the support for Zion Builder.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\PageBuilder\ZionBuilder\Digest;

/**
 * Registers and handles the support for Avada.
 */
class ZionBuilder extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'zionbuilder';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'zionbuilder/elements_manager/register_elements', array( $this, 'register_elements' ) );
		add_filter( 'zionbuilder/elements/categories', array( $this, 'add_category' ) );
	}

	/**
	 * Add our own category for the elements of this plugin.
	 *
	 * @param array<int,array<string,mixed>> $categories The existing categories.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_category( array $categories ): array {
		$categories[] = array(
			'id'       => 'threadi-now-pages',
			'name'     => __( 'Now Pages', 'threadi-now-pages' ),
			'priority' => 10,
		);

		return $categories;
	}

	/**
	 * Register our elements.
	 *
	 * @param mixed $elements_manager The elements manager of Zion Builder.
	 *
	 * @return void
	 */
	public function register_elements( mixed $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'register_element' ) ) {
			return;
		}

		$elements_manager->register_element( new Digest() );
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Zion keeps its elements in post meta, post_content stays empty.
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
		$elements = array(
			array(
				'uid'          => $this->get_element_uid(),
				'element_type' => 'zion_section',
				'options'      => array(),
				'content'      => array(
					array(
						'uid'          => $this->get_element_uid(),
						'element_type' => 'threadi_now_pages_digest',
						'options'      => array(
							'period' => get_option( 'threadi_now_pages_frontend_period', 'live' ),
							'format' => get_option( 'threadi_now_pages_frontend_format', 'verbatim' ),
						),
						'content'      => array(),
					),
				),
			),
		);

		return array(
			'_zionbuilder_page_status'   => 'enabled',
			'_zionbuilder_page_elements' => wp_json_encode( $elements ),
		);
	}

	/**
	 * Return an element UID in the format Zion Builder uses.
	 *
	 * @return string
	 */
	private function get_element_uid(): string {
		return 'uid' . wp_rand( 1000000000000, 9999999999999 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Zion Builder', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Return whether this page builder is active on this website.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'zionbuilder/zionbuilder.php' );
	}
}
