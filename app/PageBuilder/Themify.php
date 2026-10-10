<?php
/**
 * This file contains the support for Themify.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;
use Themify_Builder_Model;

/**
 * Registers and handles the support for Themify.
 */
class Themify extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'themify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Themify', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Themify is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'themify_builder_setup_modules', array( $this, 'register_modules' ) );
		add_action( 'wp_footer', array( $this, 'print_builder_data' ) );
		add_action( 'admin_footer', array( $this, 'print_builder_data' ) );
	}

	/**
	 * Return whether this page builder is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( Themify_Builder_Model::class );
	}

	/**
	 * Register our module for Themify.
	 *
	 * @return void
	 */
	public function register_modules(): void {
		Themify_Builder_Model::add_module( Plugin::get_plugin_path() . 'legacy-classes/Themify/modules/module-digest.php' );
	}

	/**
	 * Print the lists the module builds its fields from.
	 *
	 * Without this the periods, formats and labels would have to be
	 * duplicated inside the JS - untranslatable, and out of sync with PHP
	 * as soon as somebody registers a period of their own.
	 *
	 * @return void
	 */
	public static function print_builder_data(): void {
		// bail if the builder is not being used right now.
		if ( ! self::is_builder_active() ) {
			return;
		}

		$data = array(
			'periods' => Digests::get_instance()->get_periods(),
			'formats' => self::get_formats(),
			'default' => array(
				'period' => Digests::get_instance()->get_default_period(),
				'format' => Digests::get_instance()->get_default_format(),
			),
			'labels'  => array(
				'module'          => __( 'Digest', 'now-pages-threadi' ),
				'period'          => __( 'Period', 'now-pages-threadi' ),
				'format'          => __( 'Format', 'now-pages-threadi' ),
				'yes'             => __( 'Yes', 'now-pages-threadi' ),
				'overrideDisplay' => __( 'Use own display settings', 'now-pages-threadi' ),
				'showTime'        => __( 'Show times', 'now-pages-threadi' ),
				'showSource'      => __( 'Show data source', 'now-pages-threadi' ),
				'linkSource'      => __( 'Link the data source', 'now-pages-threadi' ),
				'showPeriod'      => __( 'Show period of entries', 'now-pages-threadi' ),
				'groupByCategory' => __( 'Group by category', 'now-pages-threadi' ),
			),
		);

		?>
		<script id="now-pages-threadi-themify-data">
			window.nowPagesThreadiThemify = <?php echo wp_json_encode( $data ); ?>;
		</script>
		<?php
	}

	/**
	 * Return the formats a digest can be rendered in.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	private static function get_formats(): array {
		$formats = array();

		foreach ( Digests::get_instance()->get_formats() as $format ) {
			$formats[ $format->get_slug() ] = $format->get_label();
		}

		return $formats;
	}

	/**
	 * Return whether the Themify builder is active on this request.
	 *
	 * @return bool
	 */
	private static function is_builder_active(): bool {
		// in backend the builder panel can be opened on any edit screen.
		if ( is_admin() ) {
			return true;
		}

		if ( ! method_exists( 'Themify_Builder_Model', 'is_front_builder_activate' ) ) { // @phpstan-ignore function.impossibleType
			return true;
		}

		return Themify_Builder_Model::is_front_builder_activate();
	}
}
