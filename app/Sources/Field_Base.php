<?php
/**
 * File to handle basic functions for each configuration field for providers.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object to handle basic functions for each configuration field for providers.
 */
abstract class Field_Base {

	/**
	 * The fields slug.
	 *
	 * @var string
	 */
	protected string $slug = '';

	/**
	 * The field name.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The label.
	 *
	 * @var string
	 */
	private string $label = '';

	/**
	 * The description.
	 *
	 * @var string
	 */
	private string $description = '';

	/**
	 * Required.
	 *
	 * @var bool
	 */
	private bool $required = false;

	/**
	 * The value.
	 *
	 * @var mixed|null
	 */
	private mixed $value = null;

	/**
	 * The placeholder.
	 *
	 * @var string
	 */
	private string $placeholder = '';

	/**
	 * Settings for this field.
	 *
	 * @var array<string,mixed>
	 */
	protected array $settings = array();

	/**
	 * Return the label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Set the label.
	 *
	 * @param string $label The label.
	 *
	 * @return void
	 */
	public function set_label( string $label ): void {
		$this->label = $label;
	}

	/**
	 * Return the fields slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Return the HTML-code for this field.
	 *
	 * @return void
	 */
	abstract public function get(): void;

	/**
	 * Return the wrapper HTML-code for this field.
	 *
	 * @param string $html The HTML-code of the single field.
	 *
	 * @return void
	 */
	protected function get_html_wrapper( string $html ): void {
		?>
		<fieldset>
			<label for="<?php echo esc_attr( $this->get_name() ); ?>"><?php echo esc_html( $this->get_label() ); ?></label>
			<?php echo wp_kses_post( $html ); ?>
			<?php
			if ( ! empty( $this->get_description() ) ) {
				echo '<p>' . wp_kses_post( $this->get_description() ) . '</p>';
			}
			?>
		</fieldset>
		<?php
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return $this->description;
	}

	/**
	 * Set the description.
	 *
	 * @param string $description The description.
	 *
	 * @return void
	 */
	public function set_description( string $description ): void {
		$this->description = $description;
	}

	/**
	 * Return whether this field is required.
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * Set field as required.
	 *
	 * @param bool $required True if it is required.
	 *
	 * @return void
	 */
	public function set_required( bool $required ): void {
		$this->required = $required;
	}

	/**
	 * Return the value.
	 *
	 * @return mixed|null
	 */
	public function get_value(): mixed {
		return $this->value;
	}

	/**
	 * Set the value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	public function set_value( mixed $value ): void {
		$this->value = $value;
	}

	/**
	 * Return the settings for this field.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings(): array {
		return $this->settings;
	}

	/**
	 * Set the settings for this field.
	 *
	 * @param array<string,mixed> $settings Settings for this field.
	 *
	 * @return void
	 */
	public function set_settings( array $settings ): void {
		$this->settings = $settings;
	}

	/**
	 * Return the field name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Set the name for this field.
	 *
	 * @param string $name The name.
	 *
	 * @return void
	 */
	public function set_name( string $name ): void {
		$this->name = $name;
	}

	/**
	 * Return the placeholder.
	 *
	 * @return string
	 */
	protected function get_placeholder(): string {
		return $this->placeholder;
	}

	/**
	 * Set the placeholder.
	 *
	 * @param string $placeholder The placeholder.
	 *
	 * @return void
	 */
	public function set_placeholder( string $placeholder ): void {
		$this->placeholder = $placeholder;
	}

	/**
	 * Return whether the value of this field is a secret.
	 *
	 * @return bool
	 */
	public function is_secret(): bool {
		return false;
	}

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
	 * @return mixed
	 */
	public function sanitize( mixed $value ): mixed {
		return sanitize_text_field( (string) $value );
	}
}
