<?php
/**
 * File to handle a secret field for provider configuration.
 *
 * The value is stored encrypted, see Sources::save_post(). It is never
 * rendered back into the form - an empty input means "keep the stored
 * value", so a saved secret cannot be read out of the markup.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources\Fields;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Sources\Field_Base;

/**
 * Object to handle a secret field for provider configuration.
 */
class Password extends Field_Base {
	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = 'password';

	/**
	 * Initialize this object.
	 */
	public function __construct() {}

	/**
	 * Return whether the value of this field is a secret.
	 *
	 * @return bool
	 */
	public function is_secret(): bool {
		return true;
	}

	/**
	 * Return the HTML-code for this field.
	 *
	 * @return void
	 */
	public function get(): void {
		// get the settings.
		$settings = $this->get_settings();

		// never echo the stored secret, only whether one exists.
		$has_value = '' !== trim( (string) $this->get_value() );

		// get placeholder from settings.
		$placeholder_settings = ! empty( $settings['placeholder'] ) ? (string) $settings['placeholder'] : '';

		// set the placeholder depending on value.
		$placeholder = $has_value
			? __( 'A value is stored. Leave empty to keep it.', 'threadi-now-pages' )
			: $placeholder_settings;

		// create the input field.
		$html = '<input type="password" id="' . esc_attr( $this->get_name() ) . '" name="' . esc_attr( $this->get_name() ) . '" value="" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '"' . ( $this->is_required() && ! $has_value ? ' required="required"' : '' ) . '>';

		// offer to remove a stored value of an optional field.
		if ( $has_value && ! $this->is_required() ) {
			$html .= '<br><label><input type="checkbox" name="' . esc_attr( $this->get_name() . '_clear' ) . '" value="1"> ' . esc_html__( 'Remove the stored value', 'threadi-now-pages' ) . '</label>';
		}

		// return the HTML-code.
		$this->get_html_wrapper( $html );
	}
}
