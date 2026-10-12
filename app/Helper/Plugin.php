<?php
/**
 * This file contains helper for this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Plugin\Log;
use WP_Filesystem_Base;
use WP_Filesystem_Direct;
use WP_Rewrite;

/**
 * Object with helper for this plugin.
 */
class Plugin {
	/**
	 * Return the absolute URL to the plugin (already trailed with slash).
	 *
	 * @return string
	 */
	public static function get_plugin_url(): string {
		return trailingslashit( plugin_dir_url( NOWPAGES_PLUGIN ) );
	}

	/**
	 * Return the absolute local filesystem-path (already trailed with slash) to the plugin.
	 *
	 * @return string
	 */
	public static function get_plugin_path(): string {
		return trailingslashit( plugin_dir_path( NOWPAGES_PLUGIN ) );
	}

	/**
	 * Return the version of the given file.
	 *
	 * With WP_DEBUG or plugin-debug enabled its @filemtime().
	 * Without this it is the plugin-version.
	 *
	 * @param string $filepath The absolute path to the requested file.
	 *
	 * @return string
	 */
	public static function get_file_version( string $filepath ): string {
		// check for WP_DEBUG.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return (string) filemtime( $filepath );
		}

		$plugin_version = NOWPAGES_PLUGIN_VERSION;

		/**
		 * Filter the used file version (for JS- and CSS-files, which get enqueued).
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $plugin_version The plugin-version.
		 * @param string $filepath The absolute path to the requested file.
		 */
		$file_version = apply_filters( 'threadi_now_pages_file_version', $plugin_version, $filepath );

		// use the plugin version if the filter returned something else than a string.
		if ( ! is_string( $file_version ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return $plugin_version;
		}

		// return the resulting version.
		return $file_version;
	}

