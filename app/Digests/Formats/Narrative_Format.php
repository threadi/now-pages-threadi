<?php
/**
 * This file contains the narrative (flowing text) digest format.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests\Formats;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Format_Interface;
use NowPagesThreadi\Digests\Summarizer_Interface;
use NowPagesThreadi\Digests\Summarizers;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\Plugin\Templates;
use WP_Error;
use WP_Post;

/**
 * Combine entries into flowing, first-person prose, e.g.
 * "Ich habe am 1.9.2026 am Plugin 'Now Pages' entwickelt. Ich bin aber auch
 * 12km mit dem Fahrrad gefahren."
 *
 * When group_by_category is enabled (default), entries are split by
 * "now_pages_threadi_category" and each group is summarized on its own under a heading.
 * That yields thematic blocks such as:
 *
 *   ### Work
 *   I worked on the plugin …
 *
 *   ### Sport
 *   I rode 12 km by bike …
 *
 * When disabled, entries are summarized as one continuous timeline
 * instead, without headings - but a category's (or source's) override
 * text still applies to its own entries; grouping is only a display
 * choice, never a precondition for an override to take effect.
 *
 * The actual text generation is delegated to a Summarizer_Interface
 * implementation (see Summarizers), so this format stays the same
 * regardless of whether the text comes from a fixed template or an AI model.
 */
class Narrative_Format implements Digest_Format_Interface {

