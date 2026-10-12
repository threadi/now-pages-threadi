<?php
/**
 * This file contains the Steam preset.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Presets\Preset_Base;

/**
 * Object for the Steam preset.
 */
class Steam extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'steam';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Steam', 'threadi-now-pages' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'What you play. Needs an API key and a public profile - Steam stays silent otherwise.', 'threadi-now-pages' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'media';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'steam';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'steam_id' => array(
				'label'       => __( 'SteamID64', 'threadi-now-pages' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The 17 digit ID of your account, not the display name. You can look it up at steamid.io. Your profile and its game details have to be set to public.', 'threadi-now-pages' ),
				'placeholder' => '76561197960287930',
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
		$steam_id = trim( ! empty( $setup['steam_id'] ) ? $setup['steam_id'] : '' );

		// bail if the answer is unusable.
		if ( '' === $steam_id ) {
			return array();
		}

		return array(
			'steam_id'          => $steam_id,
			'steam_min_minutes' => 15,
			'steam_max_items'   => 5,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<svg width="2471" height="2500" viewBox="0 0 256 259" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid"><path d="M127.779 0C60.42 0 5.24 52.412 0 119.014l68.724 28.674a35.812 35.812 0 0 1 20.426-6.366c.682 0 1.356.019 2.02.056l30.566-44.71v-.626c0-26.903 21.69-48.796 48.353-48.796 26.662 0 48.352 21.893 48.352 48.796 0 26.902-21.69 48.804-48.352 48.804-.37 0-.73-.009-1.098-.018l-43.593 31.377c.028.582.046 1.163.046 1.735 0 20.204-16.283 36.636-36.294 36.636-17.566 0-32.263-12.658-35.584-29.412L4.41 164.654c15.223 54.313 64.673 94.132 123.369 94.132 70.818 0 128.221-57.938 128.221-129.393C256 57.93 198.597 0 127.779 0zM80.352 196.332l-15.749-6.568c2.787 5.867 7.621 10.775 14.033 13.47 13.857 5.83 29.836-.803 35.612-14.799a27.555 27.555 0 0 0 .046-21.035c-2.768-6.79-7.999-12.086-14.706-14.909-6.67-2.795-13.811-2.694-20.085-.304l16.275 6.79c10.222 4.3 15.056 16.145 10.794 26.46-4.253 10.314-15.998 15.195-26.22 10.895zm121.957-100.29c0-17.925-14.457-32.52-32.217-32.52-17.769 0-32.226 14.595-32.226 32.52 0 17.926 14.457 32.512 32.226 32.512 17.76 0 32.217-14.586 32.217-32.512zm-56.37-.055c0-13.488 10.84-24.42 24.2-24.42 13.368 0 24.208 10.932 24.208 24.42 0 13.488-10.84 24.421-24.209 24.421-13.359 0-24.2-10.933-24.2-24.42z" fill="#1A1918"/></svg>';
	}
}
