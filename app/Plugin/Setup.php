<?php
/**
 * File to handle setup for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transient;
use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\Digests\Periods;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\NowPages\Now_Page;
use NowPagesThreadi\PageBuilder\PageBuilder_Base;
use NowPagesThreadi\Presets\Preset_Interface;
use NowPagesThreadi\Presets\Presets;
use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Sources\Advisor;
use NowPagesThreadi\Sources\Source_Builder;
use NowPagesThreadi\Sources\Sources;
use WP_Post;
use WP_REST_Request;

/**
 * Initialize the setup object.
 */
class Setup {
	/**
	 * The prefix every field of the setup is stored under.
	 *
	 * @var string
	 */
	public const FIELD_PREFIX = 'now_pages_threadi_setup_';

	/**
	 * The option holding the preset chosen in step 1.
	 *
	 * @var string
	 */
	public const OPTION_PRESET = 'now_pages_threadi_setup_preset';

	/**
	 * The option holding the choice whether a page should be created.
	 *
	 * @var string
	 */
	public const OPTION_CREATE_PAGE = 'now_pages_threadi_setup_create_page';

	/**
	 * The option holding what the user asked the advisor for.
	 *
	 * @var string
	 */
	public const OPTION_WISH = 'now_pages_threadi_setup_advisor_wish';

	/**
	 * The option holding the proposal of the advisor the user chose.
	 *
	 * @var string
	 */
	public const OPTION_PROPOSAL = 'now_pages_threadi_setup_advisor_proposal';

	/**
	 * The prefix the answers to the questions of a proposal are stored under.
	 *
	 * @var string
	 */
	public const ANSWER_PREFIX = 'now_pages_threadi_setup_advisor_answer_';

	/**
	 * Instance of this object.
	 *
	 * @var ?Setup
	 */
	private static ?Setup $instance = null;

	/**
	 * Define setup as an array with steps.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $setup = array();

	/**
	 * The text shown when the process is done.
	 *
	 * @var string
	 */
	private string $process_result = '';

