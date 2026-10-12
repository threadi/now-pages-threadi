<?php
/**
 * This file contains the support for BoldBuilder.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use bb_threadi_now_pages_digest;
use ThreadiNowPages\Digests\Digests;
use ThreadiNowPages\Helper\Plugin;
use ThreadiNowPages\Helper\Singleton_Trait;

/**
 * Registers and handles the support for BoldBuilder.
 */
class BoldBuilder extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'boldbuilder';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if BoldBuilder is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'init', array( $this, 'register_element' ) );
	}

	/**
	 * Check if BoldBuilder is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'bold-page-builder/bold-builder.php' );
	}

	/**
	 * Register our Bold Builder element.
	 *
	 * Hint:
	 * Since BoldBuilder, for reasons that are utterly baffling, runs the `bt_bb_content_elements` filter
	 * at the top level before other WordPress plugins (all those starting with the letter “b”) have been loaded,
	 * we cannot use that filter. Therefore, we have to integrate our own element in this way.
	 *
	 * @return void
	 */
	public function register_element(): void {
		// embed the file with the element.
		$file = Plugin::get_plugin_path() . 'legacy-classes/BoldBuilder/bb_threadi_now_pages_digest/bb_threadi_now_pages_digest.php';

		// bail if class is already registered.
		if ( class_exists( 'bb_threadi_now_pages_digest' ) ) {
			return;
		}

		// bail if the base class of Bold Builder is not available.
		if ( ! class_exists( 'BT_BB_Element' ) ) {
			return;
		}

		// embed the file.
		require_once $file;

		// register its object.
		new bb_threadi_now_pages_digest();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Bold Page Builder', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Bricks keeps its elements in post meta, post_content stays empty.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '[bt_bb_section layout="boxed_1200" lazy_load="yes" show_video_on_mobile="" bb_version="5.9.10"][bt_bb_row][bt_bb_column order="0" lazy_load="yes" bb_version="5.9.10" width="1/1" width_xl="1/1" width_lg="1/1" width_md="1/1" width_sm="1/1" width_xs="1/1"][bb_threadi_now_pages_digest bb_version="5.9.10" period="' . Digests::get_instance()->get_default_period() . '" format="' . Digests::get_instance()->get_default_format() . '" responsive="" publish_datetime="" expiry_datetime="" animation="no_animation,;,,;,,;,,;,,;,,;," el_id="" el_class="" el_style="" override_display="yes" show_time="" show_source="" link_source="" show_period="" group_by_category=""][/bb_threadi_now_pages_digest][/bt_bb_column][/bt_bb_row][/bt_bb_section]';
	}
}