	/**
	 * Return the format slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'narrative';
	}

	/**
	 * Return the format label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Narrative summary', 'now-pages-threadi' );
	}

	/**
	 * Render the given entries as a narrative summary.
	 *
	 * @param array<int,WP_Post>  $entries  Chronologically sorted np_threadi_entry posts.
	 * @param array<string,mixed> $settings Output settings, see Digest_Builder::build().
	 *
	 * @return string
	 */
	public function render( array $entries, array $settings ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		// a narrative only reads correctly in chronological order, no matter
		// how the list format is configured.
		usort(
			$entries,
			static fn ( WP_Post $a, WP_Post $b ): int => strcmp( $a->post_date_gmt, $b->post_date_gmt )
		);

		$summarizer_slug = ! empty( $settings['summarizer'] )
			? (string) $settings['summarizer']
			: Summarizers::get_instance()->get_default_summarizer_slug();

		$summarizer = Summarizers::get_instance()->get_summarizer( $summarizer_slug );

		if ( ! $summarizer instanceof Summarizer_Interface ) {
			return '';
		}

		// use the fallback if the summarizer cannot run on this site right now.
		if ( ! $summarizer->is_available() ) {
			do_action( 'now_pages_threadi_digest_error', new WP_Error( 'now_pages_threadi_summarizer_unavailable', __( 'The configured summarizer is not available.', 'now-pages-threadi' ) ), $summarizer_slug, '' );

			$summarizer = $this->get_fallback_summarizer( $summarizer );

			// bail if there is no fallback.
			if ( ! $summarizer instanceof Summarizer_Interface ) {
				return '';
			}
		}

		$language     = ! empty( $settings['language'] ) ? (string) $settings['language'] : '';
		$group_by_cat = ! isset( $settings['group_by_category'] ) || ! empty( $settings['group_by_category'] );

		if ( ! $group_by_cat ) {
			return $this->render_flat( $entries, $settings, $summarizer, $summarizer_slug, $language );
		}

		$groups = $this->group_by_category( $entries );
		$parts  = array();

		foreach ( $groups as $label => $group_entries ) {
			// cast: numeric category names (e.g. "2024") become int array keys.
			$block = $this->render_block( (string) $label, $group_entries, $summarizer, $summarizer_slug, $language );
			if ( '' !== $block ) {
				$parts[] = $block;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Render entries as flowing prose without category headings.
	 *
	 * Each entry's own override still applies: runs of entries without an
	 * override are summarized together, and an override text is shown
	 * once, at the position of its first matching entry - so a second,
	 * third, ... category's override (and its entries) is never silently
	 * dropped just because the output is not grouped.
	 *
	 * @param array<int,WP_Post>   $entries         Chronologically sorted entries.
	 * @param array<string,mixed>  $settings        Output settings, see Digest_Builder::build().
	 * @param Summarizer_Interface $summarizer      The summarizer backend.
	 * @param string               $summarizer_slug Slug (for the error action).
	 * @param string               $language        Target language code.
	 *
	 * @return string
	 */
	private function render_flat( array $entries, array $settings, Summarizer_Interface $summarizer, string $summarizer_slug, string $language ): string {
		$ignore_overrides = ! empty( $settings['ignore_overrides'] );

		$parts   = array();
		$shown   = array();
		$pending = array();

		foreach ( $entries as $entry ) {
			$text = $ignore_overrides ? null : Categories::get_instance()->get_override_text_for_entry( $entry );

			// no override on this entry: keep it for the current summarized run.
			if ( null === $text ) {
				$pending[] = $entry;
				continue;
			}

			// an overridden entry ends the current summarized run, if any.
			if ( ! empty( $pending ) ) {
				$block = $this->render_block( '', $pending, $summarizer, $summarizer_slug, $language );
				if ( '' !== $block ) {
					$parts[] = $block;
				}
				$pending = array();
			}

			// show each override text only once, at the position of its first entry.
			if ( isset( $shown[ $text ] ) ) {
				continue;
			}
			$shown[ $text ] = true;

			ob_start();
			require Templates::get_instance()->get_template( 'parts/entry/narrative.php' );
			$content = ob_get_clean();
			if ( '' !== $content ) {
				$parts[] = $content;
			}
		}

		if ( ! empty( $pending ) ) {
			$block = $this->render_block( '', $pending, $summarizer, $summarizer_slug, $language );
			if ( '' !== $block ) {
				$parts[] = $block;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Render one narrative block (optional heading + paragraph).
	 *
	 * @param string               $label           Category label; empty = no heading.
	 * @param array<int,WP_Post>   $entries         Entries for this block.
	 * @param Summarizer_Interface $summarizer      The summarizer backend.
	 * @param string               $summarizer_slug Slug (for the error action).
	 * @param string               $language        Target language code.
	 *
	 * @return string
	 */
	private function render_block( string $label, array $entries, Summarizer_Interface $summarizer, string $summarizer_slug, string $language ): string {
		$override = Categories::get_instance()->get_override_text_for_group( $entries );

		if ( null !== $override ) {
			if ( '' === trim( wp_strip_all_tags( $override ) ) ) {
				return '';
			}

			$body = wpautop( wp_kses_post( $override ) );
		} else {
			$text = $summarizer->summarize( $entries, $language );
			$body = '';

			if ( is_wp_error( $text ) ) {
				/**
				 * Fires when a narrative summary could not be generated.
				 *
				 * @since 1.0.0 Available since 1.0.0.
				 *
				 * @param WP_Error $text      The resulting error.
				 * @param string   $summarizer The summarizer slug that failed.
				 * @param string   $label      Category label of the failed block (empty if ungrouped).
				 */
				do_action( 'now_pages_threadi_digest_error', $text, $summarizer_slug, $label );

				$text = '';
			}

			// the summary may not be there yet (another request generates it, the last request
			// failed, the budget is used up): show the one of the fallback meanwhile.
			if ( '' === trim( $text ) ) {
				$fallback = $this->get_fallback_summarizer( $summarizer );
				$text     = $fallback instanceof Summarizer_Interface ? $fallback->summarize( $entries, $language ) : '';
			}

			if ( is_wp_error( $text ) || '' === trim( $text ) ) {
				return '';
			}

			ob_start();
			require Templates::get_instance()->get_template( 'parts/entry/narrative.php' );
			$content = ob_get_clean();
			if ( '' !== $content ) {
				$body = $content;
			}
		}

		$output = '';

		if ( '' !== $label ) {
			ob_start();
			require Templates::get_instance()->get_template( 'parts/category.php' );
			$output .= ob_get_clean();
		}

		$output .= $body;

		return $output;
	}

	/**
	 * Return the summarizer to use if the given one cannot deliver a summary.
	 *
	 * This is the template summarizer: it runs on every website and needs no
	 * external service, so a visitor gets a text instead of an empty block.
	 *
	 * @param Summarizer_Interface $summarizer The summarizer which cannot deliver.
	 *
	 * @return Summarizer_Interface|null Null if there is no other summarizer to use.
	 */
	private function get_fallback_summarizer( Summarizer_Interface $summarizer ): ?Summarizer_Interface {
		$fallback = Summarizers::get_instance()->get_summarizer( 'template' );

		// bail if the fallback is missing, the same or cannot run either.
		if ( ! $fallback instanceof Summarizer_Interface || $fallback->get_slug() === $summarizer->get_slug() || ! $fallback->is_available() ) {
			return null;
		}

		return $fallback;
	}

	/**
	 * Split entries into groups keyed by category label.
	 *
	 * Order of groups follows the chronological order of first appearance
	 * (same behavior as Verbatim_Format).
	 *
	 * @param array<int,WP_Post> $entries Chronologically sorted entries.
	 *
	 * @return array<string,array<int,WP_Post>>
	 */
	private function group_by_category( array $entries ): array {
		$groups = array();

		foreach ( $entries as $entry ) {
			$label              = Categories::get_instance()->get_category_label( $entry );
			$groups[ $label ][] = $entry;
		}

		return $groups;
	}
}