	/**
	 * Why the advisor could not give advice during this request.
	 *
	 * @var string
	 */
	private string $advice_error = '';

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
	public static function get_instance(): Setup {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the setup-object.
	 *
	 * @return void
	 */
	public function init(): void {
		// tell the library where the values live before anything reads them.
		if ( method_exists( '\easySetupForWordPress\Setup', 'set_user_values' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			\easySetupForWordPress\Setup::get_instance()->set_user_values( true );
		}

		// use hooks.
		add_action( 'init', array( $this, 'maybe_clear_answers' ), 5 );
		add_action( 'init', array( $this, 'init_setup' ) );

		// WordPress hands the API keys of the connectors to the AI Client at init:20. Whether the
		// advisor can be offered is only known afterwards, so the steps are built once more then.
		add_action( 'init', array( $this, 'refresh_config' ), 21 );

		// ask the advisor right before the setup asks for the step showing its proposals.
		add_filter( 'rest_request_before_callbacks', array( $this, 'prepare_advice' ), 10, 3 );
	}

	/**
	 * Initialize the setup.
	 *
	 * The setup is registered regardless of whether it has been completed:
	 * it is also the place where a further source is created later on. Only
	 * the hint and the menu entry depend on the completion marker.
	 *
	 * @return void
	 */
	public function init_setup(): void {
		// bail if the setup is not needed in this request.
		if ( ! Plugin::is_settings_request() ) {
			return;
		}

		// check to show a hint if setup should be run.
		$this->show_hint();

		// initialize the setup object.
		$setup_obj = \easySetupForWordPress\Setup::get_instance();
		$setup_obj->init();

		// get the setup-object.
		$setup_obj->set_url( Plugin::get_plugin_url() );
		$setup_obj->set_path( Plugin::get_plugin_path() );
		$setup_obj->set_texts(
			array(
				'title_error' => __( 'Error', 'now-pages-threadi' ),
				'txt_error_1' => __( 'The following error occurred:', 'now-pages-threadi' ),
				/* translators: %1$s will be replaced with the URL of the plugin-forum on wp.org */
				'txt_error_2' => sprintf( __( '<strong>If reason is unclear</strong> please contact our <a href="%1$s" target="_blank">support-forum (opens a new window)</a> with as much detail as possible.', 'now-pages-threadi' ), esc_url( Plugin::get_plugin_support_url() ) ),
			)
		);
		$setup_obj->set_display_hook( '_page_now_pages_threadi_settings' );

		// set configuration for the setup.
		$setup_obj->set_config( $this->get_config() );

		// use hooks.
		add_action( 'esfw_set_completed', array( $this, 'set_completed' ) );
		add_action( 'esfw_process', array( $this, 'run_process' ) );
		add_action( 'esfw_process', array( $this, 'show_process_end' ), PHP_INT_MAX );

		// add hooks to enable the setup of this plugin.
		add_action( 'admin_menu', array( $this, 'add_setup_menu' ) );
	}

	/**
	 * Build the steps of the setup again, once the AI service of the website is ready.
	 *
	 * The steps are first built at init:10, as before. At that time no
	 * connector has its API key yet, so the advisor would never be offered.
	 *
	 * @return void
	 */
	public function refresh_config(): void {
		// bail if the setup is not involved in this request, nothing would look at the result.
		if ( ! $this->is_setup_request() ) {
			return;
		}

		$this->setup = array();
		\easySetupForWordPress\Setup::get_instance()->set_config( $this->get_config() );
	}

	/**
	 * Return whether setup is completed.
	 *
	 * @return bool
	 */
	public function is_completed(): bool {
		$completed = \easySetupForWordPress\Setup::get_instance()->is_completed( $this->get_setup_name() );

		/**
		 * Filter the setup complete marker.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param bool $completed True if setup has been completed.
		 */
		return apply_filters( 'now_pages_threadi_setup_is_completed', $completed );
	}

	/**
	 * Return the setup-URL.
	 *
	 * @return string
	 */
	public function get_setup_link(): string {
		return add_query_arg( array( 'page' => 'now_pages_threadi_settings-setup' ), admin_url() . 'options-general.php' );
	}

	/**
	 * Check if setup should be run and show the hint for it.
	 *
	 * @return void
	 */
	public function show_hint(): void {
		// get transients object.
		$transients_obj = Transients::get_instance();

		// check if setup should be run.
		if ( ! $this->is_completed() ) {
			// bail if the hint is already set.
			if ( $transients_obj->get_transient_by_name( 'now_pages_threadi_start_setup_hint' )->is_set() ) {
				return;
			}

			// delete all other transients.
			foreach ( $transients_obj->get_transients() as $transient_obj ) {
				// bail if the object is not ours.
				if ( ! $transient_obj instanceof Transient ) { // @phpstan-ignore instanceof.alwaysTrue
					continue;
				}

				// delete it.
				$transient_obj->delete();
			}

			// add a hint to run setup.
			$transient_obj = Transients::get_instance()->add();
			$transient_obj->set_name( 'now_pages_threadi_start_setup_hint' );
			$transient_obj->set_message( __( '<strong>You have installed Now Pages - nice, and thank you!</strong> Run the setup to create your own Now Page on your website.', 'now-pages-threadi' ) . '<br><br>' . sprintf( '<a href="%1$s" class="button button-primary">' . __( 'Start setup', 'now-pages-threadi' ) . '</a>', esc_url( $this->get_setup_link() ) ) );
			$transient_obj->set_type( 'error' );
			$transient_obj->set_dismissible_days( 2 );
			$transient_obj->set_hide_on(
				array(
					$this->get_setup_link(),
				)
			);
			$transient_obj->save();
		} elseif ( is_admin() ) {
			$transients_obj->get_transient_by_name( 'now_pages_threadi_start_setup_hint' )->delete();
		}
	}

	/**
	 * Return the configured setup.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_setup(): array {
		$setup = $this->setup;

		if ( empty( $setup ) ) {
			$this->set_config();

			$setup = $this->setup;
		}

		/**
		 * Filter the configured setup for this plugin.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<int,array<string,mixed>> $setup The setup-configuration.
		 */
		return apply_filters( 'now_pages_threadi_setup', $setup );
	}

	/**
	 * Show the setup dialog.
	 *
	 * @return void
	 */
	public function display(): void {
		// create help in case of error during loading of the setup.
		$error_help = '<div class="now-pages-threadi-transient notice notice-success"><h3>' . esc_html( Plugin::get_plugin_name() ) . '</h3><p><strong>' . __( 'Setup is loading', 'now-pages-threadi' ) . '</strong><br>' . __( 'Please wait while we load the setup.', 'now-pages-threadi' ) . '<br>' . __( 'However, you can also skip the setup and configure the plugin manually.', 'now-pages-threadi' ) . '</p><p><a href="' . esc_url( \easySetupForWordPress\Setup::get_instance()->get_skip_url( $this->get_setup_name(), Settings::get_instance()->get_url() ) ) . '" class="button button-primary">' . __( 'Skip setup', 'now-pages-threadi' ) . '</a></p></div>';

		// add error text.
		\easySetupForWordPress\Setup::get_instance()->set_error_help( $error_help );

		// output.
		echo wp_kses_post( \easySetupForWordPress\Setup::get_instance()->display( $this->get_setup_name() ) );
	}

	/**
	 * Add a setup menu if setup is not completed.
	 *
	 * @return void
	 */
	public function add_setup_menu(): void {
		$setup_slug = Settings::MENU_SLUG . '-setup';

		// add setup entry as sub-menu.
		$hook = add_options_page(
			Plugin::get_plugin_name() . ' ' . __( 'Setup', 'now-pages-threadi' ),
			__( 'Now Pages', 'now-pages-threadi' ),
			'manage_options',
			$setup_slug,
			array( $this, 'display' )
		);

		// bail if the page could not be registered.
		if ( ! is_string( $hook ) ) {
			return;
		}

		// get_admin_page_title() reads the title from the submenu we just
		// removed, so set it ourselves before admin-header.php runs.
		add_action( 'load-' . $hook, array( $this, 'set_page_title' ) );

		// during the first run the setup replaces the settings in the menu.
		if ( ! $this->is_completed() ) {
			remove_submenu_page( 'options-general.php', Settings::MENU_SLUG );

			return;
		}

		// afterward the setup stays reachable by URL - it is where a further
		// source is created - but it no longer belongs in the menu.
		remove_submenu_page( 'options-general.php', $setup_slug );
	}

	/**
	 * Set the title of the setup page.
	 *
	 * @return void
	 */
	public function set_page_title(): void {
		$GLOBALS['title'] = Plugin::get_plugin_name() . ' ' . __( 'Setup', 'now-pages-threadi' );

		// start with a clean slate: the answers are only transport between
		// the steps, a previous run must not preselect anything.
		$this->clear_all_answers();
	}

	/**
	 * Return configuration for setup.
	 *
	 * Here we define which steps and texts are used by wp-easy-setup.
	 *
	 * @return array<string,array<int,mixed>|bool|string>
	 */
	private function get_config(): array {
		// get setup.
		$setup = $this->get_setup();

		// get the skip URL.
		$skip_url = \easySetupForWordPress\Setup::get_instance()->get_skip_url( $this->get_setup_name(), Settings::get_instance()->get_url() );
		if ( $this->is_completed() ) {
			$skip_url = \easySetupForWordPress\Setup::get_instance()->get_skip_url( $this->get_setup_name(), Sources::get_instance()->get_backend_list_url() );
		}

		// collect configuration for the setup.
		$config = array(
			'name'                  => $this->get_setup_name(),
			'title'                 => __( 'Now Pages', 'now-pages-threadi' ) . ' ' . __( 'Setup', 'now-pages-threadi' ),
			'steps'                 => $setup,
			'update_fields'         => true,
			'user_values'           => true,
			'back_button_label'     => __( 'Back', 'now-pages-threadi' ) . '<span class="dashicons dashicons-undo"></span>',
			'continue_button_label' => __( 'Continue', 'now-pages-threadi' ) . '<span class="dashicons dashicons-controls-play"></span>',
			'finish_button_label'   => __( 'Completed', 'now-pages-threadi' ) . '<span class="dashicons dashicons-saved"></span>',
			'skip_button_label'     => __( 'Skip', 'now-pages-threadi' ) . '<span class="dashicons dashicons-undo"></span>',
			'skip_url'              => $skip_url,
			'error_label'           => __( 'An error occurred. Received an incorrect response from the server. Please check your permalink settings.', 'now-pages-threadi' ),
		);

		/**
		 * Filter the setup configuration.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<string,array<int,mixed>|bool|string> $config List of configuration for the setup.
		 */
		return apply_filters( 'now_pages_threadi_setup_config', $config );
	}

	/**
	 * Set the process label.
	 *
	 * @param string $label The label to process.
	 *
	 * @return void
	 */
	public function set_process_label( string $label ): void {
		update_option( 'esfw_step_label', $label );
	}

	/**
	 * Updates the process step.
	 *
	 * @param int $step Steps to add.
	 *
	 * @return void
	 */
	public function update_process_step( int $step = 1 ): void {
		update_option( 'esfw_step', absint( get_option( 'esfw_step' ) + $step ) );
	}

	/**
	 * Sets the setup configuration.
	 *
	 * Three steps: choose a preset, answer its questions, create the source.
	 * With the advisor the questions are replaced by three steps of its
	 * own: describe the wish, choose a proposal, answer what is still open.
	 *
	 * @return void
	 */
	public function set_config(): void {
		// prepare the steps.
		$this->setup = array();
		if ( ! $this->is_completed() ) {
			$this->setup[1] = $this->get_first_step();
		}
		$this->setup[2] = $this->get_second_step();
		if ( $this->is_advisor_chosen() ) {
			$this->setup[3] = $this->get_advisor_wish_step();
			$this->setup[4] = $this->get_advisor_proposals_step();
			$this->setup[5] = $this->get_advisor_details_step();
		} else {
			$this->setup[3] = $this->get_third_step();
		}
		$this->setup[6] = array(
			'runSetup' => array(
				'type'  => 'ProgressBar',
				'label' => __( 'Setup preparing your first source for entries in your "now page"', 'now-pages-threadi' ),
			),
		);
		$this->setup    = array_combine(
			range( 1, count( $this->setup ) ),
			array_values( $this->setup )
		);
	}

	/**
	 * Return the fields of step 1: the choice of the page.
	 *
	 * @return array<string,mixed>
	 */
	private function get_first_step(): array {
		$slug     = (string) get_option( 'now_pages_threadi_slug', 'now' );
		$conflict = Now_Page::get_instance()->has_conflict( $slug );

		// the page is safe either way: WordPress gives it a free permalink
		// of its own, so a taken address only affects the virtual route.
		$page_description = $this->get_page_builder_hint();

		if ( $conflict ) {
			$page_description .= ' ' . sprintf(
				/* translators: %1$s will be replaced by an address. */
				esc_html__( 'The address %1$s is already taken, so your page will get a free one of its own.', 'now-pages-threadi' ),
				'/' . esc_html( $slug ) . '/'
			);
		}

		$virtual_description = esc_html__( 'Configure how to present your entries.', 'now-pages-threadi' );

		if ( $conflict ) {
			$virtual_description = sprintf(
			/* translators: %1$s will be replaced by an address. */
				esc_html__( 'Not recommended right now: the address %1$s is already used by something else, so your now page would not be reachable there.', 'now-pages-threadi' ),
				'/' . esc_html( $slug ) . '/'
			);
		}

		// return the configured first step.
		return array(
			self::OPTION_CREATE_PAGE => array(
				'type'     => 'RadioControl',
				'label'    => __( 'How should your now page be built?', 'now-pages-threadi' ),
				'options'  => array(
					array(
						'label'       => __( 'Create a page I can design myself', 'now-pages-threadi' ),
						'value'       => 'page',
						'description' => $page_description,
					),
					array(
						'label'       => sprintf(
						/* translators: %1$s will be replaced by an address. */
							__( 'Just the address %1$s, without a page', 'now-pages-threadi' ),
							'/' . $slug . '/'
						),
						'value'       => 'virtual',
						'description' => $virtual_description,
					),
				),
				'default'  => 'page',
				'required' => true,
			),
		);
	}

	/**
	 * Return the fields of step 1: the choice of a preset.
	 *
	 * @return array<string,mixed>
	 */
	private function get_second_step(): array {
		// collect the presets which can be used on this website.
		$presets = array();

		foreach ( Presets::get_instance()->get_available_presets() as $preset ) {
			$presets[] = array(
				'label'       => $preset->get_label(),
				'value'       => $preset->get_slug(),
				'description' => $preset->get_description(),
				'icon'        => $preset->get_icon(),
			);
		}

		// offer the advisor first: it is the way for everything the presets do not cover.
		if ( $this->is_advisor_offered() ) {
			array_unshift(
				$presets,
				array(
					'label'       => __( 'Get advice', 'now-pages-threadi' ),
					'value'       => Advisor::SLUG,
					'description' => __( 'Describe what you would like to show. An AI model proposes matching sources, and each one is tried before it is offered to you.', 'now-pages-threadi' ),
					'icon'        => $this->get_advisor_icon(),
				)
			);
		}

		// show a hint if no preset can be offered at all.
		if ( empty( $presets ) ) {
			return array(
				'help' => array(
					'type' => 'Text',
					'text' => '<p>' . esc_html__( 'No source can be offered right now, because every provider has been disabled in the settings.', 'now-pages-threadi' ) . '</p>',
				),
			);
		}

		// prepare the preset.
		$preset = array(
			self::OPTION_PRESET => array(
				'type'     => 'CardControl',
				'options'  => $presets,
				'label'    => __( 'Which of your activities would you like to feature on your "now page"?', 'now-pages-threadi' ),
				'required' => true,
			),
		);

		// tell what is missing for the advisor, if it could be offered.
		$advisor_hint = $this->is_setup_request() ? Advisor::get_instance()->get_unavailable_reason() : '';
		if ( '' !== $advisor_hint ) {
			$preset['help0'] = array(
				'type' => 'Text',
				'text' => '<p><span class="dashicons dashicons-lightbulb"></span> ' . wp_kses_post( $advisor_hint ) . '</p>',
			);
		}

		// add help texts.
		$preset['help1'] = array(
			'type' => 'Text',
			/* translators: %1$s will be replaced by our support-forum-URL. */
			'text' => '<p><span class="dashicons dashicons-controls-skipforward"></span> ' . sprintf( __( '<strong>Unsure?</strong> Skip this setup and <a href="%1$s">add your source manually</a>. You can also add more sources at any time later.', 'now-pages-threadi' ), esc_url( get_admin_url() . 'post-new.php?post_type=' . Sources::get_instance()->get_slug() . '&now_pages_threadi_manual=1' ) ) . '</p>',
		);
		$preset['help2'] = array(
			'type' => 'Text',
			/* translators: %1$s will be replaced by our support-forum-URL. */
			'text' => '<p><span class="dashicons dashicons-editor-help"></span> ' . sprintf( __( '<strong>Missing something?</strong> You can ask for more in <a href="%1$s" target="_blank">our forum%2$s</a>.', 'now-pages-threadi' ), esc_url( Plugin::get_plugin_support_url() ), Plugin::get_a11n_window_hint() ) . '</p>',
		);

		return $preset;
	}

	/**
	 * Return the headline showing which preset was chosen.
	 *
	 * @param Preset_Interface $preset The chosen preset.
	 *
	 * @return array<string,string>
	 */
	private function get_preset_headline( Preset_Interface $preset ): array {
		return $this->get_headline( $preset->get_label(), $preset->get_icon() );
	}

	/**
	 * Return a headline showing what was chosen.
	 *
	 * @param string $label The label, plain text.
	 * @param string $icon  The icon as trusted markup, may be empty.
	 *
	 * @return array<string,string>
	 */
	private function get_headline( string $label, string $icon ): array {
		if ( '' !== $icon ) {
			$icon = '<span class="now-pages-threadi-setup-chosen__icon">' . $icon . '</span>';
		}

		return array(
			'type' => 'Text',
			'text' => '<div class="now-pages-threadi-setup-chosen">'
						. $icon
						. '<span class="now-pages-threadi-setup-chosen__label">' . esc_html( $label ) . '</span>'
						. '</div>',
		);
	}

	/**
	 * Return the fields of step 3: the questions of the chosen preset.
	 *
	 * Only reachable once step 1 has been saved, because the setup asks the
	 * server for the fields again before it moves on.
	 *
	 * @return array<string,mixed>
	 */
	private function get_third_step(): array {
		// get the chosen preset.
		$preset = $this->get_chosen_preset();

		// nothing chosen yet - this is what the first page load delivers.
		if ( ! $preset instanceof Preset_Interface ) {
			return array(
				'help' => array(
					'type' => 'Text',
					'text' => '<p>' . esc_html__( 'Please choose a preset on the first page of this setup.', 'now-pages-threadi' ) . '</p>',
				),
			);
		}

		// a preset without questions needs nothing from the user.
		if ( empty( $preset->get_setup_fields() ) ) {
			return array(
				'chosen' => $this->get_preset_headline( $preset ),
				'help'   => array(
					'type' => 'Text',
					'text' => '<p>' . sprintf(
						/* translators: %1$s will be replaced by the name of a service. */
						esc_html__( '%1$s needs no further details. Continue to create the source.', 'now-pages-threadi' ),
						esc_html( $preset->get_label() )
					) . '</p>',
				),
			);
		}

		// build one field per question.
		$fields = array(
			'chosen' => $this->get_preset_headline( $preset ),
		);

		foreach ( $preset->get_setup_fields() as $name => $field ) {
			$label = $name;

			if ( ! empty( $field['label'] ) ) {
				$label = $field['label'];
			}

			$description = '';

			if ( ! empty( $field['description'] ) ) {
				$description = $field['description'];
			}

			$placeholder = '';

			if ( ! empty( $field['placeholder'] ) ) {
				$placeholder = $field['placeholder'];
			}

			$fields[ self::FIELD_PREFIX . $name ] = array(
				'type'        => 'TextControl',
				'label'       => $label,
				'help'        => $description,
				'placeholder' => $placeholder,
				'required'    => ! empty( $field['required'] ),
			);
		}

		return $fields;
	}

	/**
	 * Return the preset chosen in step 1.
	 *
	 * @return Preset_Interface|null
	 */
	private function get_chosen_preset(): ?Preset_Interface {
		$value = '';
		if ( method_exists( '\easySetupForWordPress\Setup', 'get_field_value' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$value = \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::OPTION_PRESET, '' );
		}

		return Presets::get_instance()->get_preset( (string) $value );
	}

	/**
	 * Return whether this request can be part of a setup run.
	 *
	 * The steps are built on every request, also in the frontend. Whatever
	 * only matters while the setup is shown must not cost anything elsewhere.
	 *
	 * @return bool
	 */
	private function is_setup_request(): bool {
		// the requests the setup sends while it is running.
		if ( Plugin::is_rest_request() ) {
			return isset( $_SERVER['REQUEST_URI'] ) && str_contains( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'easy-setup-for-wordpress' );
		}

		// the page the setup is shown on.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return is_admin() && isset( $_GET['page'] ) && Settings::MENU_SLUG . '-setup' === sanitize_text_field( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Return whether the advisor is offered as a way to choose a source.
	 *
	 * @return bool
	 */
	private function is_advisor_offered(): bool {
		return $this->is_setup_request() && Advisor::get_instance()->is_available();
	}

	/**
	 * Return whether the user chose the advisor instead of a preset.
	 *
	 * @return bool
	 */
	private function is_advisor_chosen(): bool {
		// bail if the library cannot tell us.
		if ( ! method_exists( '\easySetupForWordPress\Setup', 'get_field_value' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			return false;
		}

		// bail if something else was chosen. this is asked first, as it is the cheap question.
		if ( Advisor::SLUG !== (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::OPTION_PRESET, '' ) ) {
			return false;
		}

		return $this->is_advisor_offered();
	}

	/**
	 * Return the icon of the advisor.
	 *
	 * @return string
	 */
	private function get_advisor_icon(): string {
		return '<span class="dashicons dashicons-lightbulb"></span>';
	}

	/**
	 * Return what the user asked the advisor for.
	 *
	 * @return string
	 */
	private function get_wish(): string {
		return Advisor::get_instance()->normalize_wish( (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::OPTION_WISH, '' ) );
	}

	/**
	 * Return the proposal of the advisor the user chose.
	 *
	 * @return array<string,mixed>|null Null if nothing was chosen or the proposals are gone.
	 */
	private function get_chosen_proposal(): ?array {
		$advice = Advisor::get_instance()->get_cached_advice( $this->get_wish() );

		// bail if there is no advice for this wish.
		if ( null === $advice ) {
			return null;
		}

		$chosen = (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::OPTION_PROPOSAL, '' );

		// bail if this is not one of the proposals.
		if ( '' === $chosen || ! isset( $advice['proposals'][ $chosen ] ) ) {
			return null;
		}

		return $advice['proposals'][ $chosen ];
	}

	/**
	 * Return the fields of the first step of the advisor: the wish.
	 *
	 * @return array<string,mixed>
	 */
	private function get_advisor_wish_step(): array {
		return array(
			'chosen'          => $this->get_headline( __( 'Get advice', 'now-pages-threadi' ), $this->get_advisor_icon() ),
			self::OPTION_WISH => array(
				'type'        => 'TextControl',
				'label'       => __( 'What would you like to show on your now page?', 'now-pages-threadi' ),
				'help'        => esc_html__( 'Describe it in your own words and name the service you use for it. If you know your account name there, add it.', 'now-pages-threadi' ),
				'placeholder' => __( 'The films I log on Letterboxd', 'now-pages-threadi' ),
				'required'    => true,
			),
			'help'            => array(
				'type' => 'Text',
				'text' => '<p class="now-pages-threadi-advisor-wish"><span class="dashicons dashicons-info-outline"></span> ' . esc_html__( 'Your description and the list of source types this website offers are sent to the AI service connected to this website. Each proposal is then tried against the real service, so the next step can take up to a minute.', 'now-pages-threadi' ) . '</p>',
			),
		);
	}

	/**
	 * Return the fields of the second step of the advisor: its proposals.
	 *
	 * Only reads what the advisor found already, see prepare_advice() for
	 * the moment it is asked.
	 *
	 * @return array<string,mixed>
	 */
	private function get_advisor_proposals_step(): array {
		$advice = Advisor::get_instance()->get_cached_advice( $this->get_wish() );

		// nothing to show yet, or the advisor failed.
		if ( null === $advice ) {
			$text = __( 'Please describe on the previous page what you would like to show.', 'now-pages-threadi' );

			if ( '' !== $this->advice_error ) {
				/* translators: %1$s will be replaced by an error message. */
				$text = sprintf( __( 'The advice could not be requested: %1$s', 'now-pages-threadi' ), $this->advice_error );
			}

			return array(
				self::OPTION_PROPOSAL => array(
					'type'     => 'CardControl',
					'label'    => __( 'No proposals available', 'now-pages-threadi' ),
					'options'  => array(),
					'help'     => '<p>' . esc_html( $text ) . '</p>' . $this->get_advisor_fallback_hint(),
					'required' => true,
				),
			);
		}

		$options = array();

		foreach ( $advice['proposals'] as $proposal ) {
			$options[] = array(
				'label'       => (string) $proposal['title'],
				'value'       => (string) $proposal['id'],
				'description' => trim( (string) $proposal['reason'] . ' ' . $this->get_proposal_state( $proposal ) ),
				'icon'        => $this->get_proposal_icon( $proposal ),
			);
		}

		// collect what the user should know beside the proposals.
		$help = '';

		if ( '' !== $advice['message'] ) {
			$help .= '<p>' . Advisor::get_instance()->format_message( (string) $advice['message'] ) . '</p>';
		}

		if ( ! empty( $advice['rejected'] ) ) {
			$help .= '<p>' . esc_html__( 'Proposed, but not offered to you:', 'now-pages-threadi' ) . '</p><ul>';
			foreach ( $advice['rejected'] as $rejected ) {
				$help .= '<li>' . ( '' !== $rejected['title'] ? '<strong>' . esc_html( $rejected['title'] ) . '</strong>: ' : '' ) . esc_html( $rejected['message'] ) . '</li>';
			}
			$help .= '</ul>';
		}

		// nothing which can be chosen: say what else is possible.
		if ( empty( $options ) ) {
			$help .= $this->get_advisor_fallback_hint();
		}

		return array(
			self::OPTION_PROPOSAL => array(
				'type'     => 'CardControl',
				'label'    => empty( $options ) ? __( 'No matching source was found', 'now-pages-threadi' ) : __( 'These sources match your description', 'now-pages-threadi' ),
				'options'  => $options,
				'help'     => $help,
				'required' => true,
			),
		);
	}

	/**
	 * Return the fields of the third step of the advisor: what is still open.
	 *
	 * @return array<string,mixed>
	 */
	private function get_advisor_details_step(): array {
		$proposal = $this->get_chosen_proposal();

		// nothing chosen yet - this is what every earlier page load delivers.
		if ( null === $proposal ) {
			return array(
				'help' => array(
					'type' => 'Text',
					'text' => '<p>' . esc_html__( 'Please choose a proposal on the previous page.', 'now-pages-threadi' ) . '</p>',
				),
			);
		}

		$fields = array(
			'chosen' => $this->get_headline( (string) $proposal['title'], $this->get_proposal_icon( $proposal ) ),
		);

		// show what the check found, it is the best proof that this is the right source.
		if ( ! empty( $proposal['check']['samples'] ) ) {
			$list = '';
			foreach ( $proposal['check']['samples'] as $sample ) {
				$list .= '<li>' . esc_html( (string) $sample['text'] ) . '</li>';
			}

			$fields['samples'] = array(
				'type' => 'Text',
				'text' => '<p>' . esc_html__( 'This source has been tried just now. Its latest entries:', 'now-pages-threadi' ) . '</p><ul class="now-pages-threadi-advisor-samples">' . $list . '</ul>',
			);
		}

		// ask what the proposal left open, and show what the user may put in their own words.
		foreach ( $proposal['questions'] as $name => $question ) {
			$fields[ $this->get_answer_field( $proposal, (string) $name ) ] = array(
				'type'        => 'TextControl',
				'label'       => (string) $question['label'],
				'help'        => esc_html( (string) $question['help'] ),
				'placeholder' => (string) $question['placeholder'],
				'required'    => Advisor::QUESTION_SUGGESTION !== ( $question['kind'] ?? '' ),
			);
		}

		if ( ! Advisor::get_instance()->has_open_questions( $proposal ) ) {
			$fields['help'] = array(
				'type' => 'Text',
				'text' => '<p>' . sprintf(
					/* translators: %1$s will be replaced by the name of a service. */
					esc_html__( '%1$s needs no further details. Continue to create the source.', 'now-pages-threadi' ),
					esc_html( (string) $proposal['title'] )
				) . '</p>',
			);
		} else {
			$fields['help'] = array(
				'type' => 'Text',
				'text' => '<p>' . esc_html__( 'The source is tried with your details when it is created. If it cannot be read, it is saved as a draft for you to correct.', 'now-pages-threadi' ) . '</p>',
			);
		}

		// a key is never asked for here, the user enters it where every other key is entered.
		if ( ! empty( $proposal['secrets'] ) ) {
			$fields['secrets'] = array(
				'type' => 'Text',
				'text' => '<p><span class="dashicons dashicons-admin-network"></span> ' . sprintf(
					/* translators: %1$s will be replaced by a list of field names. */
					esc_html__( 'This source also needs: %1$s. You enter this in the source itself after it has been created. Until then it is saved as a draft.', 'now-pages-threadi' ),
					esc_html( implode( ', ', array_map( 'strval', $proposal['secrets'] ) ) )
				) . '</p>',
			);
		}

		return $fields;
	}

	/**
	 * Return what the check says about a proposal, for its card.
	 *
	 * @param array<string,mixed> $proposal The proposal.
	 *
	 * @return string
	 */
	private function get_proposal_state( array $proposal ): string {
		if ( Advisor::get_instance()->has_open_questions( $proposal ) ) {
			return __( 'Needs a detail from you, it is tried afterwards.', 'now-pages-threadi' );
		}

		if ( ! empty( $proposal['secrets'] ) ) {
			return __( 'Needs a key, which you enter in the source afterwards.', 'now-pages-threadi' );
		}

		return (string) ( $proposal['check']['message'] ?? '' );
	}

	/**
	 * Return the icon of a proposal.
	 *
	 * @param array<string,mixed> $proposal The proposal.
	 *
	 * @return string
	 */
	private function get_proposal_icon( array $proposal ): string {
		$preset = Presets::get_instance()->get_preset( (string) $proposal['preset'] );

		// use the icon of the preset, the user knows the service by it.
		if ( $preset instanceof Preset_Interface && '' !== $preset->get_icon() ) {
			return $preset->get_icon();
		}

		$icons = array(
			'rss'     => 'rss',
			'ics'     => 'calendar-alt',
			'webhook' => 'download',
		);

		return '<span class="dashicons dashicons-' . esc_attr( $icons[ (string) $proposal['provider'] ] ?? 'admin-links' ) . '"></span>';
	}

	/**
	 * Return what the user can do if the advisor found nothing.
	 *
	 * @return string
	 */
	private function get_advisor_fallback_hint(): string {
		return '<p>' . sprintf(
			/* translators: %1$s will be replaced by a URL, %2$s by the URL of our forum, %3$s by a hint that a new window opens. */
			__( 'Go back to describe it differently or to choose a service from the list. You can also <a href="%1$s">add a source manually</a>, or ask for a service in <a href="%2$s" target="_blank">our forum%3$s</a>.', 'now-pages-threadi' ),
			esc_url( get_admin_url() . 'post-new.php?post_type=' . Sources::get_instance()->get_slug() . '&now_pages_threadi_manual=1' ),
			esc_url( Plugin::get_plugin_support_url() ),
			Plugin::get_a11n_window_hint()
		) . '</p>';
	}

	/**
	 * Ask the advisor right before the setup asks for its fields.
	 *
	 * The steps are built on every request, so asking a model while they are
	 * built is not an option. This is the one request which needs the answer:
	 * the setup saved the wish and now fetches the fields of the next step.
	 *
	 * @param mixed $response The response so far, passed through unchanged.
	 * @param mixed $handler  The route handler, not used.
	 * @param mixed $request  The request.
	 *
	 * @return mixed
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function prepare_advice( mixed $response, mixed $handler, mixed $request ): mixed {
		// bail if this is not the request for the fields of our setup.
		if ( ! $request instanceof WP_REST_Request || '/easy-setup-for-wordpress/v1/fields/' . $this->get_setup_name() !== $request->get_route() ) {
			return $response;
		}

		// bail if the user may not run the setup: this filter runs before the permission check.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $response;
		}

		$this->load_advice();

		return $response;
	}

	/**
	 * Get the advice for the stored wish and rebuild the steps with it.
	 *
	 * @return void
	 */
	private function load_advice(): void {
		// bail if the advisor was not chosen.
		if ( ! $this->is_advisor_chosen() ) {
			return;
		}

		$wish = $this->get_wish();

		// bail if there is no wish yet, or if its advice is known already.
		if ( '' === $wish || null !== Advisor::get_instance()->get_cached_advice( $wish ) ) {
			return;
		}

		// asking a model and trying its proposals takes its time.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 2 * MINUTE_IN_SECONDS ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- the import needs more time; may be forbidden by the hosting.
		}

		$advice = Advisor::get_instance()->get_advice( $wish );

		$this->advice_error = is_wp_error( $advice ) ? $advice->get_error_message() : '';

		// the steps of this request were built without the advice, build them again.
		$this->setup = array();
		\easySetupForWordPress\Setup::get_instance()->set_config( $this->get_config() );
	}

	/**
	 * Create a source from the proposal the user chose.
	 *
	 * @return void
	 */
	private function create_source_from_advice(): void {
		$proposal = $this->get_chosen_proposal();

		// bail if the proposal is gone, e.g. because the setup stayed open for too long.
		if ( null === $proposal ) {
			$this->process_result = __( 'The proposals are no longer available, so no source was created. Please start again.', 'now-pages-threadi' );

			return;
		}

		// collect the answers to its questions.
		$answers = array();

		foreach ( $proposal['questions'] as $name => $question ) {
			$field = $this->get_answer_field( $proposal, (string) $name );

			$answers[ (string) $name ] = (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), $field, '' );

			// a suggestion may stay unanswered. Forget what was written this time: left in place,
			// it would be used without a word the next time the same proposal comes up.
			if ( Advisor::QUESTION_SUGGESTION === ( $question['kind'] ?? '' ) && '' !== $answers[ (string) $name ] ) {
				\easySetupForWordPress\Setup::get_instance()->set_field_value( $this->get_setup_name(), $field, '' );
			}
		}

		$created = Advisor::get_instance()->create_source( $proposal, $answers );

		// bail if nothing was created.
		if ( is_wp_error( $created ) ) {
			/* translators: %1$s will be replaced by a reason. */
			$this->process_result = sprintf( __( 'No source was created. %1$s', 'now-pages-threadi' ), esc_html( $created->get_error_message() ) );

			return;
		}

		// a published source needs no explanation.
		if ( 'draft' !== $created['status'] ) {
			return;
		}

		$link = '<a href="' . esc_url( (string) get_edit_post_link( $created['source_id'], '' ) ) . '">' . esc_html( get_the_title( $created['source_id'] ) ) . '</a>';

		// a key is missing, which only the user has.
		if ( ! empty( $created['secrets'] ) ) {
			$this->process_result = sprintf(
				/* translators: %1$s will be replaced by a link to a source, %2$s by a list of field names. */
				__( 'The source %1$s has been saved as a draft, because it still needs: %2$s. Enter it there and publish the source, it is imported from then on.', 'now-pages-threadi' ),
				$link,
				esc_html( implode( ', ', $created['secrets'] ) )
			);

			return;
		}

		$this->process_result = sprintf(
			/* translators: %1$s will be replaced by a link to a source, %2$s by an error message. */
			__( 'The source %1$s has been saved as a draft, because it could not be read: %2$s Correct its settings there and publish it, it is imported from then on.', 'now-pages-threadi' ),
			$link,
			'<code>' . esc_html( (string) $created['check']['message'] ) . '</code>'
		);
	}

	/**
	 * Return the name of the setup field holding the answer to a question of a proposal.
	 *
	 * The answer to a suggestion belongs to its proposal: it is optional, so an
	 * answer given to another proposal must not be found under the same name.
	 *
	 * @param array<string,mixed> $proposal The proposal.
	 * @param string              $name     The name of the question.
	 *
	 * @return string
	 */
	private function get_answer_field( array $proposal, string $name ): string {
		$question = isset( $proposal['questions'][ $name ] ) && is_array( $proposal['questions'][ $name ] ) ? $proposal['questions'][ $name ] : array();

		if ( Advisor::QUESTION_SUGGESTION === ( $question['kind'] ?? '' ) ) {
			return self::ANSWER_PREFIX . $name . '_' . sanitize_key( (string) ( $proposal['id'] ?? '' ) );
		}

		return self::ANSWER_PREFIX . $name;
	}

	/**
	 * Return what an earlier step of the process had to say, to put it in front of a later message.
	 *
	 * @return string
	 */
	private function get_process_result_prefix(): string {
		return '' !== $this->process_result ? $this->process_result . ' ' : '';
	}

	/**
	 * Update max count.
	 *
	 * @param int $add_to_max_count The value to add.
	 *
	 * @return void
	 */
	public function update_max_step( int $add_to_max_count ): void {
		update_option( 'esfw_max_steps', absint( get_option( 'esfw_max_steps' ) ) + $add_to_max_count );
	}

	/**
	 * Run the process.
	 *
	 * @param string $config_name The name of the setup-configuration.
	 *
	 * @return void
	 */
	public function run_process( string $config_name ): void {
		// bail if this is not our setup.
		if ( $config_name !== $this->get_setup_name() ) {
			return;
		}

		// start this run without the result of a previous one.
		$this->process_result = '';

		// update the max steps for this process.
		$this->update_max_step( 4 );

		// step 1: add the source.
		$this->set_process_label( __( 'Adding the source.', 'now-pages-threadi' ) );

		// get the preset.
		$preset = $this->get_chosen_preset();

		// create the source.
		if ( $this->is_advisor_chosen() ) {
			$this->create_source_from_advice();
		} elseif ( $preset instanceof Preset_Interface ) {
			$this->create_source( $preset );
		}
		$this->update_process_step();

		// step 2: import the entries.
		$this->set_process_label( __( 'Importing the entries.', 'now-pages-threadi' ) );
		Sources::get_instance()->import();
		$this->update_process_step();

		// step 3: calculate the optimal period.
		$this->set_process_label( __( 'Calculate the minimum period.', 'now-pages-threadi' ) );

		// get and save the slug of the period with the first entries, but only on the first run.
		// a later run adds a further source, the period chosen by the user must stay as it is.
		if ( ! $this->is_completed() ) {
			$period_slug = Periods::get_instance()->get_smallest_period_with_entries();
			update_option( 'now_pages_threadi_frontend_period', $period_slug );
		}
		$this->update_process_step();

		// step 4: create the page, if the user asked for one.
		$this->set_process_label( __( 'Creating the page.', 'now-pages-threadi' ) );
		$this->maybe_create_page();
		$this->update_process_step();

		// reset the setup.
		$this->clear_all_answers();
	}

	/**
	 * Create a source from the chosen preset.
	 *
	 * @param Preset_Interface $preset The chosen preset.
	 *
	 * @return void
	 */
	private function create_source( Preset_Interface $preset ): void {
		// collect the answers of step 2.
		$setup = array();

		foreach ( array_keys( $preset->get_setup_fields() ) as $name ) {
			$setup[ $name ] = (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::FIELD_PREFIX . $name, '' );
		}

		// get what the preset derives from the answers.
		$config = $preset->get_config( $setup );

		// bail if a source with exactly this configuration already exists.
		$provider_obj = Providers::get_instance()->get_provider( $preset->get_provider_slug() );

		if ( $provider_obj instanceof Provider_Interface ) {
			$duplicate = Sources::get_instance()->get_duplicate( $provider_obj, $config );

			if ( $duplicate > 0 ) {
				$this->process_result = sprintf(
					/* translators: %1$s will be replaced by the name of a service, %2$s by a link to the existing source. */
					__( 'A source for %1$s with these settings already exists, so nothing was created. You can edit %2$s instead.', 'now-pages-threadi' ),
					esc_html( $preset->get_label() ),
					'<a href="' . esc_url( (string) get_edit_post_link( $duplicate, '' ) ) . '">' . esc_html( get_the_title( $duplicate ) ) . '</a>'
				);

				return;
			}
		}

		// create the source. the title is the name of the service, not of
		// the provider - that is the whole point of the presets. A provider
		// without a service behind it names its sources itself.
		$post_id = Source_Builder::get_instance()->insert(
			$preset->get_provider_slug(),
			$config,
			array(
				'title'  => $provider_obj instanceof Provider_Interface ? Source_Builder::get_instance()->get_default_title( $preset, $provider_obj, $config ) : $preset->get_label(),
				'preset' => $preset->get_slug(),
			)
		);

		// bail if the source could not be created.
		if ( is_wp_error( $post_id ) ) {
			Log::get_instance()->add( __( 'The source could not be created during setup.', 'now-pages-threadi' ), 'error', 'system' );

			return;
		}

		/**
		 * Fires after the setup created a source from a preset.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int              $post_id The ID of the new source.
		 * @param Preset_Interface $preset  The used preset.
		 */
		do_action( 'now_pages_threadi_setup_source_created', absint( $post_id ), $preset );
	}

	/**
	 * Show process end text.
	 *
	 * @param string $config_name The name of the setup-configuration.
	 *
	 * @return void
	 */
	public function show_process_end( string $config_name ): void {
		// bail if this is not our setup.
		if ( $config_name !== $this->get_setup_name() ) {
			return;
		}

		$completed_text = __( 'Setup has been run. Your source has been added, and their entries has been retrieved. Click on "Completed" to view them.', 'now-pages-threadi' );

		// a step of the process had something to say, that wins.
		if ( '' !== $this->process_result ) {
			$completed_text = $this->process_result;
		}

		/**
		 * Filter the text for display if the setup has been run.
		 *
		 * @since 1.0.0 Available since 1.0.0
		 * @param string $completed_text The text to show.
		 * @param string $config_name The name of the setup-configuration used.
		 */
		$this->set_process_label( apply_filters( 'now_pages_threadi_setup_process_completed_text', $completed_text, $config_name ) );

		// set steps to max steps.
		$this->update_process_step( $this->get_max_step() );
	}

	/**
	 * Run additional tasks if the setup has been marked as completed.
	 *
	 * @param string $config_name The name of the setup-configuration.
	 *
	 * @return void
	 */
	public function set_completed( string $config_name ): void {
		// bail if this is not our setup.
		if ( $this->get_setup_name() !== $config_name ) {
			return;
		}

		// bail if this is not a request from API.
		if ( ! Plugin::is_rest_request() ) {
			return;
		}

		// clear the permalink cache.
		Frontend::get_instance()->update_slugs();

		/**
		 * Run additional tasks if the setup is marked as completed.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 */
		do_action( 'now_pages_threadi_setup_completed' );

		// return JSON with forward-URL.
		wp_send_json(
			array(
				'forward' => Sources::get_instance()->get_backend_list_url(),
			)
		);
	}

	/**
	 * Return the name for the setup configuration.
	 *
	 * @return string
	 */
	public function get_setup_name(): string {
		return 'now-pages-threadi';
	}

	/**
	 * Return the actual max steps.
	 *
	 * @return int
	 */
	public function get_max_step(): int {
		return absint( get_option( 'esfw_max_steps' ) );
	}

	/**
	 * Uninstall the setup.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		\easySetupForWordPress\Setup::get_instance()->uninstall( $this->get_setup_name() );
	}

	/**
	 * Remove the answers of every preset.
	 *
	 * @return void
	 */
	private function clear_all_answers(): void {
		\easySetupForWordPress\Setup::get_instance()->delete_field_values( $this->get_setup_name() );
	}

	/**
	 * Start a fresh run whenever the setup page is opened directly.
	 *
	 * @return void
	 */
	public function maybe_clear_answers(): void {
		// bail if this is not a regular page call.
		if ( ! is_admin() || wp_doing_ajax() || Plugin::is_rest_request() ) {
			return;
		}

		// bail if this is not our setup page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( ! isset( $_GET['page'] ) || Settings::MENU_SLUG . '-setup' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$this->clear_all_answers();
	}

	/**
	 * Create the page for the now route, if the user asked for one.
	 *
	 * @return void
	 */
	private function maybe_create_page(): void {
		// get the choice.
		$choice = (string) \easySetupForWordPress\Setup::get_instance()->get_field_value( $this->get_setup_name(), self::OPTION_CREATE_PAGE, '' );

		// bail if the user wants the route to stay virtual.
		if ( 'page' !== $choice ) {
			return;
		}

		// bail if something else already occupies our address. creating the
		// page would not help - it would simply never be reachable.
		$slug     = (string) get_option( 'now_pages_threadi_slug', 'now' );
		$conflict = Now_Page::get_instance()->get_conflicting_post( $slug );

		if ( $conflict instanceof WP_Post ) {
			$this->process_result = $this->get_process_result_prefix() . sprintf(
			/* translators: %1$s will be replaced by an address, %2$s by a link to the conflicting entry. */
				__( 'The address %1$s is already used by %2$s, so no page was created. Give that entry another address, or change the address of your now page in the permalink settings. Click on "Complete" to view your prepared Now Page.', 'now-pages-threadi' ),
				'<code>/' . esc_html( $slug ) . '/</code>',
				'<a href="' . esc_url( (string) get_edit_post_link( $conflict->ID, '' ) ) . '">' . esc_html( get_the_title( $conflict ) ) . '</a>'
			);

			return;
		}

		// create the Now Page.
		$page_id = Now_Page::get_instance()->create_page();

		// bail if the page could not be created, create_page() logged it.
		if ( 0 === $page_id ) {
			return;
		}

		$this->process_result = $this->get_process_result_prefix() . sprintf(
		/* translators: %1$s will be replaced by a link to the new page. */
			__( 'Your now page has been created. You can design the page %1$s with your page builder. Click "Complete" to view your Now Page.', 'now-pages-threadi' ),
			'<a href="' . esc_url( (string) get_edit_post_link( $page_id, '' ) ) . '">' . esc_html( get_the_title( $page_id ) ) . '</a>'
		);
	}

	/**
	 * Return a hint naming the page builder a new page would be built with.
	 *
	 * @return string
	 */
	private function get_page_builder_hint(): string {
		$page_builder_obj = Now_Page::get_instance()->get_active_page_builder();

		// bail if nothing is active, the shortcode is the fallback.
		if ( ! $page_builder_obj instanceof PageBuilder_Base ) {
			return __( 'The page will contain the shortcode, which works in every editor.', 'now-pages-threadi' );
		}

		return sprintf(
			/* translators: %1$s will be replaced by the name of a page builder. */
			esc_html__( 'The page will be prepared for %1$s, with our widget already on it.', 'now-pages-threadi' ),
			esc_html( $page_builder_obj->get_label() )
		);
	}
}
