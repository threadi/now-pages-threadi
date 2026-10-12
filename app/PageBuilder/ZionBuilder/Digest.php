<?php
/**
 * This file contains an element for Zion Builder.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\ZionBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Digests\Digest_Builder;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Digests\Display_Settings;
use ZionBuilder\Elements\Element;

/**
 * Object for the digest element in Zion Builder.
 */
class Digest extends Element {
	/**
	 * Return the name of this element.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Digest', 'threadi-now-pages' );
	}

	/**
	 * Return the unique type of this element.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'threadi_now_pages_digest';
	}

	/**
	 * Return the category this element belongs to.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'threadi-now-pages';
	}

	/**
	 * Return the keywords this element can be found with.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords(): array {
		return array( 'now', 'digest', 'entries' );
	}

	/**
	 * Register the options of this element.
	 *
	 * @param mixed $options The options object of Zion Builder.
	 *
	 * @return void
	 */
	public function options( $options ): void {
		// collect the periods.
		$periods = array();

		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[ $slug ] = array( 'name' => $label );
		}

		// collect the formats.
		$formats = array();

		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = array( 'name' => $format->get_label() );
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		$options->add_option(
			'period',
			array(
				'type'        => 'select',
				'default'     => Digests::get_instance()->get_default_period(),
				'title'       => esc_html__( 'Period', 'threadi-now-pages' ),
				'description' => esc_html__( 'Which period the shown entries are from.', 'threadi-now-pages' ),
				'options'     => $periods,
			)
		);

		$options->add_option(
			'format',
			array(
				'type'        => 'select',
				'default'     => Digests::get_instance()->get_default_format(),
				'title'       => esc_html__( 'Format', 'threadi-now-pages' ),
				'description' => esc_html__( 'How the entries are rendered.', 'threadi-now-pages' ),
				'options'     => $formats,
			)
		);

		$options->add_option(
			'override_display',
			array(
				'type'        => 'checkbox_switch',
				'default'     => false,
				'title'       => esc_html__( 'Use own display settings', 'threadi-now-pages' ),
				'description' => esc_html__( 'Without this the element follows the settings of your website.', 'threadi-now-pages' ),
			)
		);

		$switches = array(
			'show_time'         => esc_html__( 'Show times', 'threadi-now-pages' ),
			'show_source'       => esc_html__( 'Show data source', 'threadi-now-pages' ),
			'link_source'       => esc_html__( 'Link the data source', 'threadi-now-pages' ),
			'show_period'       => esc_html__( 'Show period of entries', 'threadi-now-pages' ),
			'group_by_category' => esc_html__( 'Group by category', 'threadi-now-pages' ),
		);

		foreach ( $switches as $name => $title ) {
			$options->add_option(
				$name,
				array(
					'type'       => 'checkbox_switch',
					'default'    => ! empty( $defaults[ $name ] ),
					'title'      => $title,
					'dependency' => array(
						array(
							'option' => 'override_display',
							'value'  => array( true ),
						),
					),
				)
			);
		}
	}

	/**
	 * Render this element in frontend and editor.
	 *
	 * @param mixed $options The options object of Zion Builder.
	 *
	 * @return void
	 */
	public function render( $options ): void {
		$args = array(
			'period' => (string) $options->get_value( 'period', 'live' ),
			'format' => (string) $options->get_value( 'format', 'verbatim' ),
		);

		// only pass the display settings when the element was told to
		// override them. otherwise every element would beat the settings of
		// the website.
		if ( ! empty( $options->get_value( 'override_display', false ) ) ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				$args[ $name ] = ! empty( $options->get_value( $name, false ) );
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled locally, all dynamic parts are escaped in the formats.
		echo ( new Digest_Builder() )->build( $args );
	}
}
