<?php
/**
 * This file contains the interface for any narrative summarizer backend.
 *
 * Definition:
 * A summarizer turns a set of entries into flowing, first-person prose.
 * Kept separate from Digest_Format_Interface so the "narrative" format
 * itself never has to know whether the actual text came from a fixed
 * template or an AI model.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Post;

/**
 * Interface for any narrative summarizer backend.
 */
interface Summarizer_Interface {
	/**
	 * Return the summarizer slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the summarizer label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Turn the given entries into a narrative summary.
	 *
	 * @param array<int,WP_Post> $entries  Chronologically sorted np_threadi_entry posts.
	 * @param string             $language Language code the summary should be written in.
	 *
	 * @return string|WP_Error The summary text, or a WP_Error on failure.
	 */
	public function summarize( array $entries, string $language ): string|WP_Error;

	/**
	 * Check whether this summarizer can run on the current site at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Return why this summarizer cannot run and how to fix it.
	 *
	 * May contain simple HTML (links). Empty string if it is available.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string;

	/**
	 * Return whether this summarizer sends the entries to an external service.
	 *
	 * Such a summarizer can cause costs and lets data leave the website, so it
	 * is only used if the administrator chose it in the settings.
	 *
	 * @return bool
	 */
	public function uses_external_service(): bool;
}
