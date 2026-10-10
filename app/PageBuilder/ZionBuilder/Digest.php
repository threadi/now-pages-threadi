<?php
/**
 * This file contains an element for Zion Builder.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\ZionBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
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
		return __( 'Digest', 'now-pages-threadi' );
	}

	/**
	 * Return the unique type of this element.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'now_pages_threadi_digest';
	}

	/**
	 * Return the category this element belongs to.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'now-pages-threadi';
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
				'title'       => esc_html__( 'Period', 'now-pages-threadi' ),
				'description' => esc_html__( 'Which period the shown entries are from.', 'now-pages-threadi' ),
				'options'     => $periods,
			)
		);

		$options->add_option(
			'format',
			array(
				'type'        => 'select',
				'default'     => Digests::get_instance()->get_default_format(),
				'title'       => esc_html__( 'Format', 'now-pages-threadi' ),
				'description' => esc_html__( 'How the entries are rendered.', 'now-pages-threadi' ),
				'options'     => $formats,
			)
		);

		$options->add_option(
			'override_display',
			array(
				'type'        => 'checkbox_switch',
				'default'     => false,
				'title'       => esc_html__( 'Use own display settings', 'now-pages-threadi' ),
				'description' => esc_html__( 'Without this the element follows the settings of your website.', 'now-pages-threadi' ),
			)
		);

		$switches = array(
			'show_time'         => esc_html__( 'Show times', 'now-pages-threadi' ),
			'show_source'       => esc_html__( 'Show data source', 'now-pages-threadi' ),
			'link_source'       => esc_html__( 'Link the data source', 'now-pages-threadi' ),
			'show_period'       => esc_html__( 'Show period of entries', 'now-pages-threadi' ),
			'group_by_category' => esc_html__( 'Group by category', 'now-pages-threadi' ),
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
