<?php
/**
 * This file contains the interface for any digest output format.
 *
 * Definition:
 * A digest format turns a chronologically ordered set of threadi_np_entry
 * posts into final output - either verbatim (1:1 list) or combined into a
 * narrative summary. Formats are storage-agnostic: they only ever see
 * already-saved entries, regardless of which provider or source they came
 * from.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use WP_Post;

/**
 * Interface for any digest output format.
 */
interface Digest_Format_Interface {
	/**
	 * Return the format slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the format label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Render the given entries into final output (plain text or HTML).
	 *
	 * @param array<int,WP_Post>  $entries  Chronologically sorted threadi_np_entry posts.
	 * @param array<string,mixed> $settings Output settings, see Digest_Builder::build().
	 *
	 * @return string
	 */
	public function render( array $entries, array $settings ): string;
}
