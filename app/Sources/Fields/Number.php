<?php
/**
 * File to handle a single field for provider configuration.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Sources\Field_Base;

/**
 * Object to handle a single field for provider configuration.
 */
class Number extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'number';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}

	/**
	 * Return the HTML-code for this field.
	 *
	 * @return void
	 */
	public function get(): void {
		// get the settings.
		$settings = $this->get_settings();

		// get min, max and step.
		$min  = ! empty( $settings['min'] ) ? (int) $settings['min'] : 0;
		$max  = ! empty( $settings['max'] ) ? (int) $settings['max'] : 100;
		$step = ! empty( $settings['step'] ) ? (int) $settings['step'] : 1;

		// create the HTML-code.
		$html = '<input type="number" id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '" value="' . (int) $this->get_value() . '" min="' . (int) $min . '" max="' . (int) $max . '" step="' . (int) $step . '"' . ( $this->is_required() ? ' required="required"' : '' ) . '>';

		// show the field.
		$this->get_html_wrapper( $html );
	}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return int
	 */
	public function sanitize( mixed $value ): int {
		// get the settings.
		$settings = $this->get_settings();

		// use the same limits as the field in the form: they only exist in the
		// browser otherwise and could be bypassed with a crafted request.
		$min = ! empty( $settings['min'] ) ? (int) $settings['min'] : 0;
		$max = ! empty( $settings['max'] ) ? (int) $settings['max'] : 100;

		return max( $min, min( $max, absint( $value ) ) );
	}
}
