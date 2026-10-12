<?php
/**
 * File to handle a single field for provider configuration.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Sources\Field_Base;

/**
 * Object to handle a single field for provider configuration.
 */
class Url extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'url';

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
		// return the HTML-code.
		$this->get_html_wrapper( '<input type="url" id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '" value="' . esc_attr( $this->get_value() ) . '" placeholder="' . esc_attr( $this->get_placeholder() ) . '"' . ( $this->is_required() ? ' required="required"' : '' ) . '>' );
	}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value ): string {
		return esc_url_raw( (string) $value );
	}
}
