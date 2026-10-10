<?php
/**
 * This file contains the GitHub preset.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Presets\Preset_Base;

/**
 * Object for the GitHub preset.
 */
class GitHub extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'github';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'GitHub', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Your public activity: commits, pull requests, issues, releases and stars. Only a username is needed.', 'now-pages-threadi' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'code';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'github';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'github_username' => array(
				'label'       => __( 'Username', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your account name on GitHub.', 'now-pages-threadi' ),
				'placeholder' => 'octocat',
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
		$username = trim( ! empty( $setup['github_username'] ) ? $setup['github_username'] : '' );

		// bail if the answer is unusable.
		if ( '' === $username ) {
			return array();
		}

		return array(
			'github_username'  => $username,
			'github_max_items' => 10,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<svg width="24" height="24" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill="#000000"><path d="M10,0 C15.523,0 20,4.59 20,10.253 C20,14.782 17.138,18.624 13.167,19.981 C12.66,20.082 12.48,19.762 12.48,19.489 C12.48,19.151 12.492,18.047 12.492,16.675 C12.492,15.719 12.172,15.095 11.813,14.777 C14.04,14.523 16.38,13.656 16.38,9.718 C16.38,8.598 15.992,7.684 15.35,6.966 C15.454,6.707 15.797,5.664 15.252,4.252 C15.252,4.252 14.414,3.977 12.505,5.303 C11.706,5.076 10.85,4.962 10,4.958 C9.15,4.962 8.295,5.076 7.497,5.303 C5.586,3.977 4.746,4.252 4.746,4.252 C4.203,5.664 4.546,6.707 4.649,6.966 C4.01,7.684 3.619,8.598 3.619,9.718 C3.619,13.646 5.954,14.526 8.175,14.785 C7.889,15.041 7.63,15.493 7.54,16.156 C6.97,16.418 5.522,16.871 4.63,15.304 C4.63,15.304 4.101,14.319 3.097,14.247 C3.097,14.247 2.122,14.234 3.029,14.87 C3.029,14.87 3.684,15.185 4.139,16.37 C4.139,16.37 4.726,18.2 7.508,17.58 C7.513,18.437 7.522,19.245 7.522,19.489 C7.522,19.76 7.338,20.077 6.839,19.982 C2.865,18.627 0,14.783 0,10.253 C0,4.59 4.478,0 10,0" /></svg>';
	}
}
