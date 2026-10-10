<?php
/**
 * This file contains the support for Brizy.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Brizy_Content_Providers_AbstractProvider;
use Brizy_Editor_ThirdParty_Extension;
use Exception;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\Plugin\Log;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and handles the support for Brizy.
 */
class Brizy extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'brizy';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Brizy is not enabled.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks from Brizy.
		add_filter( 'brizy_extensions', array( $this, 'add_extension' ) );
		add_filter( 'brizy_providers', array( $this, 'add_providers' ) );
		add_action( 'brizy_editor_enqueue_scripts', array( $this, 'add_editor_data' ) );
		add_action( 'brizy_preview_enqueue_scripts', array( $this, 'add_editor_data' ) );

		// use hooks.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Add our extension in Brizy.
	 *
	 * @param array<int,Brizy_Editor_ThirdParty_Extension> $extensions List of all extensions.
	 *
	 * @return array<int,Brizy_Editor_ThirdParty_Extension>
	 */
	public function add_extension( array $extensions ): array {
		// bail if class is missing.
		if ( ! class_exists( 'Brizy_Editor_ThirdParty_Extension' ) ) {
			return $extensions;
		}

		// get the compiled config.json.
		$config_file = Plugin::get_plugin_path() . 'brizy/build/config.json';

		// get the public URL of the extension.
		$public_url = Plugin::get_plugin_url() . 'brizy/build/';

		// add our own extension.
		try {
			$extensions[] = new Brizy_Editor_ThirdParty_Extension( $public_url, $config_file );
		} catch ( Exception $e ) {
			Log::get_instance()->add( __( 'Brizy extension error:', 'now-pages-threadi' ) . '<code>' . $e->getMessage() . '</code>', 'error', 'pagebuilder' );
		}

		// return the resulting list of extensions.
		return $extensions;
	}

	/**
	 * Add our provider in Brizy.
	 *
	 * @param array<int,Brizy_Content_Providers_AbstractProvider> $providers List of Brizy providers.
	 *
	 * @return array<int,Brizy_Content_Providers_AbstractProvider>
	 */
	public function add_providers( array $providers ): array {
		// bail if class is missing.
		if ( ! class_exists( 'Brizy_Content_Providers_AbstractProvider' ) ) {
			return $providers;
		}

		// add the widget we support.
		$providers[] = new Brizy\Digest();

		// return the resulting list of providers.
		return $providers;
	}

	/**
	 * Provide REST URL and nonce to the Brizy editor widget.
	 *
	 * @return void
	 */
	public function add_editor_data(): void {
		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[] = array(
				'value' => $format->get_slug(),
				'title' => $format->get_label(),
			);
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[] = array(
				'value' => $slug,
				'title' => $label,
			);
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		// collect the data.
		$data = array(
			'restUrl'        => esc_url_raw( rest_url( 'now-pages-threadi/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'formats'        => $formats,
			'default_format' => Digests::get_instance()->get_default_format(),
			'periods'        => $periods,
			'default_period' => Digests::get_instance()->get_default_period(),
			'i18n'           => $this->get_widget_translations(),
		);

		wp_register_script(
			'now-pages-threadi-brizy-data',
			false,
			array(),
			NOWPAGES_PLUGIN_VERSION,
			true
		);
		wp_enqueue_script( 'now-pages-threadi-brizy-data' );
		wp_add_inline_script(
			'now-pages-threadi-brizy-data',
			'window.nowPagesThreadiBrizy = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Return the translations needed by the Brizy widgets.
	 *
	 * @return array<string,string>
	 */
	private function get_widget_translations(): array {
		return array();
	}

	/**
	 * Register REST API routes used by the editor to request the preview values for our widgets.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'now-pages-threadi/v1',
			'/digest',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( $this, 'get_rest_callback' ),
			)
		);
	}

	/**
	 * Return the REST API callback.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_rest_callback( WP_REST_Request $request ): WP_REST_Response {
		$attributes = array(
			'period' => $request->get_param( 'period' ),
			'format' => $request->get_param( 'format' ),
		);

		// the view writes "true"/"false", older layouts may contain "on".
		$is_on = static function ( mixed $value ): bool {
			return in_array( $value, array( true, 1, '1', 'on', 'true', 'yes' ), true );
		};

		// get the parameters, if enabled.
		if ( $is_on( $request->get_param( 'override_display' ) ) ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );
			foreach ( $names as $name ) {
				$attributes[ $name ] = $is_on( $request->get_param( $name ) );
			}
		}

		// return the resulting response.
		return new WP_REST_Response( ( new Digest_Builder() )->build( $attributes ), 200 );
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Brizy keeps its layout in post meta, post_content stays empty.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array(
			'brizy_enabled'      => 1,
			'_wp_page_template'  => 'brizy-blank-template.php',
			'brizy_post_uid'     => md5( uniqid( 'now-pages-threadi', true ) ),
			'brizy'              => array(
				'brizy-post' => array(
					'editor_version'     => defined( 'BRIZY_MINIMUM_COMPILER_VERSION' ) ? BRIZY_MINIMUM_COMPILER_VERSION : '375-wp',
					'compiler_version'   => defined( 'BRIZY_MINIMUM_COMPILER_VERSION' ) ? BRIZY_MINIMUM_COMPILER_VERSION : '375-wp',
					'plugin_version'     => defined( 'BRIZY_VERSION' ) ? BRIZY_VERSION : '',
					'pro_plugin_version' => null,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Brizy stores its editor data base64 encoded, this is its format, not obfuscation.
					'editor_data'        => base64_encode( (string) wp_json_encode( $this->get_editor_data() ) ),
					'lastUserEdited'     => get_current_user_id(),
					'compiler'           => 'browser',
					'brizy-use-brizy'    => true,
				),
			),
			// an empty value tells brizy to compile on the next request.
			'brizy-need-compile' => '',
		);
	}

	/**
	 * Return the editor tree for a page with just our element on it.
	 *
	 * @return array<string,mixed>
	 */
	private function get_editor_data(): array {
		return array(
			'items'         => array(
				array(
					'type'    => 'Section',
					'value'   => array(
						'_styles' => array( 'section' ),
						'items'   => array(
							array(
								'type'  => 'SectionItem',
								'value' => array(
									'_styles' => array( 'section-item' ),
									'items'   => array(
										array(
											'type'  => 'Wrapper',
											'value' => array(
												'_styles' => array( 'wrapper', 'wrapper--toolbar' ),
												'items'   => array(
													array(
														'type'  => 'ThirdParty',
														'value' => array(
															'thirdPartyId' => 'Now.Pages.Digest',
															'items'        => array(),
															'_id'          => $this->get_element_id(),
															'period'       => get_option( 'now_pages_threadi_frontend_period', 'live' ),
															'format'       => get_option( 'now_pages_threadi_frontend_format', 'verbatim' ),
														),
													),
												),
												'_id'     => $this->get_element_id(),
											),
										),
									),
									'_id'     => $this->get_element_id(),
								),
							),
						),
						'_id'     => $this->get_element_id(),
					),
					'blockId' => 'blank',
				),
			),
			'editorVersion' => '375-wp',
		);
	}

	/**
	 * Return an element ID in the format Brizy uses.
	 *
	 * @return string
	 */
	private function get_element_id(): string {
		return wp_generate_password( 12, false, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return defined( 'BRIZY_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Brizy', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
