<?php
/**
 * File to handle a select field for provider configuration.
 *
 * The options can either be given as a fixed list in the field settings,
 * or be built at render time by a callback - which is what a provider
 * needs when the choices depend on the website, like its users.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Sources\Field_Base;

/**
 * Object to handle a select field for provider configuration.
 */
class Select extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'select';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * Only a value that actually exists as an option is kept, so nothing a
	 * client invented ends up in the configuration.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value ): string {
		$value   = sanitize_text_field( (string) $value );
		$options = $this->get_options();

		// bail if this is not one of the offered options.
		if ( ! isset( $options[ $value ] ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Return the options of this field.
	 *
	 * @return array<string,string> Map of value => label.
	 */
	public function get_options(): array {
		$settings = $this->get_settings();

		// the options can be built at render time.
		if ( isset( $settings['options_callback'] ) && is_callable( $settings['options_callback'] ) ) {
			$options = call_user_func( $settings['options_callback'] );

			if ( is_array( $options ) ) {
				return $options;
			}

			return array();
		}

		if ( isset( $settings['options'] ) && is_array( $settings['options'] ) ) {
			return $settings['options'];
		}

		return array();
	}

	/**
	 * Return the HTML-code for this field.
	 *
	 * @return void
	 */
	public function get(): void {
		$options = $this->get_options();

		// show a hint instead of an empty box.
		if ( empty( $options ) ) {
			$this->get_html_wrapper( '<em>' . esc_html__( 'No options available.', 'now-pages-threadi' ) . '</em>' );

			return;
		}

		$current  = (string) $this->get_value();
		$required = '';

		if ( $this->is_required() ) {
			$required = ' required="required"';
		}

		$html = '<select id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '"' . $required . '>';

		foreach ( $options as $value => $label ) {
			$selected = '';

			if ( (string) $value === $current ) {
				$selected = ' selected="selected"';
			}

			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . $selected . '>' . esc_html( (string) $label ) . '</option>';
		}

		$html .= '</select>';

		// show the field.
		$this->get_html_wrapper( $html );
	}
}
