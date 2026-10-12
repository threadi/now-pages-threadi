<?php
/**
 * This file contains the Meetup preset.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Presets\Preset_Base;

/**
 * Object for the Meetup preset.
 */
class Meetups extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'meetups';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Meetup', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Show the meetups of a group on meetup.com while they take place, and announce them a week ahead. Uses the public calendar of the group.', 'threadi-now-pages' );
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
			'meetup_group' => array(
				'label'       => __( 'Which group', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The address of the group on meetup.com, or just its name from that address, e.g. "leipzig-wordpress-meetup". Every event of this group is imported, whether you attend or not.', 'threadi-now-pages' ),
				'placeholder' => 'leipzig-wordpress-meetup',
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
		$group = $this->get_group_slug( ! empty( $setup['meetup_group'] ) ? $setup['meetup_group'] : '' );

		// bail if the answer is unusable.
		if ( '' === $group ) {
			return array();
		}

		return array(
			'ics_url'            => 'https://www.meetup.com/' . $group . '/events/ical/',
			'ics_lookahead_days' => 7,
			'ics_max_items'      => 5,
		);
	}

	/**
	 * Return the cleaned group slug.
	 *
	 * @param string $answer The answer.
	 *
	 * @return string
	 */
	private function get_group_slug( string $answer ): string {
		$answer = trim( $answer );

		// a pasted address: take the name of the group out of it, with or without a language in front.
		if ( 1 === preg_match( '~meetup\.com/(?:[a-z]{2}-[A-Z]{2}/)?([^/?#]+)~', $answer, $matches ) ) {
			$answer = $matches[1];
		}

		// what is left may only consist of what the name of a group consists of.
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $answer );
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="dashicons dashicons-groups"></span>';
	}
}
