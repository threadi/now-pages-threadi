<?php
/**
 * This file contains the Wehook Provider.
 *
 * @package threadi-now-pages
 */

declare( strict_types=1 );

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Field_Base;
use easySettingsForWordPress\Fields\Button;
use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Fields\Password;
use easySettingsForWordPress\Section;
use easySettingsForWordPress\Setting;
use ThreadiNowPages\NowPages\Entry;
use ThreadiNowPages\Plugin\Crypt;
use ThreadiNowPages\Plugin\Settings;

/**
 * Object for the Webhook Provider.
 */
class Webhook_Provider extends Provider_Base {
	/**
	 * Initialize this provider.
	 *
	 * @return void
	 */
	public function init(): void {
		parent::init();
		add_action( 'init', array( $this, 'add_provider_settings' ), 40 );
		add_action( 'threadi_now_pages_source_duplicate', array( $this, 'check_for_duplicates' ), 10, 3 );
	}

	/**
	 * Add the settings for this provider.
	 *
	 * @return void
	 */
	public function add_provider_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! \ThreadiNowPages\Helper\Plugin::is_settings_request() ) {
			return;
		}

		// get the settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// add section.
		$section = $this->settings_tab->get_section( 'threadi_now_pages_provider_' . $this->get_slug() . '_section' );
		if ( ! $section instanceof Section ) {
			return;
		}
		$section->set_callback( array( $this, 'show_instructions' ) );

		// get and extend the main setting.
		$webhook_setting = $settings_obj->get_setting( 'threadi_now_pages_provider_' . $this->get_slug() . '_enabled' );
		if ( $webhook_setting instanceof Setting ) {
			$field = $webhook_setting->get_field();
			if ( $field instanceof Field_Base ) {
				$field->set_description( __( 'Lets external tools push entries into your now page. Disabled by default - only enable it if you actually use it.', 'threadi-now-pages' ) );
			}
		}

		// add setting.
		$setting = $settings_obj->add_setting( Webhook_Controller::OPTION_TOKEN );
		$setting->set_section( $section );
		$setting->set_type( 'string' );
		$setting->set_default( '' );
		$setting->prevent_export( true );
		$setting->set_read_callback( Crypt::get_instance()->get_read_callback( $setting->get_name() ) );
		$setting->set_save_callback( Crypt::get_instance()->get_save_callback( $setting->get_name() ) );
		$field = new Password( $settings_obj );
		$field->set_title( __( 'Token', 'threadi-now-pages' ) );
		$field->set_description(
			sprintf(
			/* translators: %1$d will be replaced by the minimal number of characters. */
				__( 'The secret your automation tool has to send in the X-Now-Pages-Token header. Anyone who knows it can add entries. Use at least %1$d characters - shorter tokens are not accepted. Best use the "Generate" button below.', 'threadi-now-pages' ),
				Webhook_Controller::MIN_TOKEN_LENGTH
			)
		);
		if ( $webhook_setting instanceof Setting ) {
			$field->add_depend( $webhook_setting, 1 );
		}
		$setting->set_field( $field );

		// create URL to generate a token.
		$create_token_url = add_query_arg(
			array(
				'action' => 'threadi_now_pages_generate_webhook_token',
				'nonce'  => wp_create_nonce( 'threadi-now-pages-generate-webhook-token' ),
			),
			get_admin_url() . 'admin.php'
		);

		// add setting.
		$setting = $settings_obj->add_setting( 'threadi_now_pages_webhook_generate' );
		$setting->set_section( $section );
		$setting->prevent_export( true );
		$field = new Button( $settings_obj );
		$field->set_title( __( 'New token', 'threadi-now-pages' ) );
		$field->set_button_title( __( 'Generate', 'threadi-now-pages' ) );
		$field->set_button_url( $create_token_url );
		if ( $webhook_setting instanceof Setting ) {
			$field->add_depend( $webhook_setting, 1 );
		}
		$setting->set_field( $field );
	}

	/**
	 * The slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'webhook';
	}

	/**
	 * The label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Webhook (push)', 'threadi-now-pages' );
	}

	/**
	 * The config fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array(
			'webhook_slug'  => array(
				'label'       => __( 'Slug', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Send this value as "source" in the request body to assign entries to this source. Only lowercase letters, numbers, dashes and underscores are kept, and every webhook source needs its own slug.', 'threadi-now-pages' ),
				'placeholder' => 'discord',
				// the request is matched with sanitize_key(), so the slug is stored the same way.
				'sanitize'    => 'key',
			),
			'webhook_token' => array(
				'label'       => __( 'Token for this source', 'threadi-now-pages' ),
				'type'        => 'password',
				'required'    => false,
				'description' => sprintf(
					/* translators: %1$d will be replaced by the minimal number of characters. */
					__( 'Optional. If set, requests for this source must send this token in the X-Now-Pages-Token header, the global token is not accepted for this source anymore. Use at least %1$d characters - shorter tokens are not accepted.', 'threadi-now-pages' ),
					Webhook_Controller::MIN_TOKEN_LENGTH
				),
			),
		);
	}

	/**
	 * Never called: this provider is push-based, nothing is ever pulled.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array {
		return array();
	}

	/**
	 * Return that this provider is using push.
	 *
	 * @return bool
	 */
	public function is_push(): bool {
		return true;
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Receives entries pushed by external tools. Nothing is fetched - see the webhook section in the import settings.', 'threadi-now-pages' );
	}

	/**
	 * Return whether this provider can run on this website at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return 1 === absint( get_option( 'threadi_now_pages_provider_' . $this->get_slug() . '_enabled' ) );
	}

	/**
	 * Return why this provider cannot run, for the user.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return __( 'The webhook is disabled in the import settings.', 'threadi-now-pages' );
	}

	/**
	 * Show instructions.
	 *
	 * @return void
	 */
	public function show_instructions(): void {
		$url = rest_url( Webhook_Controller::NAMESPACE . Webhook_Controller::ROUTE );

		?>
		<p><?php echo esc_html__( 'External tools can push entries into your now page instead of you polling them. Useful for services like a Discord bot, a n8n or Make flow, a shortcut on your phone.', 'threadi-now-pages' ); ?></p>

		<p>
			<strong><?php echo esc_html__( 'Endpoint', 'threadi-now-pages' ); ?></strong><br>
			<code><?php echo esc_html( $url ); ?></code>
		</p>

		<p>
			<strong><?php echo esc_html__( 'Send a POST request', 'threadi-now-pages' ); ?></strong>
			<?php echo esc_html__( 'with the header X-Now-Pages-Token and a JSON body:', 'threadi-now-pages' ); ?>
		</p>

		<pre><code>
		<?php
		echo esc_html(
			"curl -X POST '" . $url . "' \\\n"
			. "  -H 'Content-Type: application/json' \\\n"
			. "  -H 'X-Now-Pages-Token: " . __( 'YOUR-TOKEN', 'threadi-now-pages' ) . "' \\\n"
			. '  -d \'{"text":"' . __( 'Currently preparing a talk.', 'threadi-now-pages' ) . '","date":"2026-09-06T10:00:00+02:00"}\''
		);
		?>
				</code></pre>

		<p><?php echo esc_html__( 'Entries are assigned to a source, and inherit its author, category and language. Create a source with the provider "Webhook (push)", give it a slug, and send that slug as "source".', 'threadi-now-pages' ); ?></p>
		<?php

		// the supported fields.
		$fields = array(
			'text'             => __( 'Required. The text of the entry.', 'threadi-now-pages' ),
			'date'             => __( 'Optional. ISO 8601. Defaults to the time of the request. Dates in the future or before 2000 are rejected.', 'threadi-now-pages' ),
			'source'           => __( 'Optional. The slug of a webhook source you created under Sources. Determines the author and category of the entry. Defaults to "webhook". If the source has its own token, only this token is accepted.', 'threadi-now-pages' ),
			'external_id'      => __( 'Optional. Prevents duplicates. Derived from text and date if omitted.', 'threadi-now-pages' ),
			'url'              => __( 'Optional. Links the entry back to the original post.', 'threadi-now-pages' ),
			'language'         => __( 'Optional. A locale like de_DE. Only used if the source has no language of its own. Defaults to the language of your site.', 'threadi-now-pages' ),
			'category_term_id' => __( 'Optional. The term ID of a now pages category.', 'threadi-now-pages' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $fields as $name => $description ) {
			echo '<tr><th scope="row"><code>' . esc_html( $name ) . '</code></th><td>' . esc_html( $description ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Return whether this provider has settings.
	 *
	 * @return bool
	 */
	protected function has_settings(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_identifying_fields(): array {
		return array( 'webhook_slug' );
	}

	/**
	 * Delete Webhook marker for duplicates.
	 *
	 * @param Provider_Interface $provider_obj The provider.
	 * @param int                $duplicate The duplicate marker.
	 * @param int                $post_id The post ID.
	 *
	 * @return void
	 */
	public function check_for_duplicates( Provider_Interface $provider_obj, int $duplicate, int $post_id ): void {
		// a duplicate webhook slug is a real conflict: incoming entries would
		// be routed to an arbitrary one of the two sources.
		if ( $duplicate > 0 && 'webhook' === $provider_obj->get_slug() ) {
			delete_post_meta( $post_id, 'webhook_slug' );
		}
	}

	/**
	 * Return whether this provider is enabled by default.
	 *
	 * @return bool
	 */
	protected function is_default_enabled(): bool {
		return false;
	}
}