	/**
	 * Return the IDs of the blogs in a multisite-installation.
	 *
	 * @param bool $all  True to return every blog of the installation, including archived,
	 *                   spam and deleted ones and the blogs of other networks. Only meant
	 *                   for the uninstallation: nothing may be left behind there.
	 *
	 * @return array<int,int>
	 */
	public static function get_blogs( bool $all = false ): array {
		// bail if this is not a multisite installation.
		if ( false === is_multisite() ) {
			return array();
		}

		// every blog, whatever its state and network.
		if ( $all ) {
			$blog_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			return array_values( array_filter( array_map( 'absint', $blog_ids ) ) );
		}

		// get the WordPress-own database object.
		global $wpdb;

		// get blogs in this site-network.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Get blog data.
		$blog_ids = $wpdb->get_col(
			$wpdb->prepare(
				'
	            SELECT blog_id
	            FROM ' . $wpdb->blogs . "
	            WHERE site_id = %d
	            AND spam = '0'
	            AND deleted = '0'
	            AND archived = '0'
	            ",
				$wpdb->siteid
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		// return the list of IDs as integers.
		return array_values( array_filter( array_map( 'absint', (array) $blog_ids ) ) );
	}

	/**
	 * Check if WP CLI has been called.
	 *
	 * @return bool
	 */
	public static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Return the WP Filesystem object.
	 *
	 * @param bool $local Mark with "true" to get the local filesystem object.
	 *
	 * @return WP_Filesystem_Base
	 */
	public static function get_wp_filesystem( bool $local = false ): WP_Filesystem_Base {
		// get WP Filesystem-handler for local files if requested.
		if ( $local ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

			return new WP_Filesystem_Direct( false );
		}

		// get global WP Filesystem handler.
		require_once ABSPATH . '/wp-admin/includes/file.php';
		\WP_Filesystem();
		global $wp_filesystem;

		// bail if wp_filesystem is not of "WP_Filesystem_Base".
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			return new WP_Filesystem_Direct( false );
		}

		// return the local object on any error.
		if ( $wp_filesystem->errors->has_errors() ) {
			// log this event.
			/* translators: a name will replace %1$s. */
			Log::get_instance()->add( sprintf( __( '<strong>Error during loading the required WordPress-own filesystem object!</strong><br>We will now use the local filesystem object and hope it will work.<br><br>Tipps to solve this:<ul><li>Check the following error and speak to your WordPress administrator about it.</li><li>Check your <em>wp-config.php</em> if you have the constant "FS_METHOD" set there. If yes, remove it and check if your WordPress can save media files.</li><li>Ask the support of your hoster for help.</li></ul>Used filesystem mode: <em>%1$s</em><br>The following errors occurred:', 'threadi-now-pages' ), get_filesystem_method() ) . ' <code>' . wp_json_encode( $wp_filesystem->errors ) . '</code>', 'error', 'system' );

			// embed the local directory object.
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

			return new WP_Filesystem_Direct( false );
		}

		// return the requested filesystem object.
		return $wp_filesystem;
	}

	/**
	 * Return the a11n window hint.
	 *
	 * @return string
	 */
	public static function get_a11n_window_hint(): string {
		return '<span class="screen-reader-text"> ' . __( 'opens a new window', 'threadi-now-pages' ) . '</span>';
	}

	/**
	 * Return the plugin support url: the forum on WordPress.org.
	 *
	 * @return string
	 */
	public static function get_plugin_support_url(): string {
		return 'https://wordpress.org/support/plugin/threadi-now-pages/';
	}

	/**
	 * Return the name of this plugin.
	 *
	 * @return string
	 */
	public static function get_plugin_name(): string {
		// load the necessary functions, e.g. during cron.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// get the plugin data.
		$plugin_data = get_plugin_data( NOWPAGES_PLUGIN );

		// bail if no 'Name' is in the result.
		if ( empty( $plugin_data['Name'] ) ) {
			return '';
		}

		// return the plugin name.
		return $plugin_data['Name'];
	}

	/**
	 * Checks if the current request is a WP REST API request.
	 *
	 * Case #1: After WP_REST_Request initialization
	 * Case #2: Support "plain" permalink settings and check if `rest_route` starts with `/`
	 * Case #3: It can happen that WP_Rewrite is not yet initialized,
	 *          so do this (wp-settings.php)
	 * Case #4: URL Path begins with wp-json/ (your REST prefix)
	 *          Also supports WP installations in the subfolders
	 *          Not used with plain permalinks: there the REST URL is the home URL
	 *
	 * @returns boolean
	 * @author matzeeable
	 */
	public static function is_rest_request(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) // Case #1.
			|| ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) // (#2)
					&& is_string( $GLOBALS['wp']->query_vars['rest_route'] )
					&& str_starts_with( $GLOBALS['wp']->query_vars['rest_route'], '/' ) ) ) {
			return true;
		}

		// Case #3.
		global $wp_rewrite;
		if ( is_null( $wp_rewrite ) ) {
			$wp_rewrite = new WP_Rewrite();
		}

		// with plain permalinks the REST URL is the home URL plus "rest_route", so only this parameter shows a REST request.
		if ( ! $wp_rewrite->using_permalinks() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only the type of the request is checked.
			$rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';
			return str_starts_with( $rest_route, '/' );
		}

		// Case #4.
		$rest_url    = wp_parse_url( trailingslashit( rest_url() ) );
		$current_url = wp_parse_url( add_query_arg( array() ) );
		if ( is_array( $current_url ) && is_array( $rest_url ) && isset( $current_url['path'], $rest_url['path'] ) ) {
			return str_starts_with( $current_url['path'], $rest_url['path'] );
		}
		return false;
	}

	/**
	 * Return whether the settings of this plugin are needed in the actual request.
	 *
	 * They are needed in the backend (including AJAX), during WP Cron, WP CLI and REST API
	 * requests. Regular frontend requests only read the values via get_option(), so the
	 * settings objects do not have to be built there.
	 *
	 * @return bool
	 */
	public static function is_settings_request(): bool {
		// the settings are needed in the backend, during cron and during WP CLI.
		$result = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

		// and also for REST API requests.
		if ( ! $result ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
			$result = isset( $_GET['rest_route'] ) || self::is_rest_request();
		}

		/**
		 * Filter whether the settings of this plugin are built in the actual request.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param bool $result True if the settings are built.
		 */
		return (bool) apply_filters( 'threadi_now_pages_is_settings_request', $result );
	}

	/**
	 * Return the import URL.
	 *
	 * @return string
	 */
	public static function get_import_url(): string {
		return add_query_arg(
			array(
				'action' => 'threadi_now_pages_import',
				'nonce'  => wp_create_nonce( 'threadi-now-pages-import' ),
			),
			get_admin_url() . 'admin.php'
		);
	}

	/**
	 * Return the review URL.
	 *
	 * @return string
	 */
	public static function get_review_url(): string {
		return 'https://wordpress.org/support/plugin/threadi-now-pages/reviews/';
	}

	/**
	 * Return whether plugin developer modus is enabled.
	 *
	 * @return bool
	 */
	public static function is_development_mode_active(): bool {
		return function_exists( 'wp_is_development_mode' ) && wp_is_development_mode( 'plugin' );
	}

	/**
	 * Return the GitHub documentation link.
	 *
	 * @return string
	 */
	public static function get_github_documentation_link(): string {
		return 'https://github.com/threadi/threadi-now-pages/tree/master/docs';
	}

	/**
	 * Checks whether a given plugin is active.
	 *
	 * Used because WP's own function is_plugin_active() is not accessible everywhere.
	 *
	 * @param string $plugin Path to the requested plugin relative to plugin-directory.
	 * @return bool
	 */
	public static function is_plugin_active( string $plugin ): bool {
		// check the plugins of this single site.
		if ( in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		// bail if this is not a multisite.
		if ( ! is_multisite() ) {
			return false;
		}

		// check the network-wide activated plugins.
		$sitewide_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $sitewide_plugins[ $plugin ] );
	}
}
