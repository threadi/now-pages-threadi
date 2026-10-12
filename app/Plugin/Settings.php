<?php
/**
 * This file defines the settings of this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Fields\MultiSelect;
use easySettingsForWordPress\Fields\Number;
use easySettingsForWordPress\Fields\Select;
use easySettingsForWordPress\Fields\Textarea;
use easySettingsForWordPress\Page;
use easySettingsForWordPress\Section;
use easySettingsForWordPress\Tab;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Digests\Summarizer_Stats;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\NowPages\Content_Filters;
use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\Plugin\Schedules\Import;
use ThreadiNowPages\Sources\Sources;
use WP_Roles;
use WP_Screen;
use WP_User;
use WP_User_Query;

/**
 * Object, which handles the settings of this plugin.
 */
class Settings {
	/**
	 * The transient which holds the cached list of users.
	 */
	private const USERS_CACHE_KEY = 'threadi_now_pages_wp_users';
	/**
	 * The menu slug of the settings page.
	 */
	public const MENU_SLUG = 'threadi_now_pages_settings';
	/**
	 * The settings object.
	 *
	 * @var ?\easySettingsForWordPress\Settings
	 */
	private ?\easySettingsForWordPress\Settings $settings_obj = null;

	/**
	 * Instance of actual object.
	 *
	 * @var ?Settings
	 */
	private static ?Settings $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Settings
	 */
	public static function get_instance(): Settings {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize the settings.
	 *
	 * @return void
	 */
	public function init(): void {
		// prepare main settings for this plugin.
		add_action( 'init', array( $this, 'maybe_add_settings' ) );

		// use actions.
		add_action( 'admin_action_threadi_now_pages_reset', array( $this, 'reset_plugin_by_request' ) );
		add_action( 'admin_action_threadi_now_pages_cache_reset', array( $this, 'reset_cache_by_request' ) );
		add_action( 'admin_action_threadi_now_pages_import', array( $this, 'import_by_request' ) );
		add_action( 'wp_ajax_threadi_now_pages_import', array( $this, 'import_by_ajax' ) );

		// misc.
		add_filter( 'admin_footer_text', array( $this, 'show_plugin_hint_in_footer' ), 0 );
		add_filter( 'threadi_now_pages_show_help', array( $this, 'show_help' ), 10, 2 );

		// invalidate the cached list of users.
		add_action( 'set_user_role', array( $this, 'delete_users_cache' ), 10, 0 );
		add_action( 'user_register', array( $this, 'delete_users_cache' ), 10, 0 );
		add_action( 'delete_user', array( $this, 'delete_users_cache' ), 10, 0 );
		add_action( 'profile_update', array( $this, 'delete_users_cache' ), 10, 0 );
	}

	/**
	 * Delete the cached list of users.
	 *
	 * @return void
	 */
	public function delete_users_cache(): void {
		delete_transient( self::USERS_CACHE_KEY );
	}

	/**
	 * Return the configured settings object.
	 *
	 * @return \easySettingsForWordPress\Settings
	 */
	public function get_settings_obj(): \easySettingsForWordPress\Settings {
		/**
		 * Get the object one time.
		 */
		if ( null === $this->settings_obj ) {
			$this->settings_obj = new \easySettingsForWordPress\Settings( NOWPAGES_PLUGIN );
		}

		// return it.
		return $this->settings_obj;
	}

	/**
	 * Return the PHP page the settings will be using.
	 *
	 * @return string
	 */
	private function get_php_page(): string {
		return 'options-general.php';
	}

	/**
	 * Return the link to the settings.
	 *
	 * @param string $tab     The tab.
	 * @param string $sub_tab The sub tab.
	 *
	 * @return string
	 */
	public function get_url( string $tab = '', string $sub_tab = '' ): string {
		// define base array.
		// use our own slug, as the settings object is not configured in every request.
		$array = array(
			'page' => self::MENU_SLUG,
		);

		// add a tab, if set.
		if ( ! empty( $tab ) ) {
			$array['tab'] = $tab;
		}

		// add a sub-tab, if set.
		if ( ! empty( $sub_tab ) ) {
			$array['subtab'] = $sub_tab;
		}

		// return the URL.
		return add_query_arg(
			$array,
			get_admin_url() . $this->get_php_page()
		);
	}

	/**
	 * Add our custom settings for this plugin, if they are needed in this request.
	 *
	 * @return void
	 */
	public function maybe_add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// add the settings.
		$this->add_settings();
	}

