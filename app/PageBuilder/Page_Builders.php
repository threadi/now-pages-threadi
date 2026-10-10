<?php
/**
 * File to handle our page builder support.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Instance_Registry;
use NowPagesThreadi\Helper\Singleton_Trait;

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
		add_filter( 'now_pages_threadi_log_categories', array( $this, 'add_log_category' ) );
	}

	/**
	 * Return the list of page builders.
	 *
	 * @return array<int,class-string<PageBuilder_Base>>
	 */
	public function get_page_builders(): array {
		$list = array(
			'\NowPagesThreadi\PageBuilder\Avada',
			'\NowPagesThreadi\PageBuilder\Avia',
			'\NowPagesThreadi\PageBuilder\Beaver',
			'\NowPagesThreadi\PageBuilder\BoldBuilder',
			'\NowPagesThreadi\PageBuilder\Breakdance',
			'\NowPagesThreadi\PageBuilder\Bricks',
			'\NowPagesThreadi\PageBuilder\Brizy',
			'\NowPagesThreadi\PageBuilder\Divi4',
			'\NowPagesThreadi\PageBuilder\Divi5',
			'\NowPagesThreadi\PageBuilder\Elementor',
			'\NowPagesThreadi\PageBuilder\FusionBuilder',
			'\NowPagesThreadi\PageBuilder\Gutenberg',
			'\NowPagesThreadi\PageBuilder\LiveComposer',
			'\NowPagesThreadi\PageBuilder\Oxygen',
			'\NowPagesThreadi\PageBuilder\Pagelayer',
			'\NowPagesThreadi\PageBuilder\Shortcode',
			'\NowPagesThreadi\PageBuilder\SiteOrigin',
			'\NowPagesThreadi\PageBuilder\Themify',
			'\NowPagesThreadi\PageBuilder\WpBakery',
			'\NowPagesThreadi\PageBuilder\Wp_Widget',
			'\NowPagesThreadi\PageBuilder\ZionBuilder',
		);

		/**
		 * Filter the possible page builders.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<PageBuilder_Base>> $list List of the handler.
		 */
		return apply_filters( 'now_pages_threadi_pagebuilder', $list );
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories The log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		$categories['pagebuilder'] = __( 'Page Builder', 'now-pages-threadi' );
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
