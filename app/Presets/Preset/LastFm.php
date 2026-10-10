<?php
/**
 * This file contains the Last.fm preset.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Presets\Preset_Base;

/**
 * Object for the Last.fm preset.
 */
class LastFm extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'lastfm';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Last.fm', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'What you are listening to. Needs a free API key, which you create once on last.fm.', 'now-pages-threadi' );
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
		return 'lastfm';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * The API key is deliberately not asked here: the answers travel
	 * through the URL, secrets belong on the edit screen.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'lastfm_username' => array(
				'label'       => __( 'Username', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your account on Last.fm. Its listening history has to be public.', 'now-pages-threadi' ),
				'placeholder' => 'rj',
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
		$username = trim( ! empty( $setup['lastfm_username'] ) ? $setup['lastfm_username'] : '' );

		// bail if the answer is unusable.
		if ( '' === $username ) {
			return array();
		}

		return array(
			'lastfm_username'  => $username,
			'lastfm_summarize' => 1,
			'lastfm_max_items' => 50,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<svg viewBox="0 0 94 94" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#000000"><path d="M89,0H5C2.238,0,0,2.239,0,5v84c0,2.761,2.238,5,5,5h84c2.762,0,5-2.239,5-5V5C94,2.239,91.762,0,89,0z M67.611,68.875 c-15.605,0-21.019-7.036-23.904-15.786l-2.887-9.021c-2.164-6.585-4.689-11.727-12.629-11.727c-5.504,0-11.094,3.969-11.094,15.064 c0,8.659,4.42,14.073,10.643,14.073c7.037,0,11.729-5.232,11.729-5.232l2.887,7.848c0,0-4.873,4.78-15.066,4.78 c-12.627,0-19.664-7.396-19.664-21.108c0-14.251,7.037-22.642,20.297-22.642c11.998,0,18.043,4.33,21.83,16.057l2.978,9.02 c2.164,6.585,5.953,11.367,15.063,11.367c6.136,0,9.379-1.354,9.379-4.691c0-2.616-1.53-4.51-6.131-5.593l-6.138-1.442 c-7.483-1.804-10.463-5.684-10.463-11.817c0-9.833,7.939-12.9,16.058-12.9c9.203,0,14.795,3.338,15.517,11.457l-9.021,1.082 c-0.361-3.879-2.705-5.502-7.035-5.502c-3.972,0-6.403,1.804-6.403,4.871c0,2.706,1.17,4.33,5.141,5.232l5.773,1.263 c7.758,1.804,11.905,5.593,11.905,12.899C86.375,65.448,78.799,68.875,67.611,68.875z"/></svg>';
	}
}
