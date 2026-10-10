<?php
/**
 * This file contains the base for any provider.
 *
 * Holds sensible defaults for everything a simple provider does not care
 * about, so a new provider only has to implement slug, label, fields and
 * fetch().
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use easySettingsForWordPress\Fields\Checkbox;
use easySettingsForWordPress\Tab;
use NowPagesThreadi\Plugin\Settings;

/**
 * Object which holds the shared behavior of all providers.
 */
abstract class Provider_Base implements Provider_Interface {
	/**
	 * The maximum length of an entry text in characters, like for the webhook.
	 *
	 * @var int
	 */
	protected const MAX_TEXT_LENGTH = 2000;

	/**
	 * The settings tab for this provider.
	 *
	 * @var Tab
	 */
	protected Tab $settings_tab;

	/**
	 * Initialize this provider.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'add_settings' ), 30 );
	}

	/**
	 * Add Webhook-specific settings.
	 *
	 * @return void
	 */
	public function add_settings(): void {
		// bail if the settings are not needed in this request.
		if ( ! \NowPagesThreadi\Helper\Plugin::is_settings_request() ) {
			return;
		}

		// get the settings object.
		$settings_obj = Settings::get_instance()->get_settings_obj();

		// get the settings page.
		$settings_page = Settings::get_instance()->get_settings_page();

		// get the provider tab.
		$providers_tab = $settings_page->get_tab( 'now_pages_threadi_provider' );
		if ( ! $providers_tab instanceof Tab ) {
			return;
		}

		// add a sub-tab for this provider.
		$this->settings_tab = $providers_tab->add_tab( 'now_pages_threadi_provider_' . $this->get_slug(), 10 );
		$this->settings_tab->set_title( $this->get_label() );

		// add section.
		$section = $this->settings_tab->add_section( 'now_pages_threadi_provider_' . $this->get_slug() . '_section', 10 );
		$section->set_title( $this->get_label() );

		// add setting.
		$setting = $settings_obj->add_setting( 'now_pages_threadi_provider_' . $this->get_slug() . '_enabled' );
		$setting->set_section( $section );
		$setting->set_default( $this->is_default_enabled() ? 1 : 0 );
		$field = new Checkbox( $settings_obj );
		$field->set_title( __( 'Enable', 'now-pages-threadi' ) );
		$field->set_description( __( 'If enabled, this provider can be used for a source.', 'now-pages-threadi' ) );
		$setting->set_field( $field );
	}

	/**
	 * Return a short description shown when choosing this provider.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Return the configuration fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array {
		return array();
	}

	/**
	 * Return whether this provider can run on this website at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Return why this provider cannot run, for the user.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Return whether this provider receives its data instead of fetching it.
	 *
	 * @return bool
	 */
	public function is_push(): bool {
		return false;
	}

	/**
	 * Return whether this provider has settings.
	 *
	 * @return bool
	 */
	protected function has_settings(): bool {
		return false;
	}

	/**
	 * Return the entry attributes this provider fills, usable for content filtering.
	 *
	 * @return array<string,string>
	 */
	public function get_filterable_fields(): array {
		return array();
	}

	/**
	 * Return whether this provider can be used.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return 1 === absint( get_option( 'now_pages_threadi_provider_' . $this->get_slug() . '_enabled', $this->is_default_enabled() ? 1 : 0 ) );
	}

	/**
	 * Return the configuration fields which identify a source.
	 *
	 * Two sources of this provider with the same values in these fields
	 * would deliver exactly the same entries. An empty array means this
	 * provider can be configured any number of times.
	 *
	 * @return array<int,string>
	 */
	public function get_identifying_fields(): array {
		return array();
	}

	/**
	 * Return whether this provider may only be configured once.
	 *
	 * For providers without any configuration that could differ - reading
	 * this website twice makes no sense.
	 *
	 * @return bool
	 */
	public function is_unique(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_test_url( array $config ): string {
		return '';
	}

	/**
	 * Shorten a text from an external source to the maximum length of an entry.
	 *
	 * @param string $text The plain text.
	 *
	 * @return string
	 */
	protected function limit_text_length( string $text ): string {
		// bail if the text is short enough.
		if ( mb_strlen( $text ) <= self::MAX_TEXT_LENGTH ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, self::MAX_TEXT_LENGTH - 1 ) ) . '…';
	}

	/**
	 * Return whether this provider is enabled by default.
	 *
	 * @return bool
	 */
	protected function is_default_enabled(): bool {
		return true;
	}
}
