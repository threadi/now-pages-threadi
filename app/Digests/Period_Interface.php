<?php
/**
 * This file contains the interface for any period.
 *
 * A period answers one question: which window of time does a digest
 * cover. It deliberately knows nothing about the delay - that is a cap
 * on the upper bound applied afterward, and letting every period handle
 * it would repeat the logic and let a third party bypass it.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Interface for any period.
 */
interface Period_Interface {
	/**
	 * Return the period slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Return the period label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Return a short description of what this period covers.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Return whether this period can be chosen in the settings.
	 *
	 * A period needing values only a shortcode or a widget can supply does
	 * not belong into the global settings, where those fields do not
	 * exist - it would silently behave like something else.
	 *
	 * @return bool
	 */
	public function is_selectable(): bool;

	/**
	 * Return the raw window this period covers.
	 *
	 * Both values are in Y-m-d H:i:s and in the time of the website. An
	 * empty window means this period cannot be resolved with the given
	 * arguments, and the digest stays empty.
	 *
	 * @param array<string,mixed> $args The resolved arguments.
	 *
	 * @return array{after:string,before:string}
	 */
	public function get_range( array $args ): array;

	/**
	 * Return the note telling a visitor which period is shown.
	 *
	 * Different from get_description(): that one explains the period in the
	 * settings, this one names the window actually being shown.
	 *
	 * @param array<string,mixed>               $args  The resolved arguments.
	 * @param array{after:string,before:string} $range The resolved window.
	 *
	 * @return string
	 */
	public function get_note( array $args, array $range ): string;

	/**
	 * Return whether this period has entries.
	 *
	 * @return bool
	 */
	public function has_entries(): bool;
}
