<?php
/**
 * Public /now and /author/{name}/now routes.
 *
 * Renders through the theme (get_header / get_footer) via template_include.
 * Themes may override with threadi-now-pages.php or now.php in the theme root.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Fields\PermalinkSlug;
use easySettingsForWordPress\Fields\Select;
use easySettingsForWordPress\Page;
use easySettingsForWordPress\Tab;
use ThreadiNowPages\Digests\Diagnostics;
use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Digests\Summarizers;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\PageBuilder\Gutenberg;
use ThreadiNowPages\Plugin\Init;
use ThreadiNowPages\Plugin\Settings;
use ThreadiNowPages\Plugin\Setup;
use WP_Block_Template;
use WP_Post;
use WP_Query;
use WP_Rewrite;
use WP_User;

/**
 * Registers rewrite rules and loads a theme-compatible template for /now.
 */
class Frontend implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Query var that marks a “now” request.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'threadi_now_pages_now';

	/**
	 * Version of our rewrite rules. Change it whenever the rules change, so they
	 * are flushed once after an update.
	 *
	 * @var string
	 */
	public const REWRITE_VERSION = '2';

	/**
	 * Resolved digest HTML for the current request (set in template_redirect).
	 *
	 * @var string
	 */
	private string $content = '';

	/**
	 * Digest_Builder args for the current request.
	 *
	 * @var array<string,mixed>
	 */
	private array $args = array();

	/**
	 * Author for /author/…/now, if any.
	 *
	 * @var WP_User|null
	 */
	private ?WP_User $user = null;

	/**
	 * The builder used for the current request.
	 *
	 * @var ?Digest_Builder
	 */
	private ?Digest_Builder $builder = null;

	/**
	 * Whether the current request has been answered with a 404.
	 *
	 * @var bool
	 */
	private bool $not_found = false;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'init', array( $this, 'add_settings' ), 20 );
		add_action( 'admin_init', array( $this, 'save_slug' ) );
		add_action( 'wp_sitemaps_init', array( $this, 'add_sitemap_provider' ) );

		// use hooks to allow the availability.
		add_action( 'wp', array( $this, 'update_slugs' ) );
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'prepare' ) );
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_filter( 'document_title_parts', array( $this, 'document_title' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'threadi_now_pages_dashboard_widgets', array( $this, 'add_dashboard_widget' ) );

		// hide the page behind the now route, if it is not public.
		add_action( 'template_redirect', array( $this, 'hide_now_page' ), 5 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_now_page_from_sitemap' ), 10, 2 );

		// prevent the listing of users just because of their entries.
		add_filter( 'rest_user_query', array( $this, 'filter_rest_user_query' ), 10, 2 );
		add_filter( 'get_usernumposts', array( $this, 'filter_user_post_count' ), 10, 4 );

		// clear the cached sitemap entries on relevant changes.
		add_action( 'added_user_meta', array( $this, 'clear_sitemap_cache_on_meta_change' ), 10, 3 );
		add_action( 'updated_user_meta', array( $this, 'clear_sitemap_cache_on_meta_change' ), 10, 3 );
		add_action( 'deleted_user_meta', array( $this, 'clear_sitemap_cache_on_meta_change' ), 10, 3 );
		foreach ( array( 'threadi_now_pages_frontend_global_url', 'threadi_now_pages_frontend_user_url', 'threadi_now_pages_slug', 'threadi_now_pages_delay_hours', 'threadi_now_pages_disable_now_page', 'threadi_now_pages_frontend_period', 'threadi_now_pages_frontend_format', Now_Page::OPTION ) as $option ) {
			add_action( 'update_option_' . $option, array( Sitemap::class, 'clear_cache' ) );
		}

		// use actions.
		add_action( 'admin_action_threadi_now_pages_create_page', array( $this, 'create_page' ) );
	}

	/**
	 * Add template settings.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// get settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the settings page.
		$settings_page = $settings_obj->get_page( $settings_obj->get_menu_slug() );
		if ( ! $settings_page instanceof Page ) {
			return;
		}

		// add tab for frontend settings.
		$now_page_tab = $settings_page->get_tab( 'threadi_now_pages_now_page' );
		if ( ! $now_page_tab instanceof Tab ) {
			return;
		}

		// add section.
		$availability_section = $now_page_tab->add_section( 'threadi_now_pages_availability', 10 );
		$availability_section->set_title( __( 'Availability', 'threadi-now-pages' ) );
		$availability_section->set_callback( array( $this, 'show_availability_hint' ) );

		// add section.
		$content_section = $now_page_tab->add_section( 'threadi_now_pages_content', 20 );
		$content_section->set_title( __( 'Content', 'threadi-now-pages' ) );

		// add section.
		$preview_section = $now_page_tab->add_section( 'threadi_now_pages_preview', 30 );
		$preview_section->set_title( __( 'Preview', 'threadi-now-pages' ) );
		$preview_section->set_callback( array( $this, 'show_preview' ) );
		if ( method_exists( $preview_section, 'set_collapsible' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$preview_section->set_collapsible( true );
		}

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_frontend_period' );
		$setting->set_section( $content_section );
		$setting->set_type( 'string' );
		$setting->set_default( 'live' );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Period', 'threadi-now-pages' ) );
		$field->set_description( __( 'Specifies the period covered by the Now page. "Live" shows the last 24 hours; the other values show the current day, hour, week or month.', 'threadi-now-pages' ) );
		$field->set_options( Digests::get_instance()->get_periods() );
		$setting->set_field( $field );

		// get the possible formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = $format->get_label();
		}

		// add setting.
		$format_setting = $settings_obj->add_setting( 'threadi_now_pages_frontend_format' );
		$format_setting->set_section( $content_section );
		$format_setting->set_type( 'string' );
		$format_setting->set_default( 'verbatim' );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Format', 'threadi-now-pages' ) );
		$field->set_description( __( 'Defines how entries are rendered on the now page.<br>"1:1 list" lists them chronologically, one line per entry.<br>"Narrative" has the configured summarizer turn them into a continuous text.<br>This setting only applies to the generic Now Page.', 'threadi-now-pages' ) );
		$field->set_options( $formats );
		$format_setting->set_field( $field );

		$summarizers = array();
		$hints       = array();
		foreach ( Summarizers::get_instance()->get_summarizers() as $summarizer ) {
			$label = $summarizer->get_label();

			// mark summarizers which cannot run on this site.
			if ( ! $summarizer->is_available() ) {
				$label .= ' - ' . __( 'no provider configured', 'threadi-now-pages' );
				$reason = $summarizer->get_unavailable_reason();
				if ( '' !== $reason && get_option( 'threadi_now_pages_summarizer' ) === $summarizer->get_slug() ) {
					$hints[] = '<div class="threadi-now-pages-warning">' . wp_kses_post( $reason ) . '</div>';
				}
			}

			$summarizers[ $summarizer->get_slug() ] = $label;
		}

		// build the description, including the hints.
		$description = __( 'Defines, which service turns imported entries into a continuous text.', 'threadi-now-pages' );
		foreach ( $hints as $hint ) {
			$description .= '<br><br>' . $hint;
		}

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_summarizer' );
		$setting->set_section( $content_section );
		$setting->set_type( 'string' );
		$setting->set_default( 'template' );
		$setting->set_soft_reload_on_save( true );
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Default summarizer', 'threadi-now-pages' ) );
		$field->set_description( $description );
		$field->set_options( $summarizers );
		$field->add_depend( $format_setting, 'narrative' );
		$setting->set_field( $field );

		// add setting.
		$global_url_setting = $settings_obj->add_setting( 'threadi_now_pages_frontend_global_url' );
		$global_url_setting->set_section( $availability_section );
		$global_url_setting->set_default( 1 );
		$global_url_setting->set_save_callback( array( $this, 'save_url_slugs' ) );
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'Global URL', 'threadi-now-pages' ) );
		/* translators: %1$s will be replaced by a URL */
		$field->set_description( sprintf( __( 'If enabled, your now page will be available under <a href="%1$s" target="_blank">%1$s%2$s</a>.', 'threadi-now-pages' ), $this->get_global_now_page_url(), Plugin::get_a11n_window_hint() ) );
		$global_url_setting->set_field( $field );

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_frontend_user_url' );
		$setting->set_section( $availability_section );
		$setting->set_default( 0 );
		$setting->set_save_callback( array( $this, 'save_url_slugs' ) );
		if ( method_exists( $setting, 'set_soft_reload_on_save' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$setting->set_soft_reload_on_save( true );
		}
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'User URL', 'threadi-now-pages' ) );
		$field->set_description( __( 'If enabled, every user will get their own "now page" under their profile URL. They can opt out in their profile settings, if you allow this in the permissions.', 'threadi-now-pages' ) );
		$setting->set_field( $field );

		// add setting to show which page backs the route, if any.
		$setting = $settings_obj->add_setting( Now_Page::OPTION );
		$setting->set_section( $availability_section );
		$setting->set_type( 'integer' );
		$setting->set_default( 0 );
		$setting->prevent_export( true );
		$setting->set_save_callback( array( $this, 'save_page_id' ) );
		if ( method_exists( $setting, 'set_soft_reload_on_save' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$setting->set_soft_reload_on_save( true );
		}
		$field = new Select( $settings_obj );
		$field->set_title( __( 'Page for the Now Page', 'threadi-now-pages' ) );
		$field->set_description( __( 'Without a page the route is virtual and rendered by the plugin. With one, it is a normal page, so your theme and your page builder can design it.', 'threadi-now-pages' ) );
		$field->set_options( $this->get_page_options() );
		$field->add_depend( $global_url_setting, 1 );
		$setting->set_field( $field );

		// create the URL to create a page.
		$create_page_url = add_query_arg(
			array(
				'action' => 'threadi_now_pages_create_page',
				'nonce'  => wp_create_nonce( 'threadi-now-pages-create-page' ),
			),
			get_admin_url() . 'admin.php'
		);

		// create dialog.
		$dialog = array(
			'title'   => __( 'Create a new Now Page', 'threadi-now-pages' ),
			'texts'   => array(
				'<p><strong>' . __( 'Do you really want to create a new Now Page?', 'threadi-now-pages' ) . '</strong></p>',
				'<p>' . __( 'We will create the page for you using the block, widget or shortcode provided by your PageBuilder. You can start using it right away and continue customizing it.', 'threadi-now-pages' ) . '</p>',
				'<p>' . __( 'If a Now page already exists, it will neither be modified nor removed, and no new one will be created.', 'threadi-now-pages' ) . '</p>',
			),
			'buttons' => array(
				array(
					'action'  => 'location.href="' . $create_page_url . '";',
					'variant' => 'primary',
					'text'    => __( 'Yes, create it', 'threadi-now-pages' ),
				),
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'Cancel', 'threadi-now-pages' ),
				),
			),
		);

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_create_page' );
		$setting->set_section( $availability_section );
		$setting->prevent_export( true );
		$field = new Button( $settings_obj );
		$field->set_title( __( 'New page', 'threadi-now-pages' ) );
		$field->set_button_title( __( 'Create page', 'threadi-now-pages' ) );
		$field->set_button_url( $create_page_url );
		$field->add_data( 'dialog', Format::get_json( $dialog ) );
		$field->add_class( 'easy-dialog-for-wordpress' );
		$field->add_depend( $global_url_setting, 1 );
		$setting->set_field( $field );

		// get the WP-own permalink settings page.
		$permalink_page = $settings_obj->add_page( 'permalink' );

		// add a pseudo-tab on this page.
		$permalinks_tab = $permalink_page->add_tab( 'permalink', 10 );

		// add the section in this tab.
		$permalink_section = $permalinks_tab->add_section( 'threadi_now_pages', 10 );
		$permalink_section->set_title( __( 'Now Pages', 'threadi-now-pages' ) );

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_slug' );
		$setting->set_type( 'string' );
		$setting->set_default( 'now' );
		$setting->set_section( $permalink_section );
		$field = new PermalinkSlug( $settings_obj );
		$field->set_title( __( 'Slug', 'threadi-now-pages' ) );
		/* translators: %1$s will be replaced by a URL. */
		$field->set_description( sprintf( __( 'Define the slug of your now page. Only usable with pretty permalinks. Empty values are not possible. If you want to disable this slug, disable the global URL <a href="%1$s">in the plugin settings</a>.', 'threadi-now-pages' ), Settings::get_instance()->get_url() ) );
		$field->set_readonly( ! $this->using_pretty_permalinks() );
		$setting->set_field( $field );
	}

	/**
	 * Register pretty URLs: /now and /{author_base}/{nicename}/now.
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		// get the slug, never empty: an empty slug would take over the front page.
		$slug = preg_quote( $this->get_slug(), '/' );

		// a real page for the Now Page URL brings its own rule.
		// the default has to match is_public(): the option does not exist until the settings are saved for the first time.
		if ( 1 === absint( get_option( 'threadi_now_pages_frontend_global_url', 1 ) ) && ! Now_Page::get_instance()->get_page() instanceof WP_Post ) {
			add_rewrite_rule( '^' . $slug . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}

		if ( 1 === absint( get_option( 'threadi_now_pages_frontend_user_url' ) ) ) {
			$author_base = $this->get_author_base();
			add_rewrite_rule(
				'^' . preg_quote( $author_base, '/' ) . '/([^/]+)/' . $slug . '/?$',
				'index.php?author_name=$matches[1]&' . self::QUERY_VAR . '=1',
				'top'
			);
		}
	}

	/**
	 * Return the slug of the now routes.
	 *
	 * Falls back to "now" if no slug is configured, as an empty slug would
	 * take over the front page.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		$slug = get_option( 'threadi_now_pages_slug', 'now' );

		// use the fallback for empty or invalid values.
		if ( ! is_string( $slug ) || '' === trim( $slug, '/ ' ) ) {
			return 'now';
		}

		return trim( $slug, '/ ' );
	}

	/**
	 * Return whether the current request is for the author route.
	 *
	 * @return bool
	 */
	private function is_author_request(): bool {
		return '' !== (string) get_query_var( 'author_name' ) || absint( get_query_var( 'author' ) ) > 0;
	}

	/**
	 * Author rewrite base (default "author").
	 *
	 * @return string
	 */
	private function get_author_base(): string {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof WP_Rewrite && ! empty( $wp_rewrite->author_base ) ) {
			return (string) $wp_rewrite->author_base;
		}

		return 'author';
	}

	/**
	 * Register our query var.
	 *
	 * @param array<int,string> $vars Public query vars.
	 *
	 * @return array<int,string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Whether the current request is a /now view.
	 *
	 * @return bool
	 */
	public function is_now_request(): bool {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Return whether the now page may be shown publicly.
	 *
	 * Two things have to come together: the route has to exist at all, and
	 * the setup has to be finished.
	 *
	 * @param bool $for_author Whether the author route is meant instead of
	 *                         the global one.
	 *
	 * @return bool
	 */
	public function is_public( bool $for_author = false ): bool {
		$option  = 'threadi_now_pages_frontend_global_url';
		$default = 1;

		// the author route is disabled by default, also if the option is missing.
		if ( $for_author ) {
			$option  = 'threadi_now_pages_frontend_user_url';
			$default = 0;
		}

		$is_public = 1 === absint( get_option( $option, $default ) ) && Setup::get_instance()->is_completed();

		/**
		 * Filter whether the now page is publicly available.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param bool $is_public  True if the page may be shown.
		 * @param bool $for_author True if the author route is meant.
		 */
		return (bool) apply_filters( 'threadi_now_pages_is_public', $is_public, $for_author );
	}

	/**
	 * Build digest content early so the template only has to print it.
	 *
	 * @return void
	 */
	public function prepare(): void {
		// bail if this is not a request for our now page.
		if ( ! $this->is_now_request() ) {
			return;
		}

		// start with a clean state.
		$this->not_found = false;
		$this->content   = '';
		$this->args      = array();
		$this->builder   = null;

		// resolve the author first: the author route has its own visibility setting.
		$this->user        = $this->resolve_author();
		$is_author_request = $this->is_author_request();

		// answer with a 404 if the requested author does not exist, even for administrators.
		if ( $is_author_request && ! $this->user instanceof WP_User ) {
			$this->send_404();

			return;
		}

		// answer with a 404 as long as the page is not public or the requested
		// author does not exist or has no now page. an administrator still sees
		// it, so the result can be checked before the setup is finished.
		if ( ( ! $this->is_public( $is_author_request ) || ( $is_author_request && ! $this->user_has_now_page( $this->user ) ) ) && ! current_user_can( 'manage_options' ) ) {
			$this->send_404();

			return;
		}

		$args = array(
			'period' => get_option( 'threadi_now_pages_frontend_period' ),
			'format' => get_option( 'threadi_now_pages_frontend_format' ),
		);

		if ( $this->user instanceof WP_User ) {
			$args['user_id'] = $this->user->ID;
		}

		/**
		 * Filter the Digest_Builder args used for the /now frontend routes.
		 *
		 * @param array<string,mixed> $args Builder args.
		 * @param WP_User|null        $user Author for /author/…/now, null for global /now.
		 */
		$this->args = apply_filters( 'threadi_now_pages_frontend_digest_args', $args, $this->user );

		// bail if a real page backs the global route. WordPress already resolved
		// it as a page, and the digest comes from the block, widget or shortcode
		// inside that page. the author route is always rendered by us.
		if ( ! $is_author_request && Now_Page::get_instance()->get_page() instanceof WP_Post ) {
			return;
		}

		$this->builder = new Digest_Builder();
		$this->content = $this->builder->build( $this->args );

		// tell an administrator if the page is empty.
		if ( empty( $this->content ) && $this->is_public( $is_author_request ) && current_user_can( 'manage_options' ) ) {
			$this->content = '<p class="threadi-now-pages-not-public">'
							. sprintf(
							/* translators: %1$s will be replaced by a URL. */
								esc_html__( 'Only you can see this page. You have no entries imported to use for this page. Add now your sources %1$s.', 'threadi-now-pages' ),
								'<a href="' . esc_url( Setup::get_instance()->get_setup_link() ) . '">' . esc_html__( 'here', 'threadi-now-pages' ) . '</a>'
							)
							. '</p>' . $this->content;
		}

		// tell an administrator why nobody else sees this page.
		if ( ! $this->is_public( $is_author_request ) && current_user_can( 'manage_options' ) ) {
			$this->content = '<p class="threadi-now-pages-not-public">'
							. sprintf(
							/* translators: %1$s will be replaced by a URL. */
								esc_html__( 'Only you can see this page. It becomes public once you finish the %1$s.', 'threadi-now-pages' ),
								'<a href="' . esc_url( Setup::get_instance()->get_setup_link() ) . '">' . esc_html__( 'setup', 'threadi-now-pages' ) . '</a>'
							)
							. '</p>' . $this->content;
		}

		/**
		 * Filter the digest HTML body (not the full document).
		 *
		 * @param string       $content Rendered digest markup.
		 * @param WP_User|null $user    Author or null.
		 * @param array        $args    Digest args used.
		 */
		$this->content = apply_filters( 'threadi_now_pages_frontend_content', $this->content, $this->user, $this->args );

		// Backward compatibility for theme overrides written against get_query_var().
		set_query_var( 'threadi_now_pages_content', $this->content );
		set_query_var( 'threadi_now_pages_user', $this->user );
		set_query_var( 'threadi_now_pages_args', $this->args );

		// Avoid a 404 body class / status when WP thinks nothing matched.
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
			if ( ! $this->user instanceof WP_User ) {
				$wp_query->is_home = false;
			}
		}
		status_header( 200 );

		// keep page caches away only from the preview an administrator gets before the
		// page is public. WordPress already sends these headers for logged-in users, so
		// the public page stays cacheable for visitors.
		if ( ! $this->is_public( $is_author_request ) ) {
			nocache_headers();
		}
	}

	/**
	 * Answer the current request with a 404.
	 *
	 * @return void
	 */
	private function send_404(): void {
		global $wp_query;

		$this->not_found = true;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Return whether the given user has a now page others may see.
	 *
	 * Unknown users, users who disabled their now page and users without
	 * published entries get the same answer - so the route does not tell
	 * which users exist or which users may edit entries.
	 *
	 * @param WP_User|null $user The user.
	 *
	 * @return bool
	 */
	private function user_has_now_page( ?WP_User $user ): bool {
		// bail if the user does not exist.
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		// bail if the user disabled the own now page.
		$keys = Display_Settings::user_meta_keys();
		if ( 1 === absint( get_user_meta( $user->ID, $keys['disabled'], true ) ) ) {
			return false;
		}

		// the user has a now page only if they have published entries.
		return (int) count_user_posts( $user->ID, Entries::get_instance()->get_slug(), true ) > 0;
	}

	/**
	 * Answer requests for the page behind the now route with a 404, if the
	 * global now page is not public.
	 *
	 * @return void
	 */
	public function hide_now_page(): void {
		// bail if this is no single page.
		if ( ! is_page() ) {
			return;
		}

		if ( $this->is_hidden_now_page( get_queried_object_id() ) ) {
			$this->send_404();
		}
	}

	/**
	 * Exclude the page behind the now route from the sitemap of pages, if the
	 * global now page is not public.
	 *
	 * @param array<string,mixed> $args      The query arguments.
	 * @param string              $post_type The post type.
	 *
	 * @return array<string,mixed>
	 */
	public function exclude_now_page_from_sitemap( array $args, string $post_type ): array {
		// bail if this is not the sitemap of pages.
		if ( 'page' !== $post_type ) {
			return $args;
		}

		// get the page behind the now route.
		$page = Now_Page::get_instance()->get_page();

		// bail if no page is configured. The page is listed by our own sitemap, if it is public.
		if ( ! $page instanceof WP_Post ) {
			return $args;
		}

		// exclude the page.
		$post__not_in         = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
		$post__not_in[]       = $page->ID;
		$args['post__not_in'] = $post__not_in; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- excludes a single page from the sitemap.

		// return the resulting arguments.
		return $args;
	}

	/**
	 * Prevent the users endpoint from listing users just because they have
	 * published entries.
	 *
	 * The REST API lists every user with published posts in a post type with
	 * "show_in_rest" for visitors - and entries are such a post type.
	 *
	 * @param array<string,mixed> $prepared_args The arguments for WP_User_Query.
	 * @param mixed               $request       The request.
	 *
	 * @return array<string,mixed>
	 */
	public function filter_rest_user_query( array $prepared_args, mixed $request ): array {
		// bail if the user may list users anyway.
		if ( current_user_can( 'list_users' ) ) {
			return $prepared_args;
		}

		// bail if the list is not limited by published posts.
		if ( empty( $prepared_args['has_published_posts'] ) ) {
			return $prepared_args;
		}

		// get the post types.
		$post_types = $prepared_args['has_published_posts'];
		if ( true === $post_types ) {
			$post_types = get_post_types( array( 'public' => true ) );
		}
		$post_types = array_values( array_diff( (array) $post_types, array( Entries::get_instance()->get_slug() ) ) );

		// if only entries were requested, no user must be listed. an empty
		// list would lift the limitation completely.
		if ( empty( $post_types ) ) {
			$prepared_args['include'] = array( 0 );
			$post_types               = array( 'post' );
		}

		$prepared_args['has_published_posts'] = $post_types;

		return $prepared_args;
	}

	/**
	 * Do not count entries if the REST API decides whether a user may be
	 * shown to visitors.
	 *
	 * @param mixed $count       The count of posts.
	 * @param mixed $userid      The user ID.
	 * @param mixed $post_type   The post type(s).
	 * @param mixed $public_only Whether to count only public posts.
	 *
	 * @return mixed
	 */
	public function filter_user_post_count( mixed $count, mixed $userid, mixed $post_type, mixed $public_only ): mixed {
		// bail if this is not a count over multiple post types including entries (cheap check first).
		if ( ! is_array( $post_type ) || ! in_array( Entries::get_instance()->get_slug(), $post_type, true ) ) {
			return $count;
		}

		// bail if this is not a REST request or the user may list users anyway.
		if ( ! Plugin::is_rest_request() || current_user_can( 'list_users' ) ) {
			return $count;
		}

		// count again without entries.
		$post_types = array_values( array_diff( $post_type, array( Entries::get_instance()->get_slug() ) ) );

		// bail if no other post type is left.
		if ( empty( $post_types ) ) {
			return 0;
		}

		return count_user_posts( absint( $userid ), $post_types, (bool) $public_only );
	}

	/**
	 * Clear the cached sitemap entries if a relevant user meta changes.
	 *
	 * @param mixed $meta_id   The meta ID(s).
	 * @param mixed $object_id The user ID.
	 * @param mixed $meta_key  The meta key.
	 *
	 * @return void
	 */
	public function clear_sitemap_cache_on_meta_change( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		$keys = Display_Settings::user_meta_keys();

		// bail if this is not a relevant meta key.
		if ( ! in_array( $meta_key, array( $keys['disabled'], $keys['delay_hours'] ), true ) ) {
			return;
		}

		Sitemap::clear_cache();
	}

	/**
	 * Load theme override or plugin template (with theme header/footer).
	 *
	 * @param string $template Path from WP.
	 *
	 * @return string
	 */
	public function template_include( string $template ): string {
		// bail if this is not our now page.
		if ( ! $this->is_now_request() ) {
			return $template;
		}

		// get the route.
		$is_author_request = $this->is_author_request();

		// let WordPress handle the 404 we set in prepare().
		if ( $this->not_found || ( ! $this->is_public( $is_author_request ) && ! current_user_can( 'manage_options' ) ) ) {
			return $template;
		}

		// let the theme and its page builder decide for a real page on the global route.
		if ( ! $is_author_request && Now_Page::get_instance()->get_page() instanceof WP_Post ) {
			return $template;
		}

		// bail if we use a block theme.
		if ( wp_is_block_theme() ) {
			$canvas = $this->prepare_block_template();

			if ( '' !== $canvas ) {
				return $canvas;
			}
		}

		$located = $this->get_template();

		return '' !== $located ? $located : $template;
	}

	/**
	 * Locate the template for a /now view: theme first, plugin as fallback.
	 *
	 * @return string Absolute path or empty string.
	 */
	public function get_template(): string {
		/**
		 * Filter the list of template names looked up in the theme.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,string> $templates Candidate file names.
		 * @param WP_User|null      $user      Author or null.
		 */
		$templates = apply_filters(
			'threadi_now_pages_frontend_templates',
			array(
				'threadi-now-pages/threadi-now-pages.php',
				'threadi-now-pages.php',
				'now.php',
			),
			$this->user
		);

		// 1. child theme, then parent theme.
		$located = locate_template( $templates );

		// 2. fallback: our own directory for templates.
		if ( '' === $located ) {
			foreach ( $templates as $file ) {
				$path = Plugin::get_plugin_path() . 'templates/' . basename( $file );

				if ( is_readable( $path ) ) {
					$located = $path;
					break;
				}
			}
		}

		/**
		 * Filter the resolved template path.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string            $located   Absolute path or empty string.
		 * @param array<int,string> $templates Candidate file names.
		 * @param WP_User|null      $user      Author or null.
		 */
		return (string) apply_filters( 'threadi_now_pages_frontend_template', $located, $templates, $this->user );
	}

	/**
	 * Adjust <title> for /now views.
	 *
	 * @param array<string,string> $parts Title parts.
	 *
	 * @return array<string,string>
	 */
	public function document_title( array $parts ): array {
		if ( ! $this->is_now_request() ) {
			return $parts;
		}

		// a real page brings its own title on the global route.
		if ( ! $this->user instanceof WP_User && Now_Page::get_instance()->get_page() instanceof WP_Post ) {
			return $parts;
		}

		$parts['title'] = $this->user instanceof WP_User
			? sprintf(
			/* translators: %s: display name */
				__( 'Now – %s', 'threadi-now-pages' ),
				$this->user->display_name
			)
			: __( 'Now', 'threadi-now-pages' );

		return $parts;
	}

	/**
	 * Body classes for styling.
	 *
	 * @param array<int,string> $classes Body classes.
	 *
	 * @return array<int,string>
	 */
	public function body_class( array $classes ): array {
		if ( ! $this->is_now_request() ) {
			return $classes;
		}

		$classes[] = 'threadi-now-pages-now';
		if ( $this->user instanceof WP_User ) {
			$classes[] = 'threadi-now-pages-now-author';
		}

		return $classes;
	}

	/**
	 * Resolve the author from the main query.
	 *
	 * @return WP_User|null
	 */
	private function resolve_author(): ?WP_User {
		$author_name = get_query_var( 'author_name' );
		if ( is_string( $author_name ) && '' !== $author_name ) {
			$user = get_user_by( 'slug', $author_name );

			return $user instanceof WP_User ? $user : null;
		}

		$author_id = absint( get_query_var( 'author' ) );
		if ( $author_id > 0 ) {
			$user = get_user_by( 'id', $author_id );

			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}

	/**
	 * Return the rendered digest HTML for the current request.
	 *
	 * @return string Empty string if this is not a /now request.
	 */
	public function get_content(): string {
		return $this->content;
	}

	/**
	 * Return the author of the current request.
	 *
	 * @return WP_User|null Null on the global /now route.
	 */
	public function get_user(): ?WP_User {
		return $this->user;
	}

	/**
	 * Return the Digest_Builder args used for the current request.
	 *
	 * @return array<string,mixed>
	 */
	public function get_args(): array {
		return $this->args;
	}

	/**
	 * Resolve the block template and hand it to the canvas.
	 *
	 * @return string Path to the canvas, or empty string if nothing was found.
	 */
	private function prepare_block_template(): string {
		// get the block template.
		$block_template = get_block_template( get_stylesheet() . '//' . Gutenberg::TEMPLATE_SLUG );

		// bail if block template could not be loaded.
		if ( ! $block_template instanceof WP_Block_Template || empty( $block_template->content ) ) {
			return '';
		}

		// WordPress sets these itself in locate_block_template(), but our
		// route is virtual and never reaches the template hierarchy. There
		// is no public API to hand a template to the canvas.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$GLOBALS['_wp_current_template_id'] = $block_template->id;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$GLOBALS['_wp_current_template_content'] = $block_template->content;

		return ABSPATH . WPINC . '/template-canvas.php';
	}

	/**
	 * Save our custom slugs from permalinks-page.
	 * Settings-page for permalinks does not trigger this itself.
	 *
	 * @return void
	 */
	/**
	 * Save our custom slug from the permalinks page.
	 *
	 * The settings page for permalinks does not trigger this itself.
	 *
	 * @return void
	 */
	public function save_slug(): void {
		// bail if this is not the permalink page being saved.
		if ( ! isset( $_POST['threadi_now_pages_slug'] ) ) {
			return;
		}

		// bail if the user is not allowed to change permalinks.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// check the referer.
		check_admin_referer( 'update-permalink' );

		// get the given slug from request.
		$slug = sanitize_title( wp_unslash( $_POST['threadi_now_pages_slug'] ) );

		// fall back to the default: an empty slug would make the route
		// answer on the front page.
		if ( '' === $slug ) {
			$slug = 'now';
		}

		// bail if nothing changed.
		if ( (string) get_option( 'threadi_now_pages_slug' ) === $slug ) {
			return;
		}

		update_option( 'threadi_now_pages_slug', $slug );

		// the route moved, so the rules have to be rebuilt.
		self::trigger_slug_update();

		// clear the cache.
		Init::get_instance()->delete_option_caches();
	}

	/**
	 * Show a hint about managing the permalinks.
	 *
	 * @return void
	 */
	public function show_availability_hint(): void {
		// get the permalink URL.
		$permalink_url = get_admin_url() . 'options-permalink.php';

		// warn explicitly if plain permalinks are active - the rewrite rules
		// for /now are inert in that case (using_permalinks() === false makes
		// WP_Rewrite::rewrite_rules() return nothing, regardless of our own
		// add_rewrite_rule() calls), so the pretty URL below would just 404.
		if ( ! $this->using_pretty_permalinks() ) {
			echo '<p class="notice notice-warning inline" style="margin-left:0;padding:8px 12px;">';
			/* translators: %1$s will be replaced by a URL, %2$s by the query-var fallback URL. */
			echo wp_kses_post( sprintf( __( 'Your site uses plain permalinks. The pretty URL below will not work until you choose a different permalink structure under <a href="%1$s">Settings > Permalinks</a>. Until then, your now page is reachable at <code>%2$s</code> instead.', 'threadi-now-pages' ), $permalink_url, esc_url( $this->get_global_now_page_url() ) ) );
			echo '</p>';
		}

		// point at the page behind the route, if there is one. a page brings
		// its own permalink, so a taken slug does not affect it.
		$page = Now_Page::get_instance()->get_page();

		if ( $page instanceof WP_Post ) {
			echo '<p>';
			echo wp_kses_post(
				sprintf(
				/* translators: %1$s will be replaced by a link to a page. */
					__( 'Your now page is backed by the page %1$s. Design it with your theme or page builder - the entries come from the block, widget or shortcode you place inside it.', 'threadi-now-pages' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $page->ID, '' ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>'
				)
			);
			echo '</p>';

			return;
		}

		// warn if something else already claims the address of the virtual route.
		$slug = $this->get_slug();

		// show hint if conflict exist.
		if ( Now_Page::get_instance()->has_conflict( $slug ) ) {
			echo '<p class="notice notice-warning inline" style="margin-left:0;padding:8px 12px;">';
			echo wp_kses_post(
				sprintf(
				/* translators: %1$s will be replaced by an address, %2$s by a URL. */
					__( 'The address %1$s is already used by something else, so your now page may not be reachable there. Change it in <a href="%2$s">Settings > Permalinks</a>, or create a page for your now page below.', 'threadi-now-pages' ),
					'<code>/' . esc_html( $slug ) . '/</code>',
					$permalink_url
				)
			);
			echo '</p>';
		}

		// show the hint for the virtual route.
		/* translators: %1$s will be replaced by a URL */
		echo wp_kses_post( sprintf( __( 'If enabled, the now pages will be automatically published using the display options here. Change the URL slug in <a href="%1$s">Settings > Permalinks</a>.', 'threadi-now-pages' ), $permalink_url ) );
	}

	/**
	 * Trigger the slug update.
	 *
	 * @return void
	 */
	public static function trigger_slug_update(): void {
		update_option( 'threadi_now_pages_update_slugs', 1 );
	}

	/**
	 * If an URL slug settings has been changed, trigger the permalink refresh.
	 *
	 * @param mixed $value The given value from setting.
	 *
	 * @return int
	 */
	public function save_url_slugs( mixed $value ): int {
		// trigger the slug update.
		self::trigger_slug_update();

		// return the value.
		return absint( $value );
	}

	/**
	 * Update slugs on request.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 */
	public function update_slugs(): void {
		// the rules of this plugin changed with an update: flush them once, too.
		$rules_outdated = self::REWRITE_VERSION !== get_option( 'threadi_now_pages_rewrite_version' );
		if ( $rules_outdated ) {
			update_option( 'threadi_now_pages_rewrite_version', self::REWRITE_VERSION );
		}

		if ( ! $rules_outdated && 1 !== absint( get_option( 'threadi_now_pages_update_slugs' ) ) ) {
			return;
		}

		// flush the rewrite rules.
		flush_rewrite_rules();

		// delete cache.
		Init::get_instance()->delete_option_caches();

		// disable the flag to update them.
		update_option( 'threadi_now_pages_update_slugs', 0 );
	}

	/**
	 * Return the builder used for the current request.
	 *
	 * @return ?Digest_Builder Null outside a /now request.
	 */
	public function get_builder(): ?Digest_Builder {
		return $this->builder;
	}

	/**
	 * Show a live preview of the now page with its diagnostics.
	 *
	 * @return void
	 */
	public function show_preview(): void {
		$args = array(
			'period' => get_option( 'threadi_now_pages_frontend_period' ),
			'format' => get_option( 'threadi_now_pages_frontend_format' ),
		);

		$builder = new Digest_Builder();
		$content = $builder->build( $args );

		// show description.
		echo '<p><strong>' . esc_html__( 'This is a preview based on the plugin settings. The information displayed in your widget, block, or shortcode may differ from this. Reload the settings after changing any settings to show the actual preview.', 'threadi-now-pages' ) . '</strong></p>';

		// show a hint if the preview is empty.
		if ( '' === $content ) {
			echo '<p>' . wp_kses_post( __( 'The <em>now page</em> would now be empty.', 'threadi-now-pages' ) ) . '</p>';
		}

		echo '<div class="threadi-now-pages-preview">' . wp_kses_post( $content ) . '</div>';

		// show the same panel as on the frontend.
		echo wp_kses_post( Diagnostics::get_instance()->render_panel( $builder->get_trace(), null ) );
	}

	/**
	 * Return the global now page URL.
	 *
	 * Falls back to the query-var form (?threadi_now_pages_now=1) when pretty
	 * permalinks are disabled - the rewrite rule from
	 * Frontend::register_rewrites() is inert in that case, since
	 * WP_Rewrite::rewrite_rules() returns an empty array whenever
	 * using_permalinks() is false, regardless of any custom rules added.
	 *
	 * @return string
	 */
	public function get_global_now_page_url(): string {
		// return the simple URL.
		if ( ! $this->using_pretty_permalinks() ) {
			return add_query_arg( self::QUERY_VAR, '1', trailingslashit( get_option( 'home' ) ) );
		}

		// return the pretty URL.
		return user_trailingslashit( trailingslashit( get_option( 'home' ) ) . $this->get_slug() );
	}

	/**
	 * Return a user's now page URL.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return string
	 */
	public function get_author_now_page_url( WP_User $user ): string {
		// return the simple URL.
		if ( ! $this->using_pretty_permalinks() ) {
			return add_query_arg(
				array(
					self::QUERY_VAR => '1',
					'author_name'   => $user->user_nicename,
				),
				trailingslashit( get_option( 'home' ) )
			);
		}

		// return the pretty URL.
		return trailingslashit( get_author_posts_url( $user->ID, $user->user_nicename ) ) . $this->get_slug() . '/';
	}

	/**
	 * Whether the site currently uses pretty (non-Plain) permalinks.
	 *
	 * Custom rewrite rules (like ours for /now) never take effect without this.
	 *
	 * @return bool
	 */
	private function using_pretty_permalinks(): bool {
		return '' !== get_option( 'permalink_structure' );
	}

	/**
	 * Add dashboard-widget to show the state of the now page.
	 *
	 * @param array<int,array<string,mixed>> $dashboard_widgets List of widgets on dashboard.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_dashboard_widget( array $dashboard_widgets ): array {
		// bail if user has no capability to manage the now page.
		if ( ! current_user_can( Settings::get_instance()->get_settings_obj()->get_capability() ) ) {
			return $dashboard_widgets;
		}

		$dashboard_widgets[] = array(
			'id'       => 'dashboard_threadi_now_pages_state',
			'label'    => __( 'Your Now Page', 'threadi-now-pages' ),
			'callback' => array( $this, 'get_dashboard_widget_content' ),
		);

		// return the resulting list.
		return $dashboard_widgets;
	}

	/**
	 * Output the contents of the dashboard widget
	 *
	 * @param string               $post The post as an object.
	 * @param array<string,string> $callback_args List of arguments.
	 */
	public function get_dashboard_widget_content( string $post, array $callback_args ): void {
		// bail if we miss data.
		if ( ! ( empty( $post ) && ! empty( $callback_args ) ) ) {
			return;
		}

		// show hint if the global now page is disabled.
		if ( 1 !== absint( get_option( 'threadi_now_pages_frontend_global_url', 1 ) ) ) {
			/* translators: %1$s will be replaced by a URL. */
			echo '<p>' . wp_kses_post( sprintf( __( 'Your Now Page is disabled. Change this <a href="%1$s">in the settings</a>.', 'threadi-now-pages' ), Settings::get_instance()->get_url() ) ) . '</p>';
			return;
		}

		$args = array(
			'period' => get_option( 'threadi_now_pages_frontend_period' ),
			'format' => get_option( 'threadi_now_pages_frontend_format' ),
		);
		$user = null;
		/**
		 * Filter the Digest_Builder args used for the /now frontend routes.
		 *
		 * @param array<string,mixed> $args Builder args.
		 * @param WP_User|null        $user Author for /author/…/now, null for global /now.
		 */
		$args = apply_filters( 'threadi_now_pages_frontend_digest_args', $args, $user );

		// get the timestamp of the last change.
		$timestamp = ( new Digest_Builder() )->get_last_entry_date( $args );

		echo '<p>';
		if ( null === $timestamp ) {
			echo esc_html__( 'Your now page has no entries yet.', 'threadi-now-pages' );
		} else {
			printf(
				/* translators: %1$s will be replaced by a date. */
				esc_html__( 'Last updated %s ago.', 'threadi-now-pages' ),
				esc_html( human_time_diff( $timestamp ) )
			);
		}
		echo '</p>';

		printf(
			'<p><a href="%1$s" target="_blank">%2$s%3$s</a></p>',
			esc_url( self::get_instance()->get_global_now_page_url() ),
			esc_html__( 'View your now page', 'threadi-now-pages' ),
			wp_kses_post( Plugin::get_a11n_window_hint() )
		);
	}

	/**
	 * Add our own sitemap provider to add the threadi-now-pages in the sitemap.
	 *
	 * @return void
	 */
	public function add_sitemap_provider(): void {
		wp_register_sitemap_provider( 'nowpages', new Sitemap() );
	}

	/**
	 * Return the pages a now route can be backed by.
	 *
	 * @return array<int,string>
	 */
	private function get_page_options(): array {
		// bail in frontend.
		if ( ! is_admin() && ! Plugin::is_rest_request() ) {
			return array();
		}

		$cache_key = 'threadi_now_pages_wp_pages';
		$pages     = get_transient( $cache_key );

		// return the cached list, extended by the saved page.
		if ( is_array( $pages ) ) {
			return $this->add_saved_page_to_options( $pages );
		}

		$options = array(
			'0' => __( '- none, render the route directly -', 'threadi-now-pages' ),
		);

		// query for the pages in portions of 100, up to 1.000 pages.
		for ( $paged = 1; $paged <= 10; $paged++ ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'paged'          => $paged,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			$posts = $query->get_posts();

			foreach ( $posts as $page ) {
				// bail if this is not an object.
				if ( ! $page instanceof WP_Post ) {
					continue;
				}

				// add the entry to the list.
				$options[ (string) $page->ID ] = get_the_title( $page );
			}

			// stop if this was the last portion.
			if ( count( $posts ) < 100 ) {
				break;
			}
		}

		set_transient( $cache_key, $options, WEEK_IN_SECONDS );

		// return the available options, extended by the saved page.
		return $this->add_saved_page_to_options( $options );
	}

	/**
	 * Add the saved page to the list of options, if it is missing there.
	 *
	 * Otherwise the select would show the first option, and the next save
	 * would silently remove the page from the now route.
	 *
	 * @param array<int,string> $options The list of options.
	 *
	 * @return array<int,string>
	 */
	private function add_saved_page_to_options( array $options ): array {
		$page_id = absint( get_option( Now_Page::OPTION, 0 ) );

		// bail if no page is saved or it is already in the list.
		if ( 0 === $page_id || isset( $options[ (string) $page_id ] ) ) {
			return $options;
		}

		$post = get_post( $page_id );

		// bail if the saved ID does not point at a page.
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return $options;
		}

		// add the page.
		$options[ (string) $page_id ] = get_the_title( $post );

		// return the resulting list.
		return $options;
	}

	/**
	 * Create a page for the now route.
	 *
	 * @return void
	 * @noinspection PhpNoReturnAttributeCanBeAddedInspection
	 */
	public function create_page(): void {
		// check the nonce.
		check_admin_referer( 'threadi-now-pages-create-page', 'nonce' );

		// bail if the capability is missing.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'threadi-now-pages' ), '', array( 'response' => 403 ) );
		}

		// create it, prefilled for the active page builder.
		Now_Page::get_instance()->create_page();

		wp_safe_redirect( Settings::get_instance()->get_url( 'threadi_now_pages_now_page' ) );
		exit;
	}

	/**
	 * Validate the newly selected page as now page.
	 *
	 * @param mixed $value The given value.
	 *
	 * @return int
	 */
	public function save_page_id( mixed $value ): int {
		$page_id = absint( $value );

		// trigger the slug update.
		self::trigger_slug_update();

		// bail if nothing is selected.
		if ( 0 === $page_id ) {
			return 0;
		}

		$post = get_post( $page_id );

		// bail if the id points at something that is not a page.
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return 0;
		}

		return $page_id;
	}

	/**
	 * Return whether the given (or currently rendered) post is the page behind
	 * the now route while this route is not public for the actual user.
	 *
	 * @param int|null $post_id The post to check, null for the post rendered right now.
	 *
	 * @return bool
	 */
	public function is_hidden_now_page( ?int $post_id = null ): bool {
		// get the page behind the now route.
		$page = Now_Page::get_instance()->get_page();

		// bail if no page is configured.
		if ( ! $page instanceof WP_Post ) {
			return false;
		}

		// get the real post ID of the object to check.
		$real_post_id = null !== $post_id ? $post_id : absint( get_the_ID() );

		// bail if this is another post.
		if ( $real_post_id !== $page->ID ) {
			return false;
		}

		// hidden if the route is not public and the user is no administrator.
		return ! $this->is_public() && ! current_user_can( 'edit_post', $page->ID );
	}
}
