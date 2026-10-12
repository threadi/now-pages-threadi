<?php
/**
 * File to handle a multi select field for provider configuration.
 *
 * The options can either be given as a fixed list in the field settings,
 * or be built at render time by a callback - which is what a provider
 * needs when the choices depend on the website, like its post types.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Sources\Field_Base;

/**
 * Object to handle a multi select field for provider configuration.
 */
class MultiSelect extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'multiselect';

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
		return true;
	}

	/**
	 * Sanitize the value of this field before it is stored.
	 *
	 * Only values that actually exist as an option are kept, so nothing a
	 * client invented ends up in the configuration.
	 *
	 * @param mixed $value The value from the request.
	 *
	 * @return array<int,string>
	 */
	public function sanitize( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = $this->get_options();
		$result  = array();

		foreach ( $value as $single ) {
			$single = sanitize_text_field( (string) $single );

			// skip everything that is not an offered option.
			if ( ! isset( $options[ $single ] ) ) {
				continue;
			}

			$result[] = $single;
		}

		return $result;
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
			$this->get_html_wrapper( '<em>' . esc_html__( 'No options available.', 'threadi-now-pages' ) . '</em>' );

			return;
		}

		$selected = $this->get_value();

		if ( ! is_array( $selected ) ) {
			$selected = array();
		}

		$size = count( $options );

		if ( $size > 8 ) {
			$size = 8;
		}

		// an empty selection sends nothing, so carry it in a hidden field.
		$html = '<input type="hidden" name="' . esc_attr( $this->get_name() ) . '[]" value="">';

		$html .= '<select id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '[]" multiple="multiple" size="' . absint( $size ) . '">';

		foreach ( $options as $value => $label ) {
			$is_selected = '';

			if ( in_array( (string) $value, $selected, true ) ) {
				$is_selected = ' selected="selected"';
			}

			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . $is_selected . '>' . esc_html( (string) $label ) . '</option>';
		}

		$html .= '</select>';

		// show the field.
		$this->get_html_wrapper( $html );
	}
}
