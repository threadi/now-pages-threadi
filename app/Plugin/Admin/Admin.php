<?php
/**
 * File with admin tasks for this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin\Admin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Dependencies\easyTransientsForWordPress\Transients;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Frontend;
use ThreadiNowPages\Plugin\Log;
use ThreadiNowPages\Plugin\Post_Types;
use ThreadiNowPages\Plugin\Settings;
use ThreadiNowPages\Plugin\User_Settings;
use WP_Admin_Bar;
use WP_Error;

/**
 * Object for admin tasks for this plugin.
 */
class Admin {
	/**
	 * Instance of this object.
	 *
	 * @var ?Admin
	 */
	private static ?Admin $instance = null;

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
	public static function get_instance(): Admin {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize this plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// initialize Dashboard support.
		Dashboard::get_instance()->init();

		// use hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'add_js_and_styles' ), 10, 0 );
		add_action( 'init', array( $this, 'configure_transients' ), 5 );
		add_action( 'admin_bar_menu', array( $this, 'add_custom_toolbar_for_global_page' ), 100 );
		add_action( 'admin_bar_menu', array( $this, 'add_custom_toolbar_for_user_page' ), 100 );
		add_filter( 'admin_body_class', array( $this, 'add_body_classes' ) );
		add_action( 'admin_init', array( $this, 'check_language' ) );
		add_action( 'admin_init', array( $this, 'show_review_hint' ) );
		add_action( 'admin_init', array( $this, 'get_setting_errors' ), 100 );
		add_action( 'admin_menu', array( $this, 'add_error_marker_in_main_menu_title' ), PHP_INT_MAX );
		add_action( 'threadi-now-pages_crypt_error', array( $this, 'save_crypt_error' ), 10, 3 );
		add_filter( 'threadi_now_pages_settings_menu_title', array( $this, 'add_error_marker_to_menu_title' ) );

		// use action hooks.
		add_action( 'admin_action_threadi_now_pages_log_export', array( $this, 'export_log' ) );
		add_action( 'admin_action_threadi_now_pages_log_empty', array( $this, 'empty_log' ) );
		add_action( 'admin_action_threadi_now_pages_log_reset_marker', array( $this, 'reset_error_marker' ) );
	}

	/**
	 * Add our own JS and CSS in the backend.
	 *
	 * @return void
	 */
	public function add_js_and_styles(): void {
		// add the CSS.
		wp_enqueue_style(
			'threadi-now-pages-admin',
			Plugin::get_plugin_url() . 'admin/style.css',
			array(),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/style.css' )
		);

		// backend-JS.
		wp_enqueue_script(
			'threadi-now-pages-admin',
			Plugin::get_plugin_url() . 'admin/script.js',
			array( 'jquery' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/script.js' ),
			true
		);

		// add php-vars to our js-script.
		wp_localize_script(
			'threadi-now-pages-admin',
			'threadiNowPagesJsVars',
			array(
				'ajax_url'                => admin_url( 'admin-ajax.php' ),
				'test_email_nonce'        => wp_create_nonce( 'threadi-now-pages-test-email' ),
				'get_import_dialog_nonce' => wp_create_nonce( 'threadi-now-pages-get-import-dialog' ),
				'title_error'             => __( 'Error', 'threadi-now-pages' ),
				'txt_error'               => __( '<strong>An unexpected error occurred.</strong> The error was:', 'threadi-now-pages' ),
				'generate_error_text'     => __( 'Unknown error during AJAX-request', 'threadi-now-pages' ),
				'lbl_ok'                  => __( 'OK', 'threadi-now-pages' ),
				'title_rate_us'           => __( 'Rate us', 'threadi-now-pages' ),
				'title_run_import'        => __( 'Run import', 'threadi-now-pages' ),
				'import_url'              => current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ? Plugin::get_import_url() : '',
				'review_url'              => Plugin::get_review_url(),
			)
		);
	}

	/**
	 * Export log as CSV.
	 *
	 * @return void
	 */
	public function export_log(): void {
		// check the nonce.
		check_admin_referer( 'threadi-now-pages-log-export', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'threadi-now-pages' ), '', array( 'response' => 403 ) );
		}

