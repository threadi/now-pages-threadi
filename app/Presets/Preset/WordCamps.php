<?php
/**
 * This file contains the WordCamp preset.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Presets\Preset_Base;

/**
 * Object for the WordCamp preset.
 */
class WordCamps extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'wordcamps';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordCamp', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Shows a WordCamp while it is running. Uses the official calendar of all upcoming events, so name the one you are attending.', 'now-pages-threadi' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'calendar';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'ics';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'wordcamp_filter' => array(
				'label'       => __( 'Which WordCamp', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Part of the name, for example "Europe" or "Leipzig". Only matching events are imported - the calendar holds every WordCamp worldwide.', 'now-pages-threadi' ),
				'placeholder' => 'Europe',
			),
		);
	}

	/**
	 * Turn the given answers into a configuration for the provider.
	 *
	 * @param array<string,string> $setup The answers to get_setup_fields().
	 *
	 * @return array<string,mixed>
	 */
	public function get_config( array $setup ): array {
		// keep accents: the filter is compared with the event titles, e.g. "Montréal".
		$filter = trim( sanitize_text_field( ! empty( $setup['wordcamp_filter'] ) ? $setup['wordcamp_filter'] : '' ) );

		// bail if the answer is unusable.
		if ( '' === $filter ) {
			return array();
		}

		return array(
			'ics_url'            => 'https://central.wordcamp.org/calendar.ics',
			'ics_include'        => $filter,
			'ics_lookahead_days' => 7,
			'ics_max_items'      => 5,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="dashicons dashicons-wordpress"></span>';
	}
}
