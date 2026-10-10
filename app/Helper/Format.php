<?php
/**
 * This file contains format-helper for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use Collator;
use NowPagesThreadi\NowPages\Entries;
use WP_Error;
use WP_Post;

/**
 * Object with format-helper functions for this plugin.
 */
class Format {
	/**
	 * Guard against recursion while rendering the blocks of an entry
	 * (e.g. an entry which contains the digest block itself).
	 *
	 * @var bool
	 */
	private static bool $rendering_blocks = false;

	/**
	 * Create JSON from the given array.
	 *
	 * @param array<string|int,mixed>|WP_Error $source The source array.
	 * @param int                              $flag Flags to use for this JSON.
	 *
	 * @return string
	 */
	public static function get_json( array|WP_Error $source, int $flag = 0 ): string {
		// create JSON.
		$json = wp_json_encode( $source, $flag );

		// bail if creating the JSON failed.
		if ( ! $json ) {
			return '';
		}

		// return the resulting JSON string.
		return $json;
	}

	/**
	 * Return a nat-sorted array of the given array.
	 *
	 * @param array<string,mixed> $array_to_sort The array to sort.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_nat_sorted_array( array $array_to_sort ): array {
		// order them depending on hosting environments.
		if ( class_exists( 'Collator' ) ) {
			// use natural sort via PHP intl extension.
			$collator = new Collator( Languages::get_current_language() );
			$collator->setAttribute( Collator::NUMERIC_COLLATION, Collator::ON );
			uasort(
				$array_to_sort,
				// @phpstan-ignore argument.type
				static function ( $a, $b ) use ( $collator ) {
					return $collator->compare( (string) $a, (string) $b );
				}
			);
		} else {
			// use simple sort.
			uasort( $array_to_sort, 'strcoll' );
		}

		// return the sorted array.
		return $array_to_sort;
	}

	/**
	 * Link URLs in a given text.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	public static function linkify_text( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		$text = apply_filters( 'now_pages_threadi_entry_text_pre_linkify', $text );
		$html = \make_clickable( \esc_html( $text ) );
		return apply_filters( 'now_pages_threadi_entry_text_linkified', $html, $text );
	}

	/**
	 * Convert HTML (e.g. RSS content) to plain text without gluing words.
	 *
	 * WP-owns wp_strip_all_tags() alone removes tags with no separator, so
	 * "Forums:</p><p>More" becomes "Forums:More". Block-level boundaries
	 * are turned into whitespace first.
	 *
	 * @param string $html Raw HTML fragment.
	 *
	 * @return string Plain text, trimmed.
	 */
	public static function html_to_plain_text( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		// normalize common block / line breaks to whitespace before stripping.
		$search  = array(
			'/<br\s*\/?>/i',
			'/<\/p>/i',
			'/<\/div>/i',
			'/<\/h[1-6]>/i',
			'/<\/li>/i',
			'/<\/tr>/i',
			'/<\/blockquote>/i',
			'/<\/figcaption>/i',
			'/<\/dt>/i',
			'/<\/dd>/i',
		);
		$replace = "\n";
		$html    = (string) preg_replace( $search, $replace, $html );

		// drop remaining tags.
		$text = \wp_strip_all_tags( $html );

		// decode the entities (&amp; → &, &#8230; → …).
		$text = \html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// collapse horizontal whitespace; keep single newlines as paragraph hints.
		$text = (string) preg_replace( "/[ \t\f\r]+/", ' ', $text );
		$text = (string) preg_replace( "/ ?\n ?/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		// return the resulting text.
		return trim( $text );
	}

	/**
	 * Return the plain text of an entry.
	 *
	 * Imported entries hold plain text, manually created ones hold block
	 * markup from the editor. Everything that renders an entry needs the
	 * same, normalized input.
	 *
	 * @param WP_Post $entry The entry.
	 *
	 * @return string
	 */
	public static function get_entry_text( WP_Post $entry ): string {
		$text = (string) $entry->post_content;

		// render the blocks, but only for entries written by a user in the block
		// editor. imported entries come from external feeds: rendering them would let
		// the feed author run any dynamic block on this website. their block
		// comments are removed by wp_strip_all_tags() below.
		if ( ! self::$rendering_blocks && has_blocks( $text ) && Entries::get_instance()->is_manual( $entry ) ) {
			self::$rendering_blocks = true;
			try {
				$text = do_blocks( $text );
			} finally {
				self::$rendering_blocks = false;
			}
		}

		// remove shortcodes and markup, entries are single sentences.
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );

		// collapse the whitespace the block markup left behind.
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
