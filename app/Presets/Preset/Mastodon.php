<?php
/**
 * This file contains the Mastodon preset.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Presets\Preset;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Presets\Preset_Base;

/**
 * Object for the Mastodon preset.
 */
class Mastodon extends Preset_Base {
	/**
	 * Return the preset slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'mastodon';
	}

	/**
	 * Return the preset label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Mastodon', 'now-pages-threadi' );
	}

	/**
	 * Return a short description shown on the card.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Your public posts. Every Mastodon account has a feed, no account connection needed.', 'now-pages-threadi' );
	}

	/**
	 * Return the group this preset belongs to.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return 'social';
	}

	/**
	 * Return the slug of the provider this preset uses.
	 *
	 * @return string
	 */
	public function get_provider_slug(): string {
		return 'rss';
	}

	/**
	 * Return the questions asked before the provider can be configured.
	 *
	 * @return array<string,mixed>
	 */
	public function get_setup_fields(): array {
		return array(
			'mastodon_instance' => array(
				'label'       => __( 'Instance', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'The server your account lives on, without https://', 'now-pages-threadi' ),
				'placeholder' => 'mastodon.social',
			),
			'mastodon_handle'   => array(
				'label'       => __( 'Username', 'now-pages-threadi' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Without the leading @ and without the server.', 'now-pages-threadi' ),
				'placeholder' => 'tz',
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
		$instance = trim( ! empty( $setup['mastodon_instance'] ) ? $setup['mastodon_instance'] : '' );
		$handle   = trim( ! empty( $setup['mastodon_handle'] ) ? $setup['mastodon_handle'] : '' );

		// people paste the whole address, cut off what we add ourselves.
		$instance = (string) preg_replace( '#^https?://#i', '', $instance );
		$instance = trim( $instance, '/' );
		$handle   = ltrim( $handle, '@' );

		// bail if the answers are unusable.
		if ( '' === $instance || '' === $handle ) {
			return array();
		}

		// bail if the instance is not a plain host name, optionally with a port: no path, query or credentials.
		if ( 1 !== preg_match( '/^[\p{L}\p{N}.\-]+(?::\d{1,5})?$/u', $instance ) ) {
			return array();
		}

		return array(
			'feed_url'  => 'https://' . $instance . '/@' . rawurlencode( $handle ) . '.rss',
			'max_items' => 10,
		);
	}

	/**
	 * Return the icon of this preset as inline SVG.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" shape-rendering="geometricPrecision" text-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 512 539.675"><path fill-rule="nonzero" d="M509.934 119.48C502.047 61.492 450.957 15.797 390.393 6.943 380.174 5.444 341.461 0 251.779 0h-.669c-89.706 0-108.95 5.444-119.169 6.943C73.064 15.552 19.296 56.613 6.25 115.283c-6.274 28.896-6.943 60.93-5.779 90.315 1.663 42.141 1.984 84.206 5.854 126.175a587.526 587.526 0 0013.962 82.76c12.4 50.284 62.598 92.13 111.779 109.203a302.928 302.928 0 00163.538 8.535 244.938 244.938 0 0017.707-4.782c13.169-4.148 28.621-8.782 39.981-16.927.154-.113.282-.262.374-.432.089-.17.138-.358.146-.549v-40.666a1.197 1.197 0 00-.465-.929 1.242 1.242 0 00-1.023-.227 457.85 457.85 0 01-106.125 12.264c-61.505 0-78.048-28.87-82.784-40.888a125.62 125.62 0 01-7.194-32.23c-.01-.186.026-.369.102-.539a1.165 1.165 0 01.834-.674c.181-.04.371-.034.552.01a449.063 449.063 0 00104.389 12.264c8.457 0 16.888 0 25.347-.219 35.366-.981 72.64-2.772 107.436-9.493.868-.173 1.736-.319 2.482-.538 54.884-10.427 107.113-43.149 112.422-126.005.199-3.264.696-34.168.696-37.555.023-11.503 3.744-81.604-.547-124.676zm-84.473 206.752H367.75V186.417c0-29.435-12.4-44.445-37.625-44.445-27.727 0-41.615 17.758-41.615 52.834v76.528h-57.363v-76.528c0-35.076-13.915-52.834-41.642-52.834-25.075 0-37.599 15.01-37.625 44.445v139.815H94.219V182.173c0-29.432 7.597-52.818 22.792-70.151 15.675-17.293 36.234-26.171 61.754-26.171 29.539 0 51.859 11.234 66.741 33.676l14.359 23.844 14.385-23.844c14.88-22.442 37.201-33.676 66.691-33.676 25.494 0 46.053 8.878 61.777 26.171 15.196 17.317 22.793 40.7 22.793 70.151l-.05 144.059z"/></svg>';
	}
}
