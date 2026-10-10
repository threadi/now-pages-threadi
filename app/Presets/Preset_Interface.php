<?php
/**
 * This file contains the interface for any preset.
 *
 * A preset is the name an actual person knows - Mastodon, Steam, a
 * WordCamp - mapped onto one of our technical providers, together with a
 * recipe for building its configuration. Several presets can share one
 * provider: everything with a feed runs through RSS_Provider, they only
 * differ in how the URL is assembled.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Interface for any preset.
 */
interface Preset_Interface {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the preset label, as the user knows the service.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string Empty string to use the fallback icon.
	 */
	public function get_icon(): string;

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string One of the keys of Presets::get_groups().
	 */
	public function get_group(): string;

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string;

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * Same shape as Provider_Interface::get_config_fields(), so the same
	 * field objects can render them. Never ask for secrets here: the
	 * answers travel through the URL, API keys belong on the edit screen.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array;

	/**
	 * Turn the given answers into a configuration for the provider.
	 *
	 * @param array<string,string> $setup The answers to get_setup_fields().
	 *
	 * @return array<string,mixed> Map of provider field name => value.
	 */
	public function get_config( array $setup ): array;

	/**
	 * Return whether this preset can be offered on this website.
	 *
	 * @return bool
	 */
	public function is_available(): bool;
}
