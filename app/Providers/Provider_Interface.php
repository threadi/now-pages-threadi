<?php
/**
 * This file contains the interface for any provider.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\NowPages\Entry;

/**
 * Interface for any provider.
 */
interface Provider_Interface {
	/**
	 * Initialize this provider.
	 *
	 * @return void
	 */
	public function init(): void;

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Return the configuration fields.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config_fields(): array;

	/**
	 * Return whether this provider can run on this website at all.
	 *
	 * Check requirements that do not depend on a single source here - a
	 * PHP extension, a minimum WordPress version, a plugin that has to be
	 * active. Anything that depends on the configuration of one source
	 * belongs into fetch() instead.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Return why this provider cannot run, for the user.
	 *
	 * Only meaningful when is_available() returns false.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string;

	/**
	 * Return whether this provider receives its data instead of fetching it.
	 *
	 * Push providers are skipped during a scheduled import: nothing can be
	 * pulled from them, their entries arrive through their own endpoint.
	 *
	 * @return bool
	 */
	public function is_push(): bool;

	/**
	 * Return new entries based on the given config for this provider.
	 *
	 * @param array<string,mixed> $config  The configuration of the source.
	 * @param Provider_Context    $context The context of this run.
	 *
	 * @return array<int,Entry>
	 */
	public function fetch( array $config, Provider_Context $context ): array;

	/**
	 * Return the entry attributes this provider fills, usable for content filtering.
	 *
	 * Keyed by the Entry::$meta key, valued by a human-readable label for
	 * the admin UI (e.g. array( 'author' => __( 'Author', 'threadi-now-pages' ) )).
	 * Return an empty array if this provider does not offer anything
	 * filterable beyond the entry text itself.
	 *
	 * @return array<string,string>
	 */
	public function get_filterable_fields(): array;

	/**
	 * Return whether this provider can be used.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Return the configuration fields which identify a source.
	 *
	 * Two sources of this provider with the same values in these fields
	 * would deliver exactly the same entries. An empty array means this
	 * provider can be configured any number of times.
	 *
	 * @return array<int,string>
	 */
	public function get_identifying_fields(): array;

	/**
	 * Return whether this provider may only be configured once.
	 *
	 * For providers without any configuration that could differ - reading
	 * this website twice makes no sense.
	 *
	 * @return bool
	 */
	public function is_unique(): bool;

	/**
	 * Return the URL to test the provider.
	 *
	 * Hint: should not contain sensible data as it will be visible to the user.
	 *
	 * @param array<string,mixed> $config The given configuration.
	 *
	 * @return string
	 */
	public function get_test_url( array $config ): string;
}
