<?php
/**
 * This file contains the verbatim (1:1 list) digest format.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests\Formats;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Format_Interface;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\NowPages\Categories;
use ThreadiNowPages\Plugin\Templates;
use WP_Post;

/**
 * Output entries 1:1 as a list, e.g. "10:00 War einkaufen".
 */
class Verbatim_Format implements Digest_Format_Interface {

	/**
	 * Return the format slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'verbatim';
	}

	/**
	 * Return the format label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( '1:1 list', 'threadi-now-pages' );
	}

	/**
	 * Render the given entries as a (optionally grouped) list.
	 *
	 * @param array<int,WP_Post>  $entries  Chronologically sorted threadi_np_entry posts.
	 * @param array<string,mixed> $settings Output settings, see Digest_Builder::build().
	 *
	 * @return string
	 */
	public function render( array $entries, array $settings ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		$ignore_overrides = ! empty( $settings['ignore_overrides'] );

		// get the grouped setting.
		if ( ! isset( $settings['group_by_category'] ) || ! empty( $settings['group_by_category'] ) ) {
			return $this->render_grouped( $entries, $settings, $ignore_overrides );
		}

		// ungrouped output must still honor each entry's own category (or
		// source) override - grouping is only a display choice, not a
		// precondition for an override text to apply.
		return $this->render_flat( $entries, $settings, $ignore_overrides );
	}

	/**
	 * Render entries split into one heading + list/override block per category.
	 *
	 * @param array<int,WP_Post>  $entries          Chronologically sorted entries.
	 * @param array<string,mixed> $settings         Output settings, see Digest_Builder::build().
	 * @param bool                $ignore_overrides Whether override texts are disabled for this render.
	 *
	 * @return string
	 */
	private function render_grouped( array $entries, array $settings, bool $ignore_overrides ): string {
		// prepare the grouping.
		$groups = array();

		foreach ( $entries as $entry ) {
			$label              = Categories::get_instance()->get_category_label( $entry );
			$groups[ $label ][] = $entry;
		}

		// prepare the output.
		$output = array();

		foreach ( $groups as $label => $group_entries ) {
			// cast: numeric category names (e.g. "2024") become int array keys.
			$label = (string) $label;

			if ( '' !== $label ) {
				ob_start();
				require Templates::get_instance()->get_template( 'parts/category.php' );
				$output[] = ob_get_clean();
			}

			// get overrides if they are not ignored.
			$override = $ignore_overrides
				? null
				: Categories::get_instance()->get_override_text_for_group( $group_entries );

			// use the override if given.
			if ( null !== $override ) {
				$group_entries_count = count( $group_entries );
				ob_start();
				require Templates::get_instance()->get_template( 'parts/override.php' );
				$output[] = ob_get_clean();
				continue;
			}

			$output[] = $this->render_list( $group_entries, $settings );
		}

		return implode( "\n", $output );
	}

	/**
	 * Render entries as one continuous timeline, without category headings.
	 *
	 * Each entry's own override (category, then source) still applies:
	 * runs of entries without an override are rendered as a plain list,
	 * and an override text is shown once, at the position of its first
	 * matching entry - so entries of a second, third, ... category are
	 * never silently dropped just because the output is not grouped.
	 *
	 * @param array<int,WP_Post>  $entries          Chronologically sorted entries.
	 * @param array<string,mixed> $settings         Output settings, see Digest_Builder::build().
	 * @param bool                $ignore_overrides Whether override texts are disabled for this render.
	 *
	 * @return string
	 */
	private function render_flat( array $entries, array $settings, bool $ignore_overrides ): string {
		// resolve each entry's own override up front, so entries sharing
		// one override can be counted before anything is rendered.
		$overrides = array();
		$counts    = array();

		foreach ( $entries as $entry ) {
			$override = $ignore_overrides ? null : Categories::get_instance()->get_override_text_for_entry( $entry );

			$overrides[ $entry->ID ] = $override;

			if ( null !== $override ) {
				$counts[ $override ] = ( $counts[ $override ] ?? 0 ) + 1;
			}
		}

		$output  = array();
		$shown   = array();
		$pending = array();

		foreach ( $entries as $entry ) {
			$override = $overrides[ $entry->ID ];

			// no override on this entry: keep it for the current plain-list run.
			if ( null === $override ) {
				$pending[] = $entry;
				continue;
			}

			// an overridden entry ends the current plain-list run, if any.
			if ( ! empty( $pending ) ) {
				$output[] = $this->render_list( $pending, $settings );
				$pending  = array();
			}

			// show each override text only once, at the position of its first entry.
			if ( isset( $shown[ $override ] ) ) {
				continue;
			}
			$shown[ $override ] = true;

			$group_entries_count = $counts[ $override ];

			ob_start();
			require Templates::get_instance()->get_template( 'parts/override.php' );
			$output[] = ob_get_clean();
		}

		if ( ! empty( $pending ) ) {
			$output[] = $this->render_list( $pending, $settings );
		}

		return implode( "\n", $output );
	}