	/**
	 * Add our custom settings for this plugin.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		/**
		 * Configure the basic settings object.
		 */
		$this->settings_obj = $this->get_settings_obj();
		$this->settings_obj->set_slug( 'threadi_now_pages' );
		$this->settings_obj->set_plugin_slug( NOWPAGES_PLUGIN );
		$this->settings_obj->set_menu_title( __( 'Now Pages', 'threadi-now-pages' ) );
		$this->settings_obj->set_title( __( 'Settings for Now Pages', 'threadi-now-pages' ) );
		$this->settings_obj->set_menu_slug( self::MENU_SLUG );
		$this->settings_obj->set_menu_parent_slug( $this->get_php_page() );
		$this->settings_obj->set_capability( 'manage_options' );
		$this->settings_obj->show_settings_link_in_plugin_list( true );
		$this->settings_obj->set_translations(
			array(
				'title_settings_import_file_missing' => __( 'A required file is missing', 'threadi-now-pages' ),
				'text_settings_import_file_missing'  => __( 'Please choose a JSON-file with settings to import.', 'threadi-now-pages' ),
				'lbl_ok'                             => __( 'OK', 'threadi-now-pages' ),
				'lbl_cancel'                         => __( 'Cancel', 'threadi-now-pages' ),
				'import_title'                       => __( 'Import', 'threadi-now-pages' ),
				'dialog_import_title'                => __( 'Import plugin settings', 'threadi-now-pages' ),
				'dialog_import_text'                 => __( 'Click on the button below to choose your JSON-file with the settings.', 'threadi-now-pages' ),
				'dialog_import_button'               => __( 'Import now', 'threadi-now-pages' ),
				'dialog_import_error_title'          => __( 'Error during import', 'threadi-now-pages' ),
				'dialog_import_error_text'           => __( 'The file could not be imported!', 'threadi-now-pages' ),
				'dialog_import_error_no_file'        => __( 'No file was uploaded.', 'threadi-now-pages' ),
				'dialog_import_error_no_size'        => __( 'The uploaded file is no size.', 'threadi-now-pages' ),
				'dialog_import_error_no_json'        => __( 'The uploaded file is not a valid JSON-file.', 'threadi-now-pages' ),
				'dialog_import_error_no_json_ext'    => __( 'The uploaded file does not have the file extension <i>.json</i>.', 'threadi-now-pages' ),
				'dialog_import_error_not_saved'      => __( 'The uploaded file could not be saved. Contact your hoster about this problem.', 'threadi-now-pages' ),
				'dialog_import_error_not_our_json'   => __( 'The uploaded file is not a valid JSON-file with settings for this plugin.', 'threadi-now-pages' ),
				'dialog_import_success_title'        => __( 'Settings have been imported', 'threadi-now-pages' ),
				'dialog_import_success_text'         => __( 'Import has been run successfully.', 'threadi-now-pages' ),
				'dialog_import_success_text_2'       => __( 'The new settings are now active. Click on the button below to reload the page and see the settings.', 'threadi-now-pages' ),
				'export_title'                       => __( 'Export', 'threadi-now-pages' ),
				'dialog_export_title'                => __( 'Export plugin settings', 'threadi-now-pages' ),
				'dialog_export_text'                 => __( 'Click on the button below to export the actual settings.', 'threadi-now-pages' ),
				'dialog_export_text_2'               => __( 'You can import this JSON-file in other projects using this WordPress plugin or theme.', 'threadi-now-pages' ),
				'dialog_export_button'               => __( 'Export now', 'threadi-now-pages' ),
				'table_options'                      => __( 'Options', 'threadi-now-pages' ),
				'table_entry'                        => __( 'Entry', 'threadi-now-pages' ),
				'table_no_entries'                   => __( 'No entries found.', 'threadi-now-pages' ),
				'plugin_settings_title'              => __( 'Settings', 'threadi-now-pages' ),
				'file_add_file'                      => __( 'Add file', 'threadi-now-pages' ),
				'file_choose_file'                   => __( 'Choose file', 'threadi-now-pages' ),
				'file_choose_image'                  => __( 'Upload or choose image', 'threadi-now-pages' ),
				'drag_n_drop'                        => __( 'Hold to drag & drop', 'threadi-now-pages' ),
			)
		);
		if ( method_exists( $this->settings_obj, 'set_view' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$this->settings_obj->set_view( get_option( 'threadi_now_pages_settings_view', 'dataview' ) );
		}
		if ( method_exists( $this->settings_obj, 'set_update_version' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$this->settings_obj->set_update_version( NOWPAGES_PLUGIN_VERSION );
		}
		if ( method_exists( $this->settings_obj, 'set_persist_section_collapse' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$this->settings_obj->set_persist_section_collapse( true );
		}

		/**
		 * Get the settings page.
		 */
		$settings_page = $this->settings_obj->get_page( $this->get_settings_obj()->get_menu_slug() );
		if ( ! $settings_page instanceof Page ) {
			return;
		}

		/**
		 * The "Now page" tab.
		 */
		$now_page_tab = $settings_page->add_tab( 'threadi_now_pages_now_page', 10 );
		$now_page_tab->set_title( __( 'Now page', 'threadi-now-pages' ) );

		/**
		 * The display tab.
		 */
		$display_tab = $settings_page->add_tab( 'threadi_now_pages_display', 20 );
		$display_tab->set_title( __( 'Display', 'threadi-now-pages' ) );

		// add section.
		$entries_section = $display_tab->add_section( 'threadi_now_pages_display_entries', 10 );
		$entries_section->set_title( __( 'Entries', 'threadi-now-pages' ) );

		// add section.
		$privacy_section = $display_tab->add_section( 'threadi_now_pages_display_privacy', 20 );
		$privacy_section->set_title( __( 'Privacy', 'threadi-now-pages' ) );

		/**
		 * The import tab.
		 */
		$import_tab = $settings_page->add_tab( 'threadi_now_pages_import', 30 );
		$import_tab->set_title( __( 'Import', 'threadi-now-pages' ) );

		// add section.
		$import_section = $import_tab->add_section( 'threadi_now_pages_import_handling', 10 );
		$import_section->set_title( __( 'Import', 'threadi-now-pages' ) );

		// add section.
		$schedule_section = $import_tab->add_section( 'threadi_now_pages_import_schedule', 20 );
		$schedule_section->set_title( __( 'Schedule', 'threadi-now-pages' ) );

		// add section.
		$filter_section = $import_tab->add_section( 'threadi_now_pages_filter', 30 );
		$filter_section->set_title( __( 'Filter', 'threadi-now-pages' ) );
		if ( method_exists( $filter_section, 'set_collapsed' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$filter_section->set_collapsible( true );
			$filter_section->set_collapsed( true );
		}

		// add section.
		$stats_section = $import_tab->add_section( 'threadi_now_pages_summarizer_stats', 40 );
		$stats_section->set_title( __( 'Summarizer statistics', 'threadi-now-pages' ) );
		$stats_section->set_callback( array( Summarizer_Stats::get_instance(), 'show' ) );
		if ( method_exists( $stats_section, 'set_collapsed' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$stats_section->set_collapsible( true );
			$stats_section->set_collapsed( true );
		}

		/**
		 * The permissions tab.
		 */
		$permissions_tab = $settings_page->add_tab( 'threadi_now_pages_permissions', 40 );
		$permissions_tab->set_title( __( 'Permissions', 'threadi-now-pages' ) );

		// add section.
		$rules_section = $permissions_tab->add_section( 'threadi_now_pages_permissions', 10 );
		$rules_section->set_title( __( 'Permissions', 'threadi-now-pages' ) );

		/**
		 * The advanced tab.
		 */
		$advanced_tab = $settings_page->add_tab( 'threadi_now_pages_advanced', 50 );
		$advanced_tab->set_title( __( 'Advanced', 'threadi-now-pages' ) );

		// add section.
		$settings_section = $advanced_tab->add_section( 'threadi_now_pages_advanced_settings', 10 );
		$settings_section->set_title( __( 'Settings', 'threadi-now-pages' ) );

		// add section.
		$misc_section = $advanced_tab->add_section( 'threadi_now_pages_advanced_misc', 20 );
		$misc_section->set_title( __( 'Miscellaneous', 'threadi-now-pages' ) );

		// add section.
		$debug_section = $advanced_tab->add_section( 'threadi_now_pages_advanced_debug', 30 );
		$debug_section->set_title( __( 'Debug', 'threadi-now-pages' ) );
		$debug_section->set_collapsed( 1 !== absint( get_option( 'threadi_now_pages_debug' ) ) );
		if ( method_exists( $debug_section, 'set_collapsible' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$debug_section->set_collapsible( true );
		}

		// add section.
		$plugin_section = $advanced_tab->add_section( 'threadi_now_pages_advanced_plugin', 40 );
		$plugin_section->set_title( __( 'Plugin handling', 'threadi-now-pages' ) );
		$plugin_section->set_collapsed( true );
		if ( method_exists( $plugin_section, 'set_collapsible' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$plugin_section->set_collapsible( true );
		}

		/**
		 * Add settings to the defined sections.
		 */
		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_display_roles' );
		$setting->set_section( $rules_section );
		$setting->set_type( 'array' );
		$setting->set_default( array() );
		$setting->set_save_callback( array( $this, 'save_user_roles' ) );
		$field = new MultiSelect( $this->get_settings_obj() );
		$field->set_title( __( 'Choose roles', 'threadi-now-pages' ) );
		$field->set_description( __( 'Select roles for users, which could manage their own sources and entries and configure the display settings for their own now pages. Only usable if now pages for users are enabled.', 'threadi-now-pages' ) );
		$field->set_options( self::get_roles( true ) );
		$field->set_readonly( 1 !== absint( get_option( 'threadi_now_pages_frontend_user_url' ) ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_display_allow_users' );
		$setting->set_section( $rules_section );
		$setting->set_type( 'array' );
		$setting->set_default( array() );
		$setting->set_save_callback( array( $this, 'save_user_capabilities' ) );
		$field = new MultiSelect( $this->get_settings_obj() );
		$field->set_title( __( 'Allow single users', 'threadi-now-pages' ) );
		$field->set_description( __( 'Select users, which could manage their own sources and entries and configure the display settings for their own now pages regardless of their role. Only usable if now pages for users are enabled.', 'threadi-now-pages' ) );
		$field->set_options( self::get_users() );
		$field->set_readonly( 1 !== absint( get_option( 'threadi_now_pages_frontend_user_url' ) ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_show_time' );
		$setting->set_section( $entries_section );
		$setting->set_default( 0 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Show times', 'threadi-now-pages' ) );
		$field->set_description( __( 'Prefix each entry with its time of day (e.g., “10:00”).', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting.
		$source_setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_show_source' );
		$source_setting->set_section( $entries_section );
		$source_setting->set_default( 0 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Show data source', 'threadi-now-pages' ) );
		$field->set_description( __( 'Append the name of the originating source in parentheses.', 'threadi-now-pages' ) );
		$source_setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_link_source' );
		$setting->set_section( $entries_section );
		$setting->set_default( 0 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Link the data source', 'threadi-now-pages' ) );
		$field->set_description( __( 'Link each source name to the original entry, if the source provided a URL.', 'threadi-now-pages' ) );
		$field->add_depend( $source_setting, 1 );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_group_by_category' );
		$setting->set_section( $entries_section );
		$setting->set_default( 1 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Group by category', 'threadi-now-pages' ) );
		$field->set_description( __( 'Group entries under category headings for better thematic orientation.', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_order' );
		$setting->set_section( $entries_section );
		$setting->set_type( 'string' );
		$setting->set_default( 'ASC' );
		$field = new Select( $this->get_settings_obj() );
		$field->set_title( __( 'Order of entries', 'threadi-now-pages' ) );
		$field->set_description( __( 'Whether the newest entry is shown first or last. The narrative format always reads chronologically, regardless of this setting.', 'threadi-now-pages' ) );
		$field->set_options(
			array(
				'ASC'  => __( 'Oldest first', 'threadi-now-pages' ),
				'DESC' => __( 'Newest first', 'threadi-now-pages' ),
			)
		);
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_show_period' );
		$setting->set_section( $entries_section );
		$setting->set_default( 1 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Show period of entries', 'threadi-now-pages' ) );
		$field->set_description( __( 'If enabled, the period of entries in the list will be shown.', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_delay_hours' );
		$setting->set_section( $privacy_section );
		$setting->set_type( 'integer' );
		$setting->set_default( 6 );
		$field = new Number( $this->get_settings_obj() );
		$field->set_title( __( 'Delay (hours)', 'threadi-now-pages' ) );
		$field->set_description( __( 'Hide entries that are newer than this many hours. Protects against stalking. Set to 0 for live display.', 'threadi-now-pages' ) );
		$field->set_min( 0 );
		$field->set_max( Display_Settings::MAX_HOURS );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_settings_view' );
		$setting->set_section( $settings_section );
		$setting->set_type( 'string' );
		$setting->set_default( 'dataview' );
		$setting->set_reload_on_save( true );
		$field = new Select( $this->get_settings_obj() );
		$field->set_title( __( 'Settings view', 'threadi-now-pages' ) );
		$field->set_description( __( 'Choose the view for the settings of this plugin.', 'threadi-now-pages' ) );
		$field->set_options(
			array(
				'classic'  => __( 'Classic', 'threadi-now-pages' ),
				'dataview' => __( 'DataView', 'threadi-now-pages' ),
			)
		);
		if ( method_exists( $setting, 'set_reload_on_save' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$setting->set_reload_on_save( true );
		}
		$setting->set_field( $field );

		// add setting.
		$debug_setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_import_now' );
		$debug_setting->set_section( $import_section );
		$debug_setting->set_default( 0 );
		$field = new Button( $this->get_settings_obj() );
		$field->set_title( __( 'Import now', 'threadi-now-pages' ) );
		$field->set_button_title( __( 'Import now', 'threadi-now-pages' ) );
		$field->set_button_url( Plugin::get_import_url() );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$field->add_data( 'dialog', Format::get_json( ProcessHandler::get_instance()->get_import_dialog() ) );
		$field->set_description( __( 'Click on the button to update your entries for your now page. You do not have to do this manually, since the process is automated for you as well.', 'threadi-now-pages' ) );
		$debug_setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_schedule_interval' );
		$setting->set_section( $schedule_section );
		$setting->set_type( 'string' );
		$setting->set_default( 'threadi_now_pages_hourly' );
		$setting->set_save_callback( array( $this, 'update_interval_setting' ) );
		$field = new Select( $this->get_settings_obj() );
		$field->set_title( __( 'Interval for import', 'threadi-now-pages' ) );
		/* translators: %1$s will be replaced by a URL */
		$field->set_description( sprintf( __( 'Defines the time interval in which new entries <a href="%1$s">from configured sources</a> will be imported.', 'threadi-now-pages' ), Sources::get_instance()->get_backend_list_url() ) );
		$field->set_options( Intervals::get_instance()->get_intervals_for_settings() );
		$field->set_sanitize_callback( array( $this, 'sanitize_interval_setting' ) );
		$setting->set_field( $field );

		// add setting.
		$debug_setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_debug' );
		$debug_setting->set_section( $debug_section );
		$debug_setting->set_default( 0 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Debug-Mode', 'threadi-now-pages' ) );
		/* translators: %1$s will be replaced by a URL. */
		$field->set_description( sprintf( __( 'When activated, the plugin logs many processes. This information can then be seen <a href="%1$s">in the log</a>. This helps to analyze any problems that may occur. <strong>We do not recommend using this mode permanently in a productive system.</strong>', 'threadi-now-pages' ), esc_url( $this->get_url( 'logs' ) ) ) );
		$debug_setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( Content_Filters::OPTION );
		$setting->set_section( $filter_section );
		$setting->set_type( 'string' );
		$setting->set_default( '' );
		// check the rules when they are saved, and load the page again so the hint about them is shown.
		$setting->set_save_callback( array( Content_Filters::get_instance(), 'report_site_rules' ) );
		$setting->set_reload_on_save( true );
		$field = new Textarea( $this->get_settings_obj() );
		$field->set_title( __( 'Content filter rules', 'threadi-now-pages' ) );
		$field->set_description(
			__( 'One rule per line, syntax: action:field:operator:value - e.g., include:author:equals:Jane Doe or exclude:text:contains:sponsored. action is "include" (entry is only imported if it matches) or "exclude" (entry is skipped if it matches). field is "text", "url" (the address an entry links to), "source_slug", or a field the chosen provider declares (see the provider\'s own description). operator is one of: equals, not_equals, contains, not_contains, word (the value as a whole word), regex. Several "include" rules for the same field are combined with OR; rules for different fields must all match.', 'threadi-now-pages' )
		);
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_debug_categories' );
		$setting->set_section( $debug_section );
		$setting->set_type( 'array' );
		$setting->set_default( array() );
		$setting->set_show_in_rest( array( 'schema' => array( 'items' => array( 'type' => 'string' ) ) ) );
		$field = new MultiSelect( $this->get_settings_obj() );
		$field->set_title( __( 'Categories to debug', 'threadi-now-pages' ) );
		$field->set_description( __( 'Select the topics for which you want to see debug output in the log. If nothing is selected, everything will be logged.', 'threadi-now-pages' ) );
		$field->set_options( Log::get_instance()->get_categories() );
		$field->add_depend( $debug_setting, 1 );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_enable_log_error_count' );
		$setting->set_section( $settings_section );
		$setting->set_default( 1 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Show error marker', 'threadi-now-pages' ) );
		$field->set_description( __( 'When enabled, a marker appears in the backend menu as soon as any error is logged. Clicking the markers path takes you directly to the log, where you can review the error.', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_max_age_log_entries' );
		$setting->set_section( $misc_section );
		$setting->set_type( 'integer' );
		$setting->set_default( 20 );
		$field = new Number( $this->get_settings_obj() );
		$field->set_title( __( 'max. Age for log entries in days', 'threadi-now-pages' ) );
		$field->set_description( __( 'The higher the value, the more data is collected in the log.', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_hide_review_hints' );
		$setting->set_section( $misc_section );
		$setting->set_default( 0 );
		$field = new Checkbox( $this->get_settings_obj() );
		$field->set_title( __( 'Hide review hints', 'threadi-now-pages' ) );
		$field->set_description( __( 'If this option is enabled, you will no longer see prompts to rate the plugin. Of course, we would still appreciate a positive review :-)', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// the log tab.
		$logs_tab = $settings_page->add_tab( 'logs', 100 );
		$logs_tab->set_title( __( 'Logs', 'threadi-now-pages' ) );
		$logs_tab->set_hide_save( true );
		$logs_tab->set_tab_class( 1 === absint( get_option( 'threadi_now_pages_enable_log_error_count' ) ) && absint( get_option( 'threadi_now_pages_log_error_count' ) ) > 0 ? 'errors' : '' );
		$logs_tab->set_callback( array( $this, 'show_logs' ) );

		// create reset cache URL.
		$reset_cache_url = add_query_arg(
			array(
				'action' => 'threadi_now_pages_cache_reset',
				'nonce'  => wp_create_nonce( 'threadi-now-pages-cache-reset' ),
			),
			get_admin_url() . 'admin.php'
		);

		// create dialog.
		$reset_dialog = array(
			'title'   => __( 'Reset cache', 'threadi-now-pages' ),
			'texts'   => array(
				'<p><strong>' . __( 'Do you really want to clear the plugins internal cache?', 'threadi-now-pages' ) . '</strong></p>',
				'<p>' . __( 'Among other things, this makes it possible to reimport and reuse entries that were previously deleted for the Now Page.', 'threadi-now-pages' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'location.href="' . $reset_cache_url . '";',
					'variant' => 'primary',
					'text'    => __( 'Yes, reset it', 'threadi-now-pages' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'Cancel', 'threadi-now-pages' ),
				),
			),
		);

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_reset_cache' );
		$setting->set_section( $plugin_section );
		$setting->prevent_export( true );
		$field = new Button( $this->get_settings_obj() );
		$field->set_title( __( 'Reset cache', 'threadi-now-pages' ) );
		$field->set_button_title( __( 'Reset cache', 'threadi-now-pages' ) );
		$field->set_button_url( $reset_cache_url );
		$field->add_data( 'dialog', Format::get_json( $reset_dialog ) );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$setting->set_field( $field );

		// create reset URL.
		$reset_plugin_url = add_query_arg(
			array(
				'action' => 'threadi_now_pages_reset',
				'nonce'  => wp_create_nonce( 'threadi-now-pages-reset' ),
			),
			get_admin_url() . 'admin.php'
		);

		// create dialog.
		$reset_dialog = array(
			'title'   => __( 'Reset plugin', 'threadi-now-pages' ),
			'texts'   => array(
				'<p><strong>' . __( 'Do you really want to reset any settings and data for the plugin Now Pages?', 'threadi-now-pages' ) . '</strong></p>',
				'<p>' . __( 'This will not only reset all settings, but also remove all importes entries and associated data.', 'threadi-now-pages' ) . '</p>',
				'<p>' . __( 'You can then set up the plugin again.', 'threadi-now-pages' ) . '</p>',
				'<p><strong>' . __( 'We recommend creating a backup before resetting the plugin.', 'threadi-now-pages' ) . '</strong></p>',
			),
			'buttons' => array(
				array(
					'action'  => 'location.href="' . $reset_plugin_url . '";',
					'variant' => 'primary',
					'text'    => __( 'Yes, reset it', 'threadi-now-pages' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'Cancel', 'threadi-now-pages' ),
				),
			),
		);

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_reset_plugin' );
		$setting->set_section( $plugin_section );
		$setting->prevent_export( true );
		$field = new Button( $this->get_settings_obj() );
		$field->set_title( __( 'Reset plugin', 'threadi-now-pages' ) );
		$field->set_button_title( __( 'Reset plugin', 'threadi-now-pages' ) );
		$field->set_button_url( $reset_plugin_url );
		$field->add_data( 'dialog', Format::get_json( $reset_dialog ) );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$setting->set_field( $field );

		// create a hidden page for hidden settings.
		$hidden_page = $this->get_settings_obj()->add_page( 'hidden_page' );

		// create a hidden tab on this page.
		$hidden_tab = $hidden_page->add_tab( 'hidden_tab', 10 );
		$hidden_tab->set_tab_class( 'nav-tab-hidden' );

		// the hidden section for any not visible settings.
		$hidden = $hidden_tab->add_section( 'hidden_section', 20 );
		$hidden->set_setting( $this->get_settings_obj() );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_log_error_count' );
		$setting->set_section( $hidden );
		$setting->set_type( 'integer' );
		$setting->set_default( 0 );
		$setting->prevent_export( true );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_update_slugs' );
		$setting->set_section( $hidden );
		$setting->set_type( 'integer' );
		$setting->set_default( 0 );
		$setting->prevent_export( true );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_install_date' );
		$setting->set_section( $hidden );
		$setting->set_type( 'integer' );
		$setting->set_default( time() );
		$setting->prevent_export( true );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( Setup::OPTION_CREATE_PAGE );
		$setting->set_section( $hidden );
		$setting->set_type( 'string' );
		$setting->set_default( 'page' );

		// add setting.
		$setting = $this->get_settings_obj()->add_setting( 'threadi_now_pages_schedule_import_status' );
		$setting->set_section( $hidden );
		$setting->set_type( 'integer' );
		$setting->set_default( 1 );
		$setting->prevent_export( true );

		// add our dynamic settings to allow them to autoload.
		foreach ( Display_Settings::option_names() as $name ) {
			// bail if this option is one of the visible settings above: a name can only be used once.
			if ( false !== $this->get_settings_obj()->get_setting( $name ) ) {
				continue;
			}

			// add setting.
			$setting = $this->get_settings_obj()->add_setting( $name );
			$setting->set_section( $hidden );
			$setting->set_type( 'string' );
			$setting->set_default( '' );
			$setting->prevent_export( true );
		}

		// the help tab.
		$help_tab = $settings_page->add_tab( 'help', 1000 );
		$help_tab->set_title( __( 'Questions? Ask here!', 'threadi-now-pages' ) );
		$help_tab->set_tab_class( 'nav-tab-help' );
		$help_tab->set_url( Plugin::get_plugin_support_url() );
		$help_tab->set_url_target( '_blank' );

		// initialize the settings.
		$this->settings_obj->init();
	}

	/**
	 * Sanitize the interval setting.
	 *
	 * @param string|null $value The given value.
	 *
	 * @return string
	 */
	public function sanitize_interval_setting( null|string $value ): string {
		// get option.
		$option = str_replace( 'sanitize_option_', '', (string) current_filter() );

		// get the list of intervals.
		$intervals = wp_get_schedules();

		// get the saved interval, which is kept if the given value is not valid.
		$previous = (string) get_option( $option, '' );
		if ( Intervals::DISABLED !== $previous && empty( $intervals[ $previous ] ) ) {
			$previous = 'threadi_now_pages_hourly';
		}

		// bail if value is empty.
		if ( empty( $value ) ) {
			add_settings_error( $option, $option, __( 'An interval has to be set.', 'threadi-now-pages' ) );
			return $previous;
		}

		// bail if value is the pseudo interval to disable the import.
		if ( Intervals::DISABLED === $value ) {
			return $value;
		}

		// bail if the given interval does not exist.
		if ( empty( $intervals[ $value ] ) ) {
			/* translators: %1$s will be replaced by the name of the used interval */
			add_settings_error( $option, $option, sprintf( __( 'The given interval %1$s does not exist.', 'threadi-now-pages' ), esc_html( $value ) ) );
			return $previous;
		}

		// return the value.
		return $value;
	}

	/**
	 * Update the schedule if interval has been changed.
	 *
	 * @param string|null $value The given value for the interval.
	 *
	 * @return string
	 */
	public function update_interval_setting( string|null $value ): string {
		// if null is given, use a string.
		$value = (string) $value;

		// get queue-schedule-object.
		$queue_schedule = new Import();

		// if new value is the pseudo interval to disable the import, remove the schedule.
		if ( Intervals::DISABLED === $value ) {
			// log this event.
			/* translators: %1$s will be replaced by intervall name. */
			Log::get_instance()->add( sprintf( __( 'Schedule %1$s has been disabled.', 'threadi-now-pages' ), '<em>' . $queue_schedule->get_interval() . '</em>' ), 'info', 'system' );

			// remove schedule.
			$queue_schedule->delete();
		} elseif ( $value !== $queue_schedule->get_interval() ) {
			// log event.
			/* translators: %1$s and %2$s will be replaced by intervall names. */
			Log::get_instance()->add( sprintf( __( 'Queue schedule interval has changed from %1$s to %2$s.', 'threadi-now-pages' ), '<em>' . $queue_schedule->get_interval() . '</em>', '<em>' . $value . '</em>' ), 'info', 'system' );

			// set the new interval.
			$queue_schedule->set_interval( $value );

			// reset the schedule.
			$queue_schedule->reset();
		}

		// return the new value to save it via WP.
		return $value;
	}

	/**
	 * Output the log table.
	 *
	 * @return void
	 */
	public static function show_logs(): void {
		// bail if the user has not the capability for this.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// get page from request.
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// if WP_List_Table is not loaded automatically, we need to load it.
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		// get the table object.
		$log = new Log_Table();
		$log->prepare_items();

		// output.
		?>
		<h2><?php echo esc_html__( 'Logs', 'threadi-now-pages' ); ?></h2>
		<form action="<?php echo esc_url( get_admin_url() . self::get_instance()->get_php_page() ); ?>" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
			<input type="hidden" name="tab" value="logs">
			<?php
			$log->views();
			$log->display();
			?>
		</form>
		<?php
	}

	/**
	 * Return the hidden section of the settings object.
	 *
	 * @return false|Section
	 */
	public function get_hidden_section(): Section|false {
		// get settings object.
		$settings_obj = $this->get_settings_obj();

		// create a hidden page for hidden settings.
		$hidden_page = $settings_obj->get_page( 'hidden_page' );

		// bail if the page could not be found.
		if ( ! $hidden_page instanceof Page ) {
			return false;
		}

		// create a hidden tab on this page.
		$hidden_tab = $hidden_page->get_tab( 'hidden_tab' );

		// bail if the tab could not be found.
		if ( ! $hidden_tab instanceof Tab ) {
			return false;
		}

		// the hidden section for any not visible settings.
		$hidden_section = $hidden_tab->get_section( 'hidden_section' );

		// bail if the section could not be found.
		if ( ! $hidden_section instanceof Section ) {
			return false;
		}

		// return the hidden section object.
		return $hidden_section;
	}

	/**
	 * Return roles except the administrator role, if set.
	 *
	 * @param bool $except_administrator True to hide administrator in list.
	 *
	 * @return array<string,string>
	 */
	public static function get_roles( bool $except_administrator = false ): array {
		global $wp_roles;

		// bail if WP_Roles could not be loaded.
		if ( ! $wp_roles instanceof WP_Roles ) {
			return array();
		}

		// collect the roles.
		$roles = array();

		// add each role to the list except the admin role.
		foreach ( $wp_roles->roles as $role => $role_settings ) {
			// bail if this is the admin role.
			if ( $except_administrator && 'administrator' === $role ) {
				continue;
			}

			// add the role.
			$roles[ $role ] = $role_settings['name'];
		}

		// @phpstan-ignore return.type
		return $roles;
	}

	/**
	 * Reset the plugin by request.
	 *
	 * @return void
	 */
	public function reset_plugin_by_request(): void {
		// check nonce.
		check_admin_referer( 'threadi-now-pages-reset', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( $this->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// uninstall all.
		Uninstallation::get_instance()->run();

		// run installer tasks.
		Activation::get_instance()->run();

		// forward user to the dashboard.
		wp_safe_redirect( get_admin_url() );
		exit;
	}

	/**
	 * Reset the cache by request.
	 *
	 * @return void
	 */
	public function reset_cache_by_request(): void {
		// check nonce.
		check_admin_referer( 'threadi-now-pages-cache-reset', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( $this->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// reset the cache for deleted entries.
		delete_option( Entries::OPTION_DELETED );
		// the hook on deleting the option does not fire if it was never saved: forget the pending ones, too.
		Entries::get_instance()->forget_pending_deletions();
		delete_option( Entries::OPTION_CLEANED_BEFORE );

		// log this event.
		Log::get_instance()->add( __( 'Cleared the list to remember deletion of entries', 'threadi-now-pages' ), 'info', 'system' );

		// forward user to the dashboard.
		wp_safe_redirect( (string) wp_get_referer() );
		exit;
	}

	/**
	 * Import entries by request.
	 *
	 * @return void
	 */
	public function import_by_request(): void {
		// check nonce.
		check_admin_referer( 'threadi-now-pages-import', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( $this->get_settings_obj()->get_capability() ) ) {
			return;
		}

		// import them.
		Sources::get_instance()->import();

		// forward user.
		wp_safe_redirect( (string) wp_get_referer() );
		exit;
	}

	/**
	 * Import by AJAX-request.
	 *
	 * @return void
	 */
	public function import_by_ajax(): void {
		// check nonce.
		check_ajax_referer( 'threadi-now-pages-import-start', 'nonce' );

		// bail if capability is missing.
		if ( ! current_user_can( $this->get_settings_obj()->get_capability() ) ) {
			wp_send_json_error();
		}

		// get the process ID from the request.
		$process_id = filter_input( INPUT_POST, 'process_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( is_null( $process_id ) ) {
			$process_id = '';
		}

		// import them.
		Sources::get_instance()->import( $process_id );

		// response with ok.
		wp_send_json_success();
	}

	/**
	 * Return the default of a display setting in the format the library expects.
	 *
	 * Display_Settings::defaults() is the single source of truth. A value
	 * that differs here makes update_option() treat the first save as a
	 * no-op, and the setting silently never takes effect.
	 *
	 * @param string $key The setting key, see Display_Settings::defaults().
	 *
	 * @return int|string
	 */
	public function get_display_default( string $key ): int|string {
		$default = Display_Settings::defaults()[ $key ] ?? false;

		return is_bool( $default ) ? (int) $default : $default;
	}

	/**
	 * Return the settings page object.
	 *
	 * @return Page
	 */
	public function get_settings_page(): Page {
		// get the settings object.
		$settings_obj = self::get_instance()->get_settings_obj();

		// get the settings page.
		$settings_page = $settings_obj->get_page( $settings_obj->get_menu_slug() );
		if ( ! $settings_page instanceof Page ) {
			$settings_page = new Page( $settings_obj );
			$settings_page->set_name( $settings_obj->get_menu_slug() );
			return $settings_page;
		}

		// return the settings page.
		return $settings_page;
	}

	/**
	 * Save capabilities for selected user roles.
	 *
	 * @param mixed $values List of roles.
	 *
	 * @return array<int,string>
	 */
	public function save_user_roles( mixed $values ): array {
		// bail if the field was readonly: keep the saved roles and their capabilities.
		if ( $this->is_readonly_submission( 'threadi_now_pages_display_roles' ) ) {
			return $this->get_saved_list( 'threadi_now_pages_display_roles' );
		}

		// use only existing roles.
		$valid_roles = array_keys( wp_roles()->roles );
		$roles       = array();
		foreach ( $this->get_array_from_field_value( $values ) as $role ) {
			if ( is_string( $role ) && in_array( $role, $valid_roles, true ) ) {
				$roles[] = $role;
			}
		}
		$values = $roles;

		// set or remove the capabilities per role. our own role and the
		// administrator always keep their capabilities.
		foreach ( $valid_roles as $role ) {
			if ( in_array( $role, array( 'administrator', 'manage_threadi_now_pages' ), true ) ) {
				continue;
			}

			if ( in_array( $role, $values, true ) ) {
				Capabilities::get_instance()->set_capabilities_for_role( $role );
				continue;
			}

			Capabilities::get_instance()->remove_capabilities_for_role( $role );
		}

		// return the list of roles.
		return $values;
	}

	/**
	 * Return the value of a multiselect field as array.
	 *
	 * @param mixed $values The submitted value.
	 *
	 * @return array<int|string,mixed>
	 */
	private function get_array_from_field_value( mixed $values ): array {
		if ( is_string( $values ) && '' !== $values ) {
			$values = json_decode( $values, true );
		}

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Return whether a multiselect field was submitted in readonly mode.
	 *
	 * A readonly multiselect is disabled and sends a hidden field with the
	 * JSON of the saved values instead, which the field sanitizer turns into
	 * an empty array before our save callback runs. Without this check every
	 * save with readonly fields would empty the list and remove all capabilities.
	 *
	 * @param string $name The name of the setting.
	 *
	 * @return bool
	 */
	private function is_readonly_submission( string $name ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by WordPress (options.php) before the options are saved.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] );
	}

	/**
	 * Return the saved list of a multiselect setting.
	 *
	 * @param string $name The name of the setting.
	 *
	 * @return array<int,string>
	 */
	private function get_saved_list( string $name ): array {
		$list = array();
		foreach ( $this->get_array_from_field_value( get_option( $name, array() ) ) as $value ) {
			if ( is_scalar( $value ) ) {
				$list[] = (string) $value;
			}
		}

		return $list;
	}

	/**
	 * Return list of users for the settings.
	 *
	 * @return array<int,string>
	 */
	private static function get_users(): array {
		// bail in frontend, but not in REST requests: the settings view saves via REST and the field validation needs the list.
		if ( ! is_admin() && ! Plugin::is_rest_request() ) {
			return array();
		}

		$cache_key = self::USERS_CACHE_KEY;
		$users     = get_transient( $cache_key );

		// bail if we have a cached list.
		if ( is_array( $users ) ) {
			return $users;
		}

		// get the users.
		$query   = array(
			'capability' => array( 'edit_posts' ),
			'fields'     => array( 'ID', 'display_name' ),
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'number'     => 100,
		);
		$results = new WP_User_Query( $query );

		$users = array();
		foreach ( $results->get_results() as $user ) {
			$users[ $user->ID ] = $user->display_name;
		}

		set_transient( $cache_key, $users, WEEK_IN_SECONDS );

		// return the users.
		return $users;
	}

	/**
	 * Save the capabilities for single users.
	 *
	 * @param mixed $values List of users.
	 *
	 * @return array<int,string>
	 */
	public function save_user_capabilities( mixed $values ): array {
		// bail if the field was readonly: keep the saved users and their capabilities.
		if ( $this->is_readonly_submission( 'threadi_now_pages_display_allow_users' ) ) {
			return $this->get_saved_list( 'threadi_now_pages_display_allow_users' );
		}

		// get the values as list of IDs of existing users.
		$user_ids = array();
		foreach ( $this->get_array_from_field_value( $values ) as $user_id ) {
			if ( is_numeric( $user_id ) && absint( $user_id ) > 0 && get_user_by( 'id', absint( $user_id ) ) instanceof WP_User ) {
				$user_ids[] = (string) absint( $user_id );
			}
		}
		$values = $user_ids;

		// get the old list.
		$old_list = get_option( 'threadi_now_pages_display_allow_users' );
		if ( ! is_array( $old_list ) ) {
			$old_list = array();
		}

		// remove the capabilities from the users on the old list.
		foreach ( $old_list as $user_id ) {
			// get the user object.
			$user = get_user_by( 'id', $user_id );

			// bail if user could not be found.
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			// remove the capabilities for this user.
			Capabilities::get_instance()->remove_capabilities_for_user( $user );
		}

		// set capabilities for the selected users.
		foreach ( $values as $user_id ) {
			// get the user object.
			$user = get_user_by( 'id', absint( $user_id ) );

			// bail if user could not be found.
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			// add capability.
			Capabilities::get_instance()->set_capabilities_for_user( $user );
		}

		// return the value for the setting.
		return $values;
	}

	/**
	 * Show hint in footer.
	 *
	 * @param string $content The actual footer content.
	 *
	 * @return string
	 */
	public function show_plugin_hint_in_footer( string $content ): string {
		global $pagenow;

		// bail if this is not the settings page.
		if ( $this->get_php_page() !== $pagenow ) {
			return $content;
		}

		// bail if the requested page is not ours.
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $this->get_settings_obj()->get_menu_slug() !== $page ) {
			return $content;
		}

		// show hint for our plugin.
		/* translators: %1$s will be replaced by the plugin name. */
		return $content . ' ' . sprintf( __( 'This page is provided by the plugin %1$s.', 'threadi-now-pages' ), '<em>' . Plugin::get_plugin_name() . '</em>' );
	}

	/**
	 * Return to show the help if this post type is called.
	 *
	 * @param bool      $allowed The decision.
	 * @param WP_Screen $screen The called screen.
	 *
	 * @return bool
	 */
	public function show_help( bool $allowed, WP_Screen $screen ): bool {
		// bail if it is already allowed.
		if ( $allowed ) {
			return true;
		}

		// return to show this screen if the post type is called.
		return ! empty( $screen->id ) && 'settings_page_threadi_now_pages_settings' === $screen->id;
	}
}
