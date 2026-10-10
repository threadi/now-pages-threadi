<?php
/**
 * File for a classic widget to show now page entries.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\WpWidgets;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Display_Settings;
use WP_Widget;

/**
 * Object to provide a classic widget to show now page entries.
 */
class Digest extends WP_Widget {

	/**
	 * Initialize this widget.
	 */
	public function __construct() {
		parent::__construct(
			'nowPagesThreadiDigest',
			__( 'Digest', 'now-pages-threadi' ),
			array(
				'description' => __( 'Provides a widget to show the entries of your now page.', 'now-pages-threadi' ),
			)
		);
	}

	/**
	 * Get the fields for this widget.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function getFields(): array {
		// collect the formats.
		$formats = array();
		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = $format->get_label();
		}

		// collect the periods.
		$periods = array();
		foreach ( Digests::get_instance()->get_periods() as $slug => $label ) {
			$periods[ $slug ] = $label;
		}

		// hand the current defaults over, so switching the override on does
		// not change the output until the user actually changes something.
		$defaults = Display_Settings::resolve( array() );

		unset( $defaults['_origin'] );

		// return the possible configuration for this widget.
		return array(
			'period'            => array(
				'type'   => 'select',
				'title'  => __( 'Period', 'now-pages-threadi' ),
				'std'    => Digests::get_instance()->get_default_period(),
				'values' => $periods,
			),
			'format'            => array(
				'type'   => 'select',
				'title'  => __( 'Format', 'now-pages-threadi' ),
				'std'    => Digests::get_instance()->get_default_format(),
				'values' => $formats,
			),
			'override_display'  => array(
				'type'   => 'select',
				'title'  => __( 'Use own display settings', 'now-pages-threadi' ),
				'std'    => 'no',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
			'show_time'         => array(
				'type'   => 'select',
				'title'  => __( 'Show times', 'now-pages-threadi' ),
				'std'    => 'no',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
			'show_source'       => array(
				'type'   => 'select',
				'title'  => __( 'Show data source', 'now-pages-threadi' ),
				'std'    => 'no',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
			'link_source'       => array(
				'type'   => 'select',
				'title'  => __( 'Link the data source', 'now-pages-threadi' ),
				'std'    => 'no',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
			'show_period'       => array(
				'type'   => 'select',
				'title'  => __( 'Show period of entries', 'now-pages-threadi' ),
				'std'    => 'yes',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
			'group_by_category' => array(
				'type'   => 'select',
				'title'  => __( 'Group by category', 'now-pages-threadi' ),
				'std'    => 'yes',
				'values' => array(
					'yes' => esc_html__( 'Show', 'now-pages-threadi' ),
					'no'  => esc_html__( 'Hide', 'now-pages-threadi' ),
				),
			),
		);
	}

	/**
	 * Add entry-formular with settings for the widget.
	 *
	 * @param array<string,mixed> $instance The instance of the widget.
	 *
	 * @return string Empty string, so WordPress shows the save button.
	 * @noinspection PhpMissingReturnTypeInspection*/
	public function form( $instance ) {
		$this->create_widget_field_output( $this->getFields(), $instance );

		return '';
	}

	/**
	 * Save updated settings from the formular.
	 *
	 * @param array<string,mixed> $new_instance The new instance.
	 * @param array<string,mixed> $old_instance The old instance.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		return $this->secure_widget_fields( $this->getFields(), $new_instance, $old_instance );
	}

	/**
	 * Output of the widget in the frontend.
	 *
	 * @param array<string,mixed> $args List of arguments.
	 * @param array<string,mixed> $settings List of settings.
	 *
	 * @return void
	 * @noinspection PhpParameterNameChangedDuringInheritanceInspection
	 * @noinspection DuplicatedCode
	 * @noinspection PhpMissingReturnTypeInspection
	 */
	public function widget( $args, $settings ) {
		$attributes = array(
			'period' => ! empty( $settings['period'] ) ? $settings['period'] : Digests::get_instance()->get_default_period(),
			'format' => ! empty( $settings['format'] ) ? $settings['format'] : Digests::get_instance()->get_default_format(),
		);

		// only pass the display settings when the block was told to override
		// them. otherwise every block would beat the settings of the website.
		if ( 'yes' === $settings['override_display'] ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				if ( ! isset( $settings[ $name ] ) ) {
					continue;
				}

				$attributes[ $name ] = 'yes' === $settings[ $name ];
			}
		}

		// add wrapper from template around widget-content.
		echo wp_kses_post( $args['before_widget'] );

		// outside the route the block behaves like the shortcode.
		echo wp_kses( ( new Digest_Builder() )->build( $attributes ), Digests::get_instance()->get_allowed_html() );

		// add wrapper from template around widget-content.
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Create output for Widget-fields.
	 *
	 * @param array<string,array<string,mixed>> $fields List of fields in this widget.
	 * @param array<string,mixed>               $instance Current settings.
	 * @return void
	 */
	protected function create_widget_field_output( array $fields, array $instance ): void {
		foreach ( $fields as $name => $field ) {
			switch ( $field['type'] ) {
				case 'select':
					// get actual value.
					$selected_value = array( ! empty( $instance[ $name ] ) ? $instance[ $name ] : $field['std'] );

					// multiselect.
					$multiple = '';
					if ( isset( $field['multiple'] ) && false !== $field['multiple'] ) {
						$multiple = ' multiple="multiple"';
						if ( ! empty( $instance[ $name ] ) && is_array( $instance[ $name ] ) ) {
							$selected_value = array();
							foreach ( $field['values'] as $n => $v ) {
								if ( false !== in_array( $n, $instance[ $name ], true ) ) {
									$selected_value[] = $n;
								}
							}
						}
					}

					// define field-name.
					$name = $this->get_field_name( $name );
					if ( isset( $field['multiple'] ) && false !== $field['multiple'] ) {
						$name .= '[]';
					}

					// output.
					?>
					<p>
						<label for="<?php echo esc_attr( $this->get_field_name( $name ) ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
						<select class="widefat" id="<?php echo esc_attr( $this->get_field_name( $name ) ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo esc_attr( $multiple ); ?>>
							<?php
							foreach ( $field['values'] as $value => $title ) {
								?>
								<option value="<?php echo esc_attr( $value ); ?>"<?php echo ( in_array( $value, $selected_value, true ) ? ' selected="selected"' : '' ); ?>><?php echo esc_html( $title ); ?></option>
								<?php
							}
							?>
						</select>
					</p>
					<?php
					break;
			}
		}
	}

	/**
	 * Secure the widget-fields.
	 *
	 * @param array<string,mixed> $fields List of fields.
	 * @param array<string,mixed> $new_instance The new instance.
	 * @param array<string,mixed> $instance The old instance.
	 * @return array<string,mixed>
	 */
	protected function secure_widget_fields( array $fields, array $new_instance, array $instance ): array {
		foreach ( $fields as $name => $field ) {
			switch ( $field['type'] ) {
				case 'select':
					if ( ! empty( $field['multiple'] ) ) {
						$values = array();
						if ( ! empty( $new_instance[ $name ] ) ) {
							foreach ( $new_instance[ $name ] as $v ) {
								$values[] = sanitize_text_field( $v );
							}
						}
						$instance[ $name ] = $values;
					} else {
						$instance[ $name ] = sanitize_text_field( $new_instance[ $name ] );
					}
					break;
			}
		}
		return $instance;
	}
}
