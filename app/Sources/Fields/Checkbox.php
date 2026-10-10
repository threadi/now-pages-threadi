<?php
/**
 * File to handle a checkbox field for provider configuration.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Sources\Field_Base;

/**
 * Object to handle a checkbox field for provider configuration.
 */
class Checkbox extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'checkbox';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}

	/**
	 * Return whether this field can hold more than one value.
	 *
	 * @return bool
	 */
	public function is_multiple(): bool {
		return false;
	}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return int
	 */
	public function sanitize( mixed $value ): int {
		if ( empty( $value ) ) {
			return 0;
		}

		return 1;
	}

	/**
	 * Return the HTML-code for this field.
	 *
	 * An unchecked checkbox sends nothing at all, so a hidden field with
	 * the same name carries the zero. The browser sends both, and the
	 * checkbox wins because it comes second.
	 *
	 * @return void
	 */
	public function get(): void {
		$checked = '';

		if ( 1 === absint( $this->get_value() ) ) {
			$checked = ' checked="checked"';
		}

		$html = '<input type="hidden" name="' . esc_attr( $this->get_name() ) . '" value="0">';

		$html .= '<input type="checkbox" id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '" value="1"' . $checked . '>';

		// show the field.
		$this->get_html_wrapper( $html );
	}
}
