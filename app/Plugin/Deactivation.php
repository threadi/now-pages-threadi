<?php
/**
 * This file defines tasks during the deactivation of this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;

/**
 * Object, which handles the tasks during the deactivation of this plugin.
 */
class Deactivation {

	/**
	 * Instance of this object.
	 *
	 * @var ?Deactivation
	 */
	private static ?Deactivation $instance = null;

	/**
	 * Constructor for this object.
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
	public static function get_instance(): Deactivation {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Deactivate the plugin.
	 *
	 * Either via deactivation-hook or via cli-plugin-reset.
	 *
	 * @param bool $network_wide True if the plugin is deactivated for the whole network (passed by WordPress).
	 *
	 * @return void
	 */
	public function run( bool $network_wide = false ): void {
		// mark the deactivation runner as running.
		if ( ! defined( 'NOW_PAGES_DEACTIVATION_RUNNING' ) ) {
			define( 'NOW_PAGES_DEACTIVATION_RUNNING', 1 );
		}

		// run the tasks for every blog if the plugin is deactivated for the whole network.
		if ( $network_wide && is_multisite() ) {
			// loop through the blogs.
			foreach ( Plugin::get_blogs() as $blog_id ) {
				// switch to the blog.
				switch_to_blog( $blog_id );

				// run tasks for deactivation in this single blog.
				$this->deactivation_tasks();

				// switch back to the original blog.
				restore_current_blog();
			}
		} else {
			// simply run the tasks on the actual blog.
			$this->deactivation_tasks();
		}
	}

	/**
	 * Define the tasks to run during activation of the plugin.
	 *
	 * Hint: do not run anything regarding extensions. This will be done at the end of the setup.
	 *
	 * @return void
	 */
	private function deactivation_tasks(): void {
		// remove our cron events: they would otherwise keep running into
		// missing callbacks. they are created again after reactivation.
		Schedules::get_instance()->delete_all();

		// let WordPress rebuild the rewrite rules without our routes on the next request.
		// flush_rewrite_rules() would keep them, as our plugin is still loaded in this request.
		delete_option( 'rewrite_rules' );

		// show a success message on WP CLI.
		Plugin::is_cli() ? \WP_CLI::success( 'Now Pages is now deactivated. Reactivate it at any time :-)' ) : false;
	}
}
