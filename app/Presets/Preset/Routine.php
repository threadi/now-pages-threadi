<?php
/**
 * This file contains the preset for something the user does regularly.
 *
 * Not a service: a sentence of the user which is repeated. It is offered
 * beside the services because that is where a source is added, and
 * nobody would look for it under a provider name.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Presets\Preset_Base;
use ThreadiNowPages\Providers\Recurring_Provider;

/**
 * Object for the preset of a routine.
 */
class Routine extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'routine';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Something I do regularly', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'A sentence of your own which is repeated, for what no service knows about - like sports once a week.', 'threadi-now-pages' );
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'recurring';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'text' => array(
				'label'       => __( 'Text of the entry', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The sentence which is repeated. It appears once per period of your now page; how often and whether you are asked first can be changed in the source afterwards.', 'threadi-now-pages' ),
				'placeholder' => __( 'Went to the gym', 'threadi-now-pages' ),
				// these are the words of the user: whoever proposes them has to show them first.
				'review'      => true,
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
		$text = trim( ! empty( $setup['text'] ) ? $setup['text'] : '' );

		// bail if the answer is unusable.
		if ( '' === $text ) {
			return array();
		}

		return array(
			'recurring_text'     => $text,
			'recurring_rhythm'   => Recurring_Provider::RHYTHM_PERIOD,
			'recurring_weekdays' => array(),
			'recurring_confirm'  => 0,
		);
	}

	/**
	 * Return the icon of this preset.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="dashicons dashicons-update"></span>';
	}
}
