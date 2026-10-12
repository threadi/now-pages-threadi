<?php
/**
 * File to handle template-tasks of this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Handler for templates.
 */
class Templates {
	/**
	 * Instance of this object.
	 *
	 * @var ?Templates
	 */
	private static ?Templates $instance = null;

	/**
	 * Constructor for this object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Templates {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the templates.
	 *
	 * @return void
	 */
	public function init(): void {}

	/**
	 * Return the path to a requested template if it exists.
	 *
	 * Also load the requested file if it is located in the /wp-content/themes/xy/threadi-now-pages/ directory.
	 *
	 * @param string $template The template to use.
	 * @return string
	 */
	public function get_template( string $template ): string {
		// reject path traversal outright.
		if ( str_contains( $template, '..' ) ) {
			return '';
		}

		// check if the requested template exists in the theme.
		$theme_template = locate_template( trailingslashit( basename( dirname( NOWPAGES_PLUGIN ) ) ) . $template );
		if ( $theme_template ) {
			return $theme_template;
		}

		// set the directory for the template to use.
		$directory = NOWPAGES_PLUGIN;

		/**
		 * Set the template directory.
		 *
		 * Defaults to our own plugin-directory.
		 *
		 * @since 1.0.0 Available since first release.
		 *
		 * @param string $directory The directory to use.
		 */
		$plugin_template = plugin_dir_path( apply_filters( 'threadi_now_pages_set_template_directory', $directory ) ) . 'templates/' . $template;
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		// return template from light-plugin.
		return plugin_dir_path( NOWPAGES_PLUGIN ) . 'templates/' . $template;
	}

	/**
	 * Check if the given template exists.
	 *
	 * @param string $template The searched template with a relative path to our plugin directory.
	 * @return bool
	 */
	public function has_template( string $template ): bool {
		// reject path traversal outright.
		if ( str_contains( $template, '..' ) ) {
			return false;
		}

		// check if the requested template exists in the theme.
		$theme_template = locate_template( trailingslashit( basename( dirname( NOWPAGES_PLUGIN ) ) ) . $template );
		if ( $theme_template ) {
			return true;
		}

		// set the directory for the template to use.
		$directory = NOWPAGES_PLUGIN;

		/**
		 * Set the template directory.
		 *
		 * Defaults to our own plugin-directory.
		 *
		 * @since 1.0.0 Available since first release.
		 *
		 * @param string $directory The directory to use.
		 */
		$plugin_template = plugin_dir_path( apply_filters( 'threadi_now_pages_set_template_directory', $directory ) ) . 'templates/' . $template;
		if ( file_exists( $plugin_template ) ) {
			return true;
		}

		// return template from light-plugin.
		return file_exists( plugin_dir_path( NOWPAGES_PLUGIN ) . 'templates/' . $template );
	}
}
