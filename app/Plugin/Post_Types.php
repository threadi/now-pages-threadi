<?php
/**
 * File for handling any post-type we add.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Fediverse\Updates;
use NowPagesThreadi\Helper\Instance_Registry;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Sources\Sources;
use WP_Query;

/**
 * Object to handle any post-type we add.
 */
class Post_Types implements Singleton_Interface {
	use Singleton_Trait;

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
		// register our post-types.
		$this->register_post_type();
	}

	/**
	 * Register the post-types from the list.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		foreach ( $this->get_post_types_as_objects() as $post_type ) {
			$post_type->init();
		}
	}

	/**
	 * Return the list of post-types.
	 *
	 * @return array<int,class-string<Post_Type_Base>>
	 */
	private function get_post_types(): array {
		$post_types = array(
			Entries::class,
			Sources::class,
			Updates::class,
		);
		/**
		 * Filter the post-types.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,class-string<Post_Type_Base>> $post_types List of post-types.
		 */
		return apply_filters( 'now_pages_threadi_register_post_type', $post_types );
	}

	/**
	 * Return our post types as objects.
	 *
	 * @return array<int,Post_Type_Base>
	 */
	private function get_post_types_as_objects(): array {
		return Instance_Registry::get_instance()->get_instances_of( $this->get_post_types(), Post_Type_Base::class );
	}

	/**
	 * Return the list of slugs for our own post types.
	 *
	 * @return array<int,string>
	 */
	public function get_post_type_slugs(): array {
		$slugs = array();
		foreach ( $this->get_post_types_as_objects() as $post_type ) {
			$slugs[] = $post_type->get_slug();
		}
		return $slugs;
	}
}
