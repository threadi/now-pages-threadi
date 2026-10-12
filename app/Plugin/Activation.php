<?php
/**
 * This file defines tasks during the activation of this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\NowPages\Frontend;
use WP_Site;

/**
 * Object, which handles the tasks during the activation of this plugin.
 */
class Activation {

	/**
	 * Instance of this object.
	 *
	 * @var ?Activation
	 */
	private static ?Activation $instance = null;

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
	public static function get_instance(): Activation {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activate the plugin.
	 *
	 * Either via activation-hook or via cli-plugin-reset.
	 *
	 * @param bool $network_wide True if the plugin is activated for the whole network (passed by WordPress).
	 *
	 * @return void
	 */
	public function run( bool $network_wide = false ): void {
		// mark the activation runner as running.
		if ( ! defined( 'NOW_PAGES_ACTIVATION_RUNNING' ) ) {
			define( 'NOW_PAGES_ACTIVATION_RUNNING', 1 );
		}

		// run the tasks for every blog if the plugin is activated for the whole network.
		if ( $network_wide && is_multisite() ) {
			// loop through the blogs.
			foreach ( Plugin::get_blogs() as $blog_id ) {
				// switch to the blog.
				switch_to_blog( $blog_id );

				// run tasks for activation in this single blog.
				$this->activation_tasks();

				// switch back to the original blog.
				restore_current_blog();
			}
		} else {
			// simply run the tasks on the actual blog.
			$this->activation_tasks();
		}
	}

	/**
	 * Run the activation tasks for a new site in a network, where our plugin is activated network-wide.
	 *
	 * @param WP_Site $site The new site.
	 *
	 * @return void
	 */
	public function initialize_site( WP_Site $site ): void {
		// bail if our plugin is not activated network-wide.
		$sitewide_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( ! isset( $sitewide_plugins[ plugin_basename( NOWPAGES_PLUGIN ) ] ) ) {
			return;
		}

		// switch to the new blog.
		switch_to_blog( (int) $site->blog_id );

		// run tasks for activation in this single blog.
		$this->activation_tasks();

		// switch back to the original blog.
		restore_current_blog();
	}

	/**
	 * Define the tasks to run during activation of the plugin.
	 *
	 * Hint: do not run anything regarding extensions. This will be done at the end of the setup.
	 *
	 * @return void
	 */
	private function activation_tasks(): void {
		// install our db tables.
		Init::get_instance()->install_db_tables();

		// prepare the roles.
		Capabilities::get_instance()->install();

		// add the main settings.
		Settings::get_instance()->add_settings();

		// initiate the settings.
		Settings::get_instance()->get_settings_obj()->activation();

		// flush the permalink cache.
		Frontend::trigger_slug_update();

		// show a success message on WP CLI.
		if ( Plugin::is_cli() ) {
			\WP_CLI::success( 'Now Pages is now activated. Thank you for using our plugin :-)' );
		}
	}
}
