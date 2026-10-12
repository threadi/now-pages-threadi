<?php
/**
 * This file contains the digest widget for Pagelayer.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Pagelayer;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Object for the digest widget in Pagelayer.
 */
class Digest {
	use Singleton_Trait;

	/**
	 * The tag of this widget in Pagelayer.
	 *
	 * Not the tag of our core shortcode: Pagelayer registers each of its tags
	 * as shortcode and would replace ours, together with all its attributes
	 * for users, categories and the delay.
	 */
	public const TAG = 'threadi_now_pages_pagelayer_digest';

	/**
	 * The display settings this widget can override.
	 *
	 * @var array<int,string>
	 */
	private const SWITCHES = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

	/**
	 * Constructor, not used as this is a singleton object.
	 */
	private function __construct() {}

	/**
	 * Register this widget in Pagelayer.
	 *
	 * @return void
	 */
	public function register(): void {
		// bail if the API of Pagelayer is not there.
		if ( ! function_exists( 'pagelayer_add_shortcode' ) ) {
			return;
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[ $slug ] = $label;
		}

		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = $format->get_label();
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		// define the display settings.
		$labels  = array(
			'show_time'         => __( 'Show times', 'threadi-now-pages' ),
			'show_source'       => __( 'Show data source', 'threadi-now-pages' ),
			'link_source'       => __( 'Link the data source', 'threadi-now-pages' ),
			'show_period'       => __( 'Show period of entries', 'threadi-now-pages' ),
			'group_by_category' => __( 'Group by category', 'threadi-now-pages' ),
		);
		$display = array(
			'override_display' => array(
				'type'  => 'checkbox',
				'label' => __( 'Use own display settings', 'threadi-now-pages' ),
				'desc'  => __( 'Without this the widget follows the settings of your website.', 'threadi-now-pages' ),
			),
		);
		foreach ( self::SWITCHES as $name ) {
			$display[ $name ] = array(
				'type'    => 'checkbox',
				'label'   => $labels[ $name ],
				'default' => ! empty( $defaults[ $name ] ) ? 'true' : '',
				'req'     => array( 'override_display' => 'true' ),
			);
		}

		pagelayer_add_shortcode(
			self::TAG,
			array(
				'name'     => __( 'Digest', 'threadi-now-pages' ),
				'group'    => __( 'Now Pages', 'threadi-now-pages' ),
				'icon'     => 'fas fa-stream',
				// Now Pages has its own block for the block editor.
				'no_gt'    => 1,
				'html'     => '<div class="threadi-now-pages-pagelayer-digest">{{{digest}}}</div>',
				'settings' => array(
					'params'  => __( 'Digest', 'threadi-now-pages' ),
					'display' => __( 'Display settings', 'threadi-now-pages' ),
				),
				'params'   => array(
					'period' => array(
						'type'    => 'select',
						'label'   => __( 'Period', 'threadi-now-pages' ),
						'desc'    => __( 'Which period the shown entries are from.', 'threadi-now-pages' ),
						'list'    => $periods,
						'default' => Digests::get_instance()->get_default_period(),
					),
					'format' => array(
						'type'    => 'select',
						'label'   => __( 'Format', 'threadi-now-pages' ),
						'desc'    => __( 'How the entries are rendered.', 'threadi-now-pages' ),
						'list'    => $formats,
						'default' => Digests::get_instance()->get_default_format(),
					),
				),
				'display'  => $display,
			)
		);
	}

	/**
	 * Add the output of our widget to the element Pagelayer is rendering.
	 *
	 * Pagelayer calls a function named after the tag of a widget, which would
	 * have to live in the global namespace. Instead, this filter runs for
	 * every element and fills our placeholder "{{{digest}}}".
	 *
	 * @param mixed $el The element of Pagelayer.
	 *
	 * @return mixed
	 */
	public function prepare( mixed $el ): mixed {
		// bail if this is not our widget.
		if ( ! is_array( $el ) || empty( $el['tag'] ) || self::TAG !== $el['tag'] ) {
			return $el;
		}

		// get the attributes.
		$attributes = ! empty( $el['atts'] ) && is_array( $el['atts'] ) ? $el['atts'] : array();

		// add the output.
		$el['tmp']['digest'] = $this->render( $attributes );

		// return the element.
		return $el;
	}

	/**
	 * Return the rendered digest for the given attributes.
	 *
	 * @param array<string,mixed> $attributes The attributes of the widget.
	 *
	 * @return string
	 */
	public function render( array $attributes ): string {
		$args = array(
			'period' => ! empty( $attributes['period'] ) ? (string) $attributes['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $attributes['format'] ) ? (string) $attributes['format'] : Digests::get_instance()->get_default_format(),
		);

		// only pass the display settings when the widget was told to override
		// them. otherwise every widget would beat the settings of the website.
		if ( ! empty( $attributes['override_display'] ) && 'true' === $attributes['override_display'] ) {
			foreach ( self::SWITCHES as $name ) {
				// bail if this setting has never been saved: the website decides.
				if ( ! isset( $attributes[ $name ] ) ) {
					continue;
				}

				$args[ $name ] = 'true' === $attributes[ $name ];
			}
		}

		// return the rendered output.
		return ( new Digest_Builder() )->build( $args );
	}

	/**
	 * Return the rendered digest for the editor of Pagelayer.
	 *
	 * @return void
	 */
	public function render_via_ajax(): void {
		// check the nonce of Pagelayer.
		check_ajax_referer( 'pagelayer_ajax', 'pagelayer_nonce' );

		// bail if capability is not enough.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		// get the attributes, only those this widget knows.
		$attributes = array();
		foreach ( array_merge( array( 'period', 'format', 'override_display' ), self::SWITCHES ) as $name ) {
			// bail if it is not set.
			if ( ! isset( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
				continue;
			}

			$attributes[ $name ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		}

		// return the output.
		wp_send_json_success( array( 'html' => $this->render( $attributes ) ) );
	}
}
