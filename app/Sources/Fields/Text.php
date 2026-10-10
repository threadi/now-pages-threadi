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
class Text extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'text';

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
		$this->get_html_wrapper( '<input type="text" id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '" value="' . esc_attr( $this->get_value() ) . '"' . ( $this->is_required() ? ' required="required"' : '' ) . ' placeholder="' . $this->get_placeholder() . '">' );
	}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * A field with the setting "sanitize" => "key" holds a slug, which is
	 * compared with sanitize_key() elsewhere, e.g. the slug of a webhook source.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		// bail if this field holds no slug.
		if ( 'key' !== ( $this->get_settings()['sanitize'] ?? '' ) ) {
			return $value;
		}

		return sanitize_key( $value );
	}
}
