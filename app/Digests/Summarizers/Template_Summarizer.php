<?php
/**
 * This file contains the template-based (offline) narrative summarizer.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Summarizers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Summarizer_Interface;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\NowPages\Entries;
use WP_Error;
use WP_Post;

/**
 * Turn entries into sentences using a filterable template, no external
 * service required. The result reads schematically rather than truly
 * natural, but works offline and with zero running cost.
 */
class Template_Summarizer implements Summarizer_Interface {

	/**
	 * Return the summarizer slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'template';
	}

	/**
	 * Return the summarizer label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Template-based (offline, no external service)', 'threadi-now-pages' );
	}

	/**
	 * Turn the given entries into a narrative summary.
	 *
	 * @param array<int,WP_Post> $entries  Chronologically sorted threadi_np_entry posts.
	 * @param string             $language Language code the summary should be written in.
	 *
	 * @return string|WP_Error
	 */
	public function summarize( array $entries, string $language ): string|WP_Error {
		if ( empty( $entries ) ) {
			return '';
		}

		// switch translations so the output is in $language, regardless of
		// which locale the current request (admin, cron, CLI, ...) is running in.
		$switched = switch_to_locale( $language );

		// prepare the sentences.
		$sentences = array();

		// marker if it has been done.
		$first_done = false;

		// collect them.
		foreach ( $entries as $entry ) {
			$is_manual = Entries::get_instance()->is_manual( $entry );
			$sentence  = $this->build_sentence( $entry, ! $first_done && ! $is_manual );

			if ( '' === $sentence ) {
				continue;
			}

			if ( ! $is_manual ) {
				$first_done = true;
			}

			$sentences[] = $sentence;
		}

		// get them as text.
		$text = implode( ' ', $sentences );

		// switch back to the original language.
		if ( $switched ) {
			restore_current_locale();
		}

		// return the resulting text.
		return $text;
	}

	/**
	 * Build one sentence for a single entry.
	 *
	 * @param WP_Post $entry   The entry.
	 * @param bool    $is_first Whether this is the first sentence of the summary.
	 *
	 * @return string
	 */
	private function build_sentence( WP_Post $entry, bool $is_first ): string {
		$raw = Format::get_entry_text( $entry );

		// bail if the entry has no text at all.
		if ( '' === $raw ) {
			return '';
		}

		// manual entries are already written by the author: show them 1:1.
		if ( Entries::get_instance()->is_manual( $entry ) ) {
			return Format::linkify_text( $this->ensure_terminator( $raw ) );
		}

		$text = $this->soften_start( $raw );

		if ( ! $is_first ) {
			// Continuation: connector + entry text only (avoids “I also I did …”).
			return Format::linkify_text( $this->ensure_terminator( $this->get_connector() . ' ' . $text ) );
		}

		$template = Categories::get_instance()->get_sentence_template_for_entry( $entry );
		$terms    = Categories::get_instance()->get_terms_for_entry( $entry );
		$slugs    = array();
		foreach ( $terms as $term ) {
			$slugs[] = $term->slug;
		}

		/**
		 * Filter the sentence template used to turn one entry's text into a sentence.
		 *
		 * %s is replaced with the entry's text.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string            $template Resolved template (term meta → slug map → default).
		 * @param WP_Post           $entry    The entry being rendered.
		 * @param array<int,string> $slugs    Category slugs assigned to the entry.
		 */
		$template = apply_filters( 'threadi_now_pages_narrative_sentence_template', $template, $entry, $slugs );

		// guard against filters returning non-strings (strict_types).
		$template = is_string( $template ) ? $template : ''; // @phpstan-ignore function.alreadyNarrowedType

		// if the author omitted %s, append the text.
		if ( ! str_contains( $template, '%s' ) ) {
			$template = rtrim( $template ) . ' %s';
		}

		// replace only the %s placeholder; any other % in the template stays literal.
		return Format::linkify_text( str_replace( '%s', $text, $template ) );
	}

	/**
	 * Return a random connector phrase for non-first sentences.
	 *
	 * @return string
	 */
	private function get_connector(): string {
		/**
		 * Filter the pool of connector phrases used between sentences.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string[] $connectors The default connector phrases.
		 */
		$connectors = apply_filters(
			'threadi_now_pages_narrative_connectors',
			array(
				__( 'I also', 'threadi-now-pages' ),
				__( 'Besides that, I', 'threadi-now-pages' ),
				__( 'On top of that, I', 'threadi-now-pages' ),
			)
		);

		// return the matching connector.
		return $connectors[ array_rand( $connectors ) ];
	}

	/**
	 * Lowercase the first character so the text fits mid-sentence templates
	 * (“I did posted a reply” instead of “I did Posted a reply”).
	 *
	 * @param string $text Raw entry text.
	 *
	 * @return string
	 */
	private function soften_start( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		/**
		 * Filter whether the entry text start should be lowercased for narrative templates.
		 *
		 * @param bool   $soften Default true.
		 * @param string $text   The text about to be softened.
		 */
		if ( ! apply_filters( 'threadi_now_pages_narrative_soften_start', true, $text ) ) {
			return $text;
		}

		$first = mb_substr( $text, 0, 1 );
		$rest  = mb_substr( $text, 1 );

		return mb_strtolower( $first ) . $rest;
	}

	/**
	 * Check whether this summarizer can run on the current site at all.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Make sure a sentence ends with a terminator.
	 *
	 * @param string $text The sentence.
	 *
	 * @return string
	 */
	private function ensure_terminator( string $text ): string {
		$text = rtrim( $text );

		foreach ( array( '.', '!', '?', '…', ':' ) as $terminator ) {
			if ( str_ends_with( $text, $terminator ) ) {
				return $text;
			}
		}

		return $text . '.';
	}

	/**
	 * Return why this summarizer cannot run and how to fix it.
	 *
	 * May contain simple HTML (links). Empty string if it is available.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Return whether this summarizer sends the entries to an external service.
	 *
	 * Such a summarizer can cause costs and lets data leave the website, so it
	 * is only used if the administrator chose it in the settings.
	 *
	 * @return bool
	 */
	public function uses_external_service(): bool {
		return false;
	}
}