		// get entries.
		$log     = Log::get_instance();
		$entries = $log->get_entries();

		// bail if there is nothing to export.
		if ( empty( $entries ) ) {
			wp_safe_redirect( (string) wp_get_referer() );
			exit;
		}

		// create the filename for JSON-download-file.
		$filename = gmdate( 'YmdHi' ) . '_' . get_option( 'blogname' ) . '_Now_Pages_Logs.csv';
		/**
		 * Filter the filename for CSV-download.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string $filename The generated filename for CSV-download.
		 */
		$filename = apply_filters( 'threadi_now_pages_export_filename', $filename );

		// set the header for response as CSV-download.
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );

		// generate CSV-output.
		$fp = fopen( 'php://output', 'w' );

		// bail if the file could not be opened.
		if ( ! $fp ) {
			exit;
		}

		// get the header.
		$head_row = $entries[0];

		// add the header.
		// the escape character is set explicitly: relying on its default is deprecated since PHP 8.4.
		fputcsv( $fp, array_keys( $head_row ), ',', '"', '' );

		// add the entries.
		foreach ( $entries as $data ) {
			fputcsv(
				$fp,
				array_map(
					static function ( $value ) {
						$value = (string) $value;

						// prefix values Excel would evaluate as a formula.
						return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
					},
					$data
				),
				',',
				'"',
				''
			);
		}

		// do nothing more.
		exit;
	}

	/**
	 * Empty the log per request.
	 *
	 * @return void
	 */
	public function empty_log(): void {
		global $wpdb;

		// check nonce.
		check_admin_referer( 'threadi-now-pages-log-empty', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// empty the table.
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'threadi_now_pages_logs`' );

		// reset the error marker, as the errors it counts are gone.
		delete_option( 'threadi_now_pages_log_error_count' );

		// redirect user.
		wp_safe_redirect( (string) wp_get_referer() );
		exit;
	}

	/**
	 * Reset the error marker per request.
	 *
	 * @return void
	 */
	public function reset_error_marker(): void {
		// check nonce.
		check_admin_referer( 'threadi-now-pages-log-reset-marker', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'threadi-now-pages' ), '', array( 'response' => 403 ) );
		}

		// reset the counter.
		delete_option( 'threadi_now_pages_log_error_count' );

		// redirect user.
		wp_safe_redirect( (string) wp_get_referer() );
		exit;
	}

	/**
	 * Set the base configuration for each transient.
	 *
	 * @return void
	 */
	public function configure_transients(): void {
		$transients_obj = Transients::get_instance();
		$transients_obj->set_slug( 'threadi-now-pages' );
		$transients_obj->set_capability( 'manage_options' );
		$transients_obj->set_template( 'grouped.php' );
		$transients_obj->set_display_method( 'grouped' );
		$transients_obj->set_url( Plugin::get_plugin_url() . '/app/Dependencies/easyTransientsForWordPress/' );
		$transients_obj->set_path( Plugin::get_plugin_path() . '/app/Dependencies/easyTransientsForWordPress/' );
		$transients_obj->set_vendor_path( Plugin::get_plugin_path() . 'vendor/' );
		$transients_obj->set_translations(
			array(
				/* translators: %1$d will be replaced by the days this message will be hidden. */
				'hide_message' => __( 'Hide this message for %1$d days.', 'threadi-now-pages' ),
				'dismiss'      => __( 'Dismiss', 'threadi-now-pages' ),
			)
		);
		$transients_obj->init();
	}

	/**
	 * Add a link in the toolbar to the global now page in frontend.
	 *
	 * @param WP_Admin_Bar $admin_bar The object of the Admin-Bar.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 **/
	public function add_custom_toolbar_for_global_page( WP_Admin_Bar $admin_bar ): void {
		// bail if global now pages are disabled.
		if ( ! Frontend::get_instance()->is_public() ) {
			return;
		}

		// show link to entry list in backend in frontend view.
		if ( ! is_admin() ) {
			// bail if this it not the now page.
			if ( ! Frontend::get_instance()->is_now_request() ) {
				return;
			}

			// add the entry in the toolbar.
			$admin_bar->add_menu(
				array(
					'id'     => 'threadi-now-pages-admin',
					'parent' => null,
					'group'  => null,
					'title'  => __( 'View entries of Now Page', 'threadi-now-pages' ),
					'href'   => Entries::get_instance()->get_backend_list_url(),
				)
			);
		} else {
			// bail if "get_current_screen()" is missing.
			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			// get the actual screen.
			$screen = get_current_screen();

			// bail if we are not on our own cpt listing and not on our own settings page.
			if ( $screen && 'settings_page_threadi_now_pages_settings' !== $screen->base && ! in_array( $screen->post_type, Post_Types::get_instance()->get_post_type_slugs(), true ) ) {
				return;
			}

			// add the entry in the toolbar.
			$admin_bar->add_menu(
				array(
					'id'     => 'threadi-now-pages-admin',
					'parent' => null,
					'group'  => null,
					'title'  => __( 'View your Now Page in frontend', 'threadi-now-pages' ),
					'href'   => Frontend::get_instance()->get_global_now_page_url(),
				)
			);
		}
	}

	/**
	 * Add a link in the toolbar to the user now page in frontend.
	 *
	 * @param WP_Admin_Bar $admin_bar The object of the Admin-Bar.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 **/
	public function add_custom_toolbar_for_user_page( WP_Admin_Bar $admin_bar ): void {
		// show link to the now page in frontend in backend.
		if ( is_admin() ) {
			// bail if user is allowed to see this.
			if ( ! User_Settings::get_instance()->current_user_can_display() ) {
				return;
			}

			// bail if "get_current_screen()" is missing.
			if ( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			// get the actual screen.
			$screen = get_current_screen();

			// bail if we are not on our own cpt listing.
			if ( $screen && 'profile' !== $screen->id ) {
				return;
			}

			// bail if the actual user does not have enabled its now page.
			$keys = Display_Settings::user_meta_keys();
			if ( 1 === absint( get_user_meta( get_current_user_id(), $keys['disabled'], true ) ) ) {
				return;
			}

			// add the entry in the toolbar.
			$admin_bar->add_menu(
				array(
					'id'     => 'threadi-now-pages-admin',
					'parent' => null,
					'group'  => null,
					'title'  => __( 'View your Now Page in frontend', 'threadi-now-pages' ),
					'href'   => Frontend::get_instance()->get_author_now_page_url( wp_get_current_user() ),
				)
			);
		}
	}

	/**
	 * Add custom classes to body-tag.
	 *
	 * @param mixed $classes List of classes.
	 *
	 * @return string
	 */
	public function add_body_classes( mixed $classes ): string {
		// use an empty list if another plugin returned something else than a string.
		if ( ! is_string( $classes ) ) {
			$classes = '';
		}

		// all class to hide the review hints.
		if ( 1 === absint( get_option( 'threadi_now_pages_hide_review_hints' ) ) ) {
			$classes .= ' threadi-now-pages-hide-buttons';
		}

		// return the resulting list of classes.
		return $classes;
	}

	/**
	 * Check the language and show a hint if we have no translations for it.
	 *
	 * @return void
	 */
	public function check_language(): void {
		// bail if the now page is not published.
		if ( ! Frontend::get_instance()->is_public() ) {
			return;
		}

		// get the object for transients.
		$transients_obj = Transients::get_instance();

		// bail if the language is Englisch.
		if ( get_locale() === 'en_US' || get_user_locale() === 'en_US' ) {
			$transients_obj->get_transient_by_name( 'threadi_now_pages_translatable' )->delete();
			return;
		}

		// bail if 'Show what you are actually doing on your website.' is translated.
		if ( __( 'Show what you are actually doing on your website.', 'threadi-now-pages' ) !== 'Show what you are actually doing on your website.' ) {
			$transients_obj->get_transient_by_name( 'threadi_now_pages_translatable' )->delete();
			return;
		}

		// show hint that the plugin could be translated in the actually used language.
		$transient_obj = $transients_obj->add();
		$transient_obj->set_type( 'hint' );
		$transient_obj->set_name( 'threadi_now_pages_translatable' );
		$transient_obj->set_dismissible_days( 180 );
		/* translators: URLs will replace %1$s and %2$s. */
		$transient_obj->set_message( sprintf( __( '<strong>You are using a language in your WordPress that has not yet been translated for the plugin "Now Pages".</strong> You are welcome to help by providing translations for your language <a href="%1$s" target="_blank">here</a>. If you have any questions, please feel free to contact us <a href="%2$s" target="_blank">in the support forum</a>.', 'threadi-now-pages' ), 'https://translate.wordpress.org/projects/wp-plugins/threadi-now-pages/', Plugin::get_plugin_support_url() ) );
		$transient_obj->save();
	}

	/**
	 * Show hint to review our plugin every 90 days.
	 *
	 * @return void
	 */
	public function show_review_hint(): void {
		// bail if the now page is not published.
		if ( ! Frontend::get_instance()->is_public() ) {
			Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_admin_show_review_hint' )->delete();
			return;
		}

		// bail if transient is already dismissed.
		if ( Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_admin_show_review_hint' )->is_dismissed() ) {
			return;
		}

		$install_date = absint( get_option( 'threadi_now_pages_install_date' ) );
		if ( $install_date > 0 ) {
			if ( time() > strtotime( '+90 days', $install_date ) ) {
				$transient_obj = Transients::get_instance()->add();
				$transient_obj->set_dismissible_days( 90 );
				$transient_obj->set_name( 'threadi_now_pages_admin_show_review_hint' );
				$transient_obj->set_message(
				/* translators: %1$s will be replaced by the plugin name, %2$d is replaced with a day-count, %3$s with the review-URL, %4$s with a hint for screen readers */
					sprintf( __( 'You have used the WordPress-plugin <em>%1$s</em> since more than %2$d days. Do you like it? Feel free to <a href="%3$s" target="_blank">leave us a review%4$s</a>.', 'threadi-now-pages' ), Plugin::get_plugin_name(), (int) floor( ( time() - $install_date ) / DAY_IN_SECONDS ), esc_url( Plugin::get_review_url() ), Plugin::get_a11n_window_hint() ) . ' <span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span><span class="dashicons dashicons-star-filled"></span>',
				);
				$transient_obj->set_type( 'info' );
				$transient_obj->save();
			}
			return;
		}
		Transients::get_instance()->get_transient_by_name( 'threadi_now_pages_admin_show_review_hint' )->delete();
	}

	/**
	 * Collect errors from settings library.
	 *
	 * @return void
	 */
	public function get_setting_errors(): void {
		// get the settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// bail if no errors occurred.
		if ( ! method_exists( $settings_obj, 'has_errors' ) || ! $settings_obj->has_errors() ) { // @phpstan-ignore function.alreadyNarrowedType
			return;
		}

		// get the errors.
		$errors = $settings_obj->get_errors();

		// bail if errors are not set.
		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		// log these errors.
		foreach ( $errors->errors as $key => $messages ) {
			// log each error only once per day: they occur on every request until the cause is fixed.
			$transient_key = 'threadi_now_pages_settings_error_' . md5( $key . implode( ' ', $messages ) );
			if ( false !== get_transient( $transient_key ) ) {
				continue;
			}
			set_transient( $transient_key, 1, DAY_IN_SECONDS );

			Log::get_instance()->add( __( 'Error in plugin settings:', 'threadi-now-pages' ) . ' <em>' . esc_html( (string) $key ) . '</em>: ' . esc_html( implode( ' ', $messages ) ), 'error', 'system' );
		}

		// clear the errors, so they are not logged again on the next request.
		if ( method_exists( $settings_obj, 'clear_errors' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$settings_obj->clear_errors();
		}
	}

	/**
	 * Save any error from the crypt library.
	 *
	 * @param string              $code    The error code.
	 * @param string              $message The message.
	 * @param array<string,mixed> $data    The data.
	 *
	 * @return void
	 */
	public function save_crypt_error( string $code, string $message, array $data ): void {
		// tell the user if the key is gone: every saved key and token has to be entered again.
		if ( in_array( $code, array( 'key_missing', 'key_changed' ), true ) ) {
			$transient_obj = Transients::get_instance()->add();
			$transient_obj->set_name( 'threadi_now_pages_crypt_key_lost' );
			$transient_obj->set_type( 'error' );
			$transient_obj->set_dismissible_days( 30 );
			$transient_obj->set_message(
				'<strong>' . esc_html__( 'Now Pages: the encryption key of this website has changed.', 'threadi-now-pages' ) . '</strong> '
				. esc_html__( 'This usually happens after moving the website or replacing the wp-config.php. API keys and tokens saved in your sources and the webhook token can no longer be read. Please enter them again.', 'threadi-now-pages' )
			);
			$transient_obj->save();
		}

		// log the same error only once per hour: some of them are reported on every request.
		$throttle_key = 'threadi_now_pages_crypt_error_' . md5( $code );
		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		// collect the data for the log entry.
		$log_entry = array(
			__( 'Error Code', 'threadi-now-pages' ) => '<code>' . $code . '</code>',
			__( 'Message', 'threadi-now-pages' )    => '<code>' . $message . '</code>',
			__( 'Data', 'threadi-now-pages' )       => '<code>' . Format::get_json( $data ) . '</code>',
		);

		// log the data.
		Log::get_instance()->add(
			'<strong>' . __( 'Error in encryption:', 'threadi-now-pages' ) . '</strong><br>' . wp_kses_post(
				implode(
					'<br>',
					array_map(
						static fn( $key, $value ) => "$key: $value",
						array_keys( $log_entry ),
						$log_entry
					)
				)
			),
			'error',
			'system'
		);
	}

	/**
	 * Add the error marker to the menu title.
	 *
	 * @param string $title The menu title.
	 *
	 * @return string
	 */
	public function add_error_marker_to_menu_title( string $title ): string {
		// bail if the marker is disabled or the user does not see the page anyway.
		if ( ! current_user_can( 'manage_options' ) || 1 !== absint( get_option( 'threadi_now_pages_enable_log_error_count' ) ) ) {
			return $title;
		}

		// get the error count.
		$errors = absint( get_option( 'threadi_now_pages_log_error_count' ) );

		// bail if there is no error.
		if ( 0 === $errors ) {
			return $title;
		}

		return $title . ' <span class="update-plugins"><span class="update-count">!</span></span>';
	}

	/**
	 * Show the number of waiting entries at the menu item of the entries.
	 *
	 * @return void
	 */
	public function add_error_marker_in_main_menu_title(): void {
		global $menu;

		// bail if there is no menu.
		if ( ! is_array( $menu ) ) {
			return;
		}

		// bail if the marker is disabled or the user does not see the page anyway.
		if ( ! current_user_can( 'manage_options' ) || 1 !== absint( get_option( 'threadi_now_pages_enable_log_error_count' ) ) ) {
			return;
		}

		// get the error count.
		$errors = absint( get_option( 'threadi_now_pages_log_error_count' ) );

		// bail if there is no error.
		if ( 0 === $errors ) {
			return;
		}

		$slug = 'options-general.php';

		foreach ( $menu as $index => $item ) {
			// bail if this is not the menu item of the entries.
			if ( ! is_array( $item ) || ! isset( $item[0], $item[2] ) || $slug !== $item[2] ) {
				continue;
			}
			$menu[ $index ][0] .= ' <span class="update-plugins"><span class="update-count">!</span></span>';
			break;
		}
	}
}