	/**
	 * Render a run of entries as a plain list.
	 *
	 * @param array<int,WP_Post>  $entries  Entries to render.
	 * @param array<string,mixed> $settings Output settings, see Digest_Builder::build().
	 *
	 * @return string
	 */
	private function render_list( array $entries, array $settings ): string {
		$lines = array();
		foreach ( $entries as $entry ) {
			$lines[] = $this->render_line( $entry, $settings );
		}

		// get the text.
		$text = implode( '', $lines );

		// use the template.
		ob_start();
		require Templates::get_instance()->get_template( 'parts/entry/verbatim.php' );
		$content = ob_get_clean();
		if ( ! $content ) {
			return '';
		}
		return $content;
	}

	/**
	 * Return whether the author of an entry hides the times of their entries.
	 *
	 * The setting "Show times" in the profile is a privacy setting: it also
	 * applies to digests of all users, where a block, a shortcode or the
	 * global setting could show the times otherwise.
	 *
	 * @param WP_Post             $entry    The entry.
	 * @param array<string,mixed> $settings The resolved display settings.
	 *
	 * @return bool
	 */
	private function is_time_hidden_by_author( WP_Post $entry, array $settings ): bool {
		// bail for the digest of a single user: their setting is already part of the settings.
		if ( ! empty( $settings['user_id'] ) ) {
			return false;
		}

		return Display_Settings::is_time_hidden_by_author( absint( $entry->post_author ) );
	}

	/**
	 * Render a single entry as one list item.
	 *
	 * @param WP_Post             $entry    The entry.
	 * @param array<string,mixed> $settings The resolved display settings.
	 *
	 * @return string
	 */
	private function render_line( WP_Post $entry, array $settings ): string {
		// prepare the line.
		$line = '';

		// show time, if enabled and the author of this entry does not hide the times.
		if ( ! empty( $settings['show_time'] ) && ! $this->is_time_hidden_by_author( $entry, $settings ) ) {
			$datetime     = (string) get_the_date( 'c', $entry );
			$time_to_show = (string) get_the_time( (string) get_option( 'time_format' ), $entry );

			ob_start();
			require Templates::get_instance()->get_template( 'parts/entry/verbatim/time.php' );
			$line = ob_get_clean();
		}

		// format the text.
		$line .= '<span class="threadi-now-pages-digest-text">' . Format::linkify_text( Format::get_entry_text( $entry ) ) . '</span>';

		// append the source, if enabled and available.
		$line .= $this->render_source( $entry, $settings );

		ob_start();
		require Templates::get_instance()->get_template( 'parts/entry/verbatim/line.php' );
		$line = ob_get_clean();
		if ( ! $line ) {
			return '';
		}
		return $line;
	}

	/**
	 * Render the source reference for a single entry.
	 *
	 * @param WP_Post             $entry    The entry.
	 * @param array<string,mixed> $settings The resolved display settings.
	 *
	 * @return string Empty string if the source should not be shown.
	 */
	private function render_source( WP_Post $entry, array $settings ): string {
		// bail if the source should not be shown or the entry has none.
		if ( empty( $settings['show_source'] ) || ! $entry->post_parent ) {
			return '';
		}

		// get the name of the source.
		$title = get_the_title( $entry->post_parent );

		// bail if the source has no title.
		if ( '' === $title ) {
			return '';
		}

		// get the URL the provider delivered with this entry.
		$url = ! empty( $settings['link_source'] ) ? (string) get_post_meta( $entry->ID, 'source_url', true ) : '';

		/**
		 * Filter the URL a source name links to.
		 *
		 * Return an empty string to render the name without a link.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param string              $url      The URL, empty for no link.
		 * @param WP_Post             $entry    The entry.
		 * @param array<string,mixed> $settings The resolved display settings.
		 */
		$url = (string) apply_filters( 'threadi_now_pages_digest_source_url', $url, $entry, $settings );

		// link the name, if we got a URL.
		$name = '' !== $url
			? sprintf(
				'<a href="%1$s" rel="nofollow noopener external">%2$s</a>',
				esc_url( $url ),
				esc_html( $title )
			)
			: esc_html( $title );

		// return the source as citation.
		return ' <cite class="threadi-now-pages-digest-source">' . $name . '</cite>';
	}
}
