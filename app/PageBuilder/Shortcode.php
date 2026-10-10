<?php
/**
 * This file contains the [now_pages_threadi_digest] shortcode.
 *
 * Deliberately thin: all actual logic lives in Digest_Builder, so a future
 * Gutenberg block or page-builder widget can call the exact same method
 * without duplicating any of this.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Digests\Summarizer_Interface;
use NowPagesThreadi\Digests\Summarizers;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\NowPages\Frontend;

/**
 * Registers and handles the [now_pages_threadi_digest] shortcode.
 */
class Shortcode extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'shortcode';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'now_pages_threadi_digest', array( self::class, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Examples:
	 * [now_pages_threadi_digest period="day" format="narrative"]
	 * [now_pages_threadi_digest format="verbatim" show_time="true" category="3"]
	 * [now_pages_threadi_digest user="tz" delay_hours="24" show_source="true"]
	 * [now_pages_threadi_digest user="42" group_by_category="false"]
	 *
	 * Display options (show_time, show_source, group_by_category, delay_hours)
	 * follow the hierarchy: shortcode attribute > user meta > global setting > default.
	 * Omitting an attribute means “use the next lower level”.
	 *
	 * Privacy: delay_hours can only raise the configured delay, never lower it,
	 * and the digest of a user who disabled their Now Page stays empty. For a
	 * given user, show_time, show_source and link_source can only hide more
	 * than the settings of this user, and nothing is shown if the now pages of
	 * users are disabled.
	 *
	 * @param array<string,mixed>|string $attributes The shortcode attributes.
	 *
	 * @return string
	 */
	public static function render( array|string $attributes ): string {
		// convert string attributes to an array.
		if ( is_string( $attributes ) ) {
			$attributes = array( $attributes );
		}

		$attributes = shortcode_atts(
			array(
				'period'            => Digests::get_instance()->get_default_period(),
				'date'              => '',
				'format'            => Digests::get_instance()->get_default_format(),
				'category'          => 0,
				'language'          => '',
				'user'              => '',
				'show_time'         => '',
				'show_source'       => '',
				'link_source'       => '',
				'group_by_category' => '',
				'delay_hours'       => '',
				'summarizer'        => '',
				'hour'              => '',
				'lookback_hours'    => '',
				'start'             => '',
				'end'               => '',
			),
			$attributes,
			'now_pages_threadi_digest'
		);

		$args = array(
			'period'     => (string) $attributes['period'],
			'format'     => (string) $attributes['format'],
			'category'   => absint( $attributes['category'] ),
			'summarizer' => self::get_allowed_summarizer( (string) $attributes['summarizer'] ),
		);

		if ( '' !== $attributes['date'] ) {
			$args['date'] = (string) $attributes['date'];
		}

		if ( '' !== $attributes['language'] ) {
			$args['language'] = (string) $attributes['language'];
		}

		$user_id = 0;
		if ( '' !== $attributes['user'] ) {
			// bail if the now pages of users are not public: the shortcode must not bypass this.
			if ( ! Frontend::get_instance()->is_public( true ) ) {
				return '';
			}

			$user_id = Display_Settings::resolve_user_id( (string) $attributes['user'] );

			// bail if this user does not exist: show nothing instead of the entries of all users.
			if ( 0 === $user_id ) {
				return '';
			}

			// bail if this user disabled their Now Page: the shortcode must not bypass this.
			$keys = Display_Settings::user_meta_keys();
			if ( $user_id > 0 && 1 === absint( get_user_meta( $user_id, $keys['disabled'], true ) ) ) {
				return '';
			}

			$args['user'] = $attributes['user'];
		}

		// Display options: only pass when the attribute was explicitly set,
		// so the hierarchy (user meta → global → default) can still apply.
		// for a given user the options which reveal details may only be more
		// restrictive than the settings of this user.
		$configured = $user_id > 0 ? Display_Settings::resolve( array( 'user_id' => $user_id ) ) : array();
		foreach ( array( 'show_time', 'show_source', 'link_source' ) as $name ) {
			// bail if the attribute is not set.
			if ( '' === $attributes[ $name ] ) {
				continue;
			}

			$value = filter_var( $attributes[ $name ], FILTER_VALIDATE_BOOLEAN );

			// ignore an attribute which would show more than the user allows.
			if ( $value && isset( $configured[ $name ] ) && ! $configured[ $name ] ) {
				continue;
			}

			$args[ $name ] = $value;
		}

		if ( '' !== $attributes['group_by_category'] ) {
			$args['group_by_category'] = filter_var( $attributes['group_by_category'], FILTER_VALIDATE_BOOLEAN );
		}

		if ( '' !== $attributes['delay_hours'] ) {
			// the delay protects the privacy of the user (anti-stalking): the shortcode
			// may raise it, but never lower it below the configured value.
			$configured          = absint( Display_Settings::resolve( array( 'user_id' => $user_id ) )['delay_hours'] ?? 0 );
			$args['delay_hours'] = max( $configured, Display_Settings::limit_hours( ! empty( $attributes['delay_hours'] ) ? $attributes['delay_hours'] : 0 ) );
		}

		if ( '' !== $attributes['hour'] ) {
			$args['hour'] = absint( $attributes['hour'] );
		}
		if ( '' !== $attributes['lookback_hours'] ) {
			$args['lookback_hours'] = Display_Settings::limit_hours( ! empty( $attributes['lookback_hours'] ) ? $attributes['lookback_hours'] : 0 );
		}
		if ( '' !== $attributes['start'] ) {
			$args['start'] = (string) $attributes['start'];
		}
		if ( '' !== $attributes['end'] ) {
			$args['end'] = (string) $attributes['end'];
		}

		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Shortcode', 'now-pages-threadi' );
	}

	/**
	 * Return the given summarizer if a shortcode may choose it, an empty string
	 * (= the configured summarizer) otherwise.
	 *
	 * @param string $slug The slug from the shortcode attribute.
	 *
	 * @return string
	 */
	private static function get_allowed_summarizer( string $slug ): string {
		$slug = sanitize_key( $slug );

		// nothing chosen: use the configured one.
		if ( '' === $slug ) {
			return '';
		}

		$summarizers = Summarizers::get_instance();
		$summarizer  = $summarizers->get_summarizer( $slug );

		// unknown summarizer: use the configured one.
		if ( ! $summarizer instanceof Summarizer_Interface ) {
			return '';
		}

		// the summarizer the administrator chose is always fine.
		if ( $slug === $summarizers->get_default_summarizer_slug() ) {
			return $slug;
		}

		// any other one only if it stays on this server: no costs, no data leaves the website.
		return $summarizer->uses_external_service() ? '' : $slug;
	}
}
