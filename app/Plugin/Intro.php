<?php
/**
 * File to handle intro for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Sources\Sources;
use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Page;
use easySettingsForWordPress\Section;
use easySettingsForWordPress\Tab;

/**
 * Initialize this object.
 */
class Intro {
	/**
	 * Instance of this object.
	 *
	 * @var ?Intro
	 */
	private static ?Intro $instance = null;

	/**
	 * Constructor for this handler.
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
	public static function get_instance(): Intro {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// add admin-actions.
		add_action( 'admin_action_now_pages_threadi_reset_intro', array( $this, 'reset_intro' ) );

		// add settings.
		add_action( 'init', array( $this, 'add_the_settings' ), 20 );

		// bail if intro has been run.
		if ( $this->is_closed() ) {
			return;
		}

		$false = false;
		/**
		 * Hide intro via hook.
		 *
		 * @since 1.0.0 Available since 1.0.0
		 *
		 * @param bool $false Return true to hide the intro.
		 * @noinspection PhpConditionAlreadyCheckedInspection
		 */
		if ( apply_filters( 'now_pages_threadi_hide_intro', $false ) ) {
			return;
		}

		// use hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'add_js' ) );
		add_filter( 'admin_body_class', array( $this, 'add_body_classes' ) );

		// add AJAX-actions.
		add_action( 'wp_ajax_now_pages_threadi_intro_closed', array( $this, 'set_closed_via_ajax' ) );
	}

	/**
	 * Return whether the intro is closed.
	 *
	 * @return bool
	 */
	private function is_closed(): bool {
		return 1 === absint( get_option( 'now_pages_threadi_intro' ) );
	}

	/**
	 * Set the intro to be closed.
	 *
	 * @return void
	 */
	public function set_closed(): void {
		update_option( 'now_pages_threadi_intro', 1 );
	}

	/**
	 * Save that intro has been closed via AJAX.
	 *
	 * @return void
	 */
	public function set_closed_via_ajax(): void {
		// check nonce.
		check_ajax_referer( 'now-pages-threadi-intro-closed', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// save that intro has been closed.
		$this->set_closed();
	}

	/**
	 * Add the intro.js-scripts and -styles.
	 *
	 * @param string $hook The used hook.
	 *
	 * @source https://introjs.com/docs/examples/basic/hello-world
	 *
	 * @return void
	 */
	public function add_js( string $hook ): void {
		// bail on any other page than the list of sources.
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// bail if the user is not allowed to close the intro or to open the steps it shows.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		if ( Sources::get_instance()->get_slug() !== get_query_var( 'post_type' ) ) {
			return;
		}

		// embed the necessary scripts for the tour.
		$path = Plugin::get_plugin_path() . 'admin/driver/';
		$url  = Plugin::get_plugin_url() . 'admin/driver/';

		// bail if the library is not installed.
		if ( ! file_exists( $path . 'driver.js.iife.js' ) ) {
			return;
		}

		// embed the JS from "driver.js".
		wp_enqueue_script(
			'now-pages-threadi-intro',
			$url . 'driver.js.iife.js',
			array(),
			Plugin::get_file_version( $path . 'driver.js.iife.js' ),
			true
		);

		// embed our own JS script.
		wp_enqueue_script(
			'now-pages-threadi-intro-custom',
			Plugin::get_plugin_url() . 'admin/intro.js',
			array( 'now-pages-threadi-intro' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/intro.js' ),
			true
		);

		// embed the CSS from "driver.js".
		wp_enqueue_style(
			'now-pages-threadi-intro',
			$url . 'driver.css',
			array(),
			Plugin::get_file_version( $path . 'driver.css' )
		);

		// get category URL.
		$category_url = add_query_arg(
			array(
				'taxonomy'  => Categories::get_instance()->get_slug(),
				'post_type' => Entries::get_instance()->get_slug(),
			),
			get_admin_url() . 'edit.php'
		);

		// add php-vars to our js-script.
		wp_localize_script(
			'now-pages-threadi-intro-custom',
			'nowPagesThreadiIntroVars',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'intro_closed_nonce' => wp_create_nonce( 'now-pages-threadi-intro-closed' ),
				'next_button'        => __( 'Next', 'now-pages-threadi' ),
				'prev_button'        => __( 'Back', 'now-pages-threadi' ),
				'done_button'        => __( 'Done', 'now-pages-threadi' ),
				'steps'              => array(
					array(
						'element'     => '',
						'title'       => __( 'Welcome', 'now-pages-threadi' ),
						'description' => __( 'Thank you for installing <em>Now Pages</em>. Let us show you the few things worth knowing.', 'now-pages-threadi' ),
					),
					array(
						'element'     => '.wp-list-table',
						'title'       => __( 'Your sources', 'now-pages-threadi' ),
						'description' => __( 'This is the source your setup created. Entries for your Now Page are imported from these sources automatically.', 'now-pages-threadi' ),
					),
					array(
						'element'     => '.page-title-action:last-of-type',
						'title'       => __( 'More sources', 'now-pages-threadi' ),
						'description' => __( 'Add another service here - we will suggest what fits and prefill it for you.', 'now-pages-threadi' ),
					),
					array(
						'element'     => '#menu-posts-' . Sources::get_instance()->get_slug() . ' a[href*="now_pages_threadi_category"]',
						'title'       => __( 'Categories', 'now-pages-threadi' ),
						/* translators: %1$s will be replaced by a URL. */
						'description' => sprintf( __( 'Group your entries by topic - and let <a href="%1$s" target="_blank">a category</a> replace its entries with one or two sentences of your own. That is what turns a list into a page about your life.', 'now-pages-threadi' ), $category_url ),
					),
					array(
						'element'     => '#menu-settings a[href*="' . Settings::get_instance()->get_settings_obj()->get_menu_slug() . '"]',
						'title'       => __( 'Settings', 'now-pages-threadi' ),
						'description' => __( 'Period, format and what each entry shows - all of it lives here. You can also preview your now page before anybody else sees it.', 'now-pages-threadi' ),
						'open_menu'   => '#menu-settings',
					),
					array(
						'element'     => '',
						'title'       => __( 'Thank you', 'now-pages-threadi' ),
						/* translators: %1$s and %2$s will be replaced by URLs. */
						'description' => sprintf( __( 'Questions belong in <a href="%1$s" target="_blank">our support forum</a>, and <a href="%2$s" target="_blank">a review</a> is always welcome.', 'now-pages-threadi' ), Plugin::get_plugin_support_url(), Plugin::get_review_url() ),
					),
				),
			)
		);
	}

	/**
	 * Reset intro via request.
	 *
	 * @return void
	 */
	public function reset_intro(): void {
		// check nonce.
		check_ajax_referer( 'now-pages-threadi-intro-reset', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// delete the actual setting.
		delete_option( 'now_pages_threadi_intro' );

		// redirect user to intro-start.
		wp_safe_redirect( $this->get_start_url() );
		exit;
	}

	/**
	 * Return the URL where set setup starts.
	 *
	 * @return string
	 */
	public function get_start_url(): string {
		return Sources::get_instance()->get_backend_list_url();
	}

	/**
	 * Add settings for the intro.
	 * *
	 *
	 * @return void
	 */
	public function add_the_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// get settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the main settings page.
		$main_settings_page = Settings::get_instance()->get_settings_page();

		// get the advanced tab.
		$advanced_tab = $main_settings_page->get_tab( 'now_pages_threadi_advanced' );

		// bail if the page could not be loaded.
		if ( ! $advanced_tab instanceof Tab ) {
			return;
		}

		// get the advanced section.
		$advanced_section = $advanced_tab->get_section( 'now_pages_threadi_advanced_plugin' );

		// bail if the section could not be loaded.
		if ( ! $advanced_section instanceof Section ) {
			return;
		}

		// create dialog.
		$dialog = array(
			'title'   => __( 'Reset intro', 'now-pages-threadi' ),
			'texts'   => array(
				'<p><strong>' . __( 'Are your sure you want to reset the intro?', 'now-pages-threadi' ) . '</strong></p>',
			),
			'buttons' => array(
				array(
					'action'  => 'location.href="' . $this->get_reset_url() . '";',
					'variant' => 'primary',
					'text'    => __( 'Yes', 'now-pages-threadi' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'secondary',
					'text'    => __( 'No', 'now-pages-threadi' ),
				),
			),
		);

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_reset_intro' );
		$setting->set_section( $advanced_section );
		$setting->set_autoload( false );
		$setting->prevent_export( true );
		$field = new Button( $settings_obj );
		$field->set_title( __( 'Reset intro', 'now-pages-threadi' ) );
		$field->set_button_title( __( 'Rerun the intro', 'now-pages-threadi' ) );
		$field->set_button_url( $this->get_reset_url() );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$field->add_data( 'dialog', Format::get_json( $dialog ) );
		$setting->set_field( $field );

		// get hidden section.
		$hidden_section = Settings::get_instance()->get_hidden_section();

		// bail if the hidden section could not be found.
		if ( ! $hidden_section instanceof Section ) {
			return;
		}

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_intro' );
		$setting->set_section( $hidden_section );
		$setting->set_show_in_rest( true );
		$setting->set_type( 'integer' );
		$setting->set_default( 0 );
	}

	/**
	 * Return the URL to reset the intro.
	 *
	 * @return string
	 */
	private function get_reset_url(): string {
		return add_query_arg(
			array(
				'action' => 'now_pages_threadi_reset_intro',
				'nonce'  => wp_create_nonce( 'now-pages-threadi-intro-reset' ),
			),
			get_admin_url() . 'admin.php'
		);
	}

	/**
	 * Add custom classes to body-tag.
	 *
	 * @param string $classes List of classes.
	 *
	 * @return string
	 */
	public function add_body_classes( string $classes ): string {
		if ( $this->is_closed() ) {
			return $classes;
		}

		// bail if the user is not allowed to see the intro.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return $classes;
		}

		// add our class to mark to show the intro.
		$classes .= ' now-pages-threadi-show-intro';

		// return the resulting list of classes.
		return $classes;
	}
}
