<?php
/**
 * File to handle our page builder support.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Instance_Registry;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Object to handle page builder support.
 */
class Page_Builders {
	use Singleton_Trait;

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// register the known page builder.
		foreach ( $this->get_page_builders_as_objects() as $page_builder_obj ) {
			$page_builder_obj->init();
		}

		// use hooks.
		add_filter( 'threadi_now_pages_log_categories', array( $this, 'add_log_category' ) );
	}

	/**
	 * Return the list of page builders.
	 *
	 * @return array<int,class-string<PageBuilder_Base>>
	 */
	public function get_page_builders(): array {
		$list = array(
			'\ThreadiNowPages\PageBuilder\Avada',
			'\ThreadiNowPages\PageBuilder\Avia',
			'\ThreadiNowPages\PageBuilder\Beaver',
			'\ThreadiNowPages\PageBuilder\BoldBuilder',
			'\ThreadiNowPages\PageBuilder\Breakdance',
			'\ThreadiNowPages\PageBuilder\Bricks',
			'\ThreadiNowPages\PageBuilder\Brizy',
			'\ThreadiNowPages\PageBuilder\Divi4',
			'\ThreadiNowPages\PageBuilder\Divi5',
			'\ThreadiNowPages\PageBuilder\Elementor',
			'\ThreadiNowPages\PageBuilder\FusionBuilder',
			'\ThreadiNowPages\PageBuilder\Gutenberg',
			'\ThreadiNowPages\PageBuilder\LiveComposer',
			'\ThreadiNowPages\PageBuilder\Oxygen',
			'\ThreadiNowPages\PageBuilder\Pagelayer',
			'\ThreadiNowPages\PageBuilder\Shortcode',
			'\ThreadiNowPages\PageBuilder\SiteOrigin',
			'\ThreadiNowPages\PageBuilder\Themify',
			'\ThreadiNowPages\PageBuilder\WpBakery',
			'\ThreadiNowPages\PageBuilder\Wp_Widget',
			'\ThreadiNowPages\PageBuilder\ZionBuilder',
		);

		/**
		 * Filter the possible page builders.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<PageBuilder_Base>> $list List of the handler.
		 */
		return apply_filters( 'threadi_now_pages_pagebuilder', $list );
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories The log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		$categories['pagebuilder'] = __( 'Page Builder', 'threadi-now-pages' );
		return $categories;
	}

	/**
	 * Return the page builder objects as list.
	 *
	 * @return array<int,PageBuilder_Base>
	 */
	public function get_page_builders_as_objects(): array {
		return Instance_Registry::get_instance()->get_new_instances_of( $this->get_page_builders(), PageBuilder_Base::class );
	}

	/**
	 * Run the early initialization of every page builder.
	 *
	 * @return void
	 */
	public function early_init(): void {
		foreach ( $this->get_page_builders_as_objects() as $page_builder_obj ) {
			$page_builder_obj->early_init();
		}
	}
}
