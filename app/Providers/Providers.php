<?php
/**
 * This file contains the main initialization for providers.
 *
 * Definition:
 * Providers are the source of any entry in now pages.
 * Each provider must return defined values to create an entry.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Plugin\Log;
use NowPagesThreadi\Plugin\Settings;
use NowPagesThreadi\Sources\Sources;
use WP_Error;

/**
 * Object to initialize the providers.
 */
class Providers {

	/**
	 * Instance of actual object.
	 *
	 * @var ?Providers
	 */
	private static ?Providers $instance = null;

	/**
	 * Registered providers, keyed by slug.
	 *
	 * @var array<string,Provider_Interface>
	 */
	private array $providers = array();

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
	 * @return Providers
	 */
	public static function get_instance(): Providers {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'init', array( $this, 'add_settings' ) );
		add_filter( 'now_pages_threadi_log_categories', array( $this, 'add_log_category' ) );
		add_action( 'now_pages_threadi_provider_error', array( $this, 'log_error' ), 10, 2 );

		// register our own, built-in providers.
		$this->register_provider( new RSS_Provider() );
		$this->register_provider( new Webhook_Provider() );
		$this->register_provider( new GitHub_Provider() );
		$this->register_provider( new ICS_Provider() );
		$this->register_provider( new WordPress_Provider() );
		$this->register_provider( new LastFm_Provider() );
		$this->register_provider( new Steam_Provider() );
		$this->register_provider( new Recurring_Provider() );

		$interface = $this;
		/**
		 * Fires once the built-in providers are registered.
		 *
		 * Use this to add further providers (e.g. custom Discord/LinkedIn
		 * clients) from your own code, without touching this plugin.
		 *
		 * Example:
		 * add_action( 'now_pages_threadi_register_providers', function( $providers ) {
		 *     $providers->register_provider( new My_Provider() );
		 * } );
		 *
		 * @param Providers $interface The providers objects.
		 */
		do_action( 'now_pages_threadi_register_providers', $interface );

		// initialize our webhook controller.
		Webhook_Controller::get_instance()->init();

		// initialize all registered providers.
		foreach ( $this->providers as $provider ) {
			$provider->init();
		}
	}

	/**
	 * Add our custom settings for this plugin.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! \NowPagesThreadi\Helper\Plugin::is_settings_request() ) {
			return;
		}

		// get the settings page.
		$settings_page = Settings::get_instance()->get_settings_page();

		/**
		 * The provider tab.
		 */
		$provider_tab = $settings_page->add_tab( 'now_pages_threadi_provider', 35 );
		$provider_tab->set_title( __( 'Provider', 'now-pages-threadi' ) );
	}

	/**
	 * Register a single provider.
	 *
	 * @param Provider_Interface $provider The provider to register.
	 *
	 * @return void
	 */
	public function register_provider( Provider_Interface $provider ): void {
		$this->providers[ $provider->get_slug() ] = $provider;
	}

	/**
	 * Return all registered providers.
	 *
	 * @return array<string,Provider_Interface>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Return a single provider by its slug.
	 *
	 * @param string $slug The provider slug.
	 *
	 * @return Provider_Interface|null
	 */
	public function get_provider( string $slug ): ?Provider_Interface {
		return isset( $this->providers[ $slug ] ) ? $this->providers[ $slug ] : null;
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories List of log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		// add our category.
		$categories['provider'] = __( 'Provider', 'now-pages-threadi' );

		// return the resulting categories.
		return $categories;
	}

	/**
	 * Log an error a provider ran into.
	 *
	 * Providers deliberately do not log themselves: they signal the problem
	 * and return an empty list, the decision what to do with it is made here.
	 *
	 * @param string   $provider_slug The slug of the failing provider.
	 * @param WP_Error $error         The resulting error.
	 *
	 * @return void
	 */
	public function log_error( string $provider_slug, WP_Error $error ): void {
		// get the label of the provider, fall back to its slug.
		$label        = $provider_slug;
		$provider_obj = $this->get_provider( $provider_slug );

		if ( $provider_obj instanceof Provider_Interface ) {
			$label = $provider_obj->get_label();
		}

		$message = $error->get_error_message();
		$data    = $error->get_error_data();

		// point at the source, so the way from the symptom to the check is
		// one click and not a search through the list.
		$source_hint = '';

		if ( is_array( $data ) && ! empty( $data['source_id'] ) ) {
			$source_id = absint( $data['source_id'] );

			$source_hint = '<br>' . sprintf(
				/* translators: %1$s will be replaced by a link to a source. */
				__( 'You can <a href="%1$s">check whether this service can be reached</a> from your website.', 'now-pages-threadi' ),
				esc_url( Sources::get_instance()->get_check_url( $source_id ) )
			);
		}

		// a formatted message is already a complete sentence with markup.
		if ( is_array( $data ) && ! empty( $data['formatted'] ) ) {
			Log::get_instance()->add( $message . $source_hint, 'error', 'provider' );

			return;
		}

		// everything else is a raw message from an API or an exception.
		Log::get_instance()->add(
			sprintf(
			/* translators: %1$s will be replaced by a provider name. */
				__( 'The provider %1$s ran into an error:', 'now-pages-threadi' ),
				'<code>' . esc_html( $label ) . '</code>'
			) . ' <code>' . esc_html( $message ) . '</code>' . $source_hint,
			'error',
			'provider'
		);
	}

	/**
	 * Return all enabled providers.
	 *
	 * @return array<string,Provider_Interface>
	 */
	public function get_enabled_providers(): array {
		return array_filter(
			$this->providers,
			static fn( Provider_Interface $provider ): bool => $provider->is_enabled()
		);
	}
}
