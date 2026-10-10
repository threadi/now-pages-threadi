<?php
/**
 * This file contains the support for Beaver Builder.
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
use stdClass;

/**
 * Registers and handles the support for Beaver Builder.
 */
class Beaver extends PageBuilder_Base {
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'beaver';
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// bail if Beaver Builder is not active.
		if ( ! $this->is_active() ) {
			return;
		}

		// use hooks.
		add_action( 'init', array( $this, 'init_widgets' ) );
	}

	/**
	 * Initialize the Beaver Widgets.
	 *
	 * @return void
	 */
	public function init_widgets(): void {
		require_once Plugin::get_plugin_path() . 'app/PageBuilder/Beaver/Digest.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_content(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_page_meta(): array {
		$layout = $this->get_layout();

		return array(
			'_fl_builder_enabled' => 1,
			'_fl_builder_data'    => $layout,
			// the draft is what the editor opens, it has to match the data.
			'_fl_builder_draft'   => $layout,
		);
	}

	/**
	 * Return the layout tree Beaver expects: row, column group, column, module.
	 *
	 * @return array<string,object>
	 */
	private function get_layout(): array {
		$row_id    = $this->get_node_id();
		$group_id  = $this->get_node_id();
		$column_id = $this->get_node_id();
		$module_id = $this->get_node_id();

		$row           = new stdClass();
		$row->node     = $row_id;
		$row->type     = 'row';
		$row->parent   = null;
		$row->position = 0;
		$row->settings = new stdClass();
		$row->global   = false;
		$row->dynamic  = false;

		$group           = new stdClass();
		$group->node     = $group_id;
		$group->type     = 'column-group';
		$group->parent   = $row_id;
		$group->position = 0;
		$group->settings = '';
		$group->global   = false;
		$group->dynamic  = false;

		$column                 = new stdClass();
		$column->node           = $column_id;
		$column->type           = 'column';
		$column->parent         = $group_id;
		$column->position       = 0;
		$column->settings       = new stdClass();
		$column->settings->size = 100;
		$column->global         = false;
		$column->dynamic        = false;

		$module                             = new stdClass();
		$module->node                       = $module_id;
		$module->type                       = 'module';
		$module->parent                     = $column_id;
		$module->position                   = 0;
		$module->settings                   = new stdClass();
		$module->settings->type             = 'Digest';
		$module->settings->period           = Digests::get_instance()->get_default_period();
		$module->settings->format           = Digests::get_instance()->get_default_format();
		$module->settings->override_display = 'no';
		$module->version                    = 1;
		$module->global                     = false;
		$module->dynamic                    = false;

		return array(
			$row_id    => $row,
			$group_id  => $group,
			$column_id => $column,
			$module_id => $module,
		);
	}

	/**
	 * Return a node ID in the format Beaver uses.
	 *
	 * @return string
	 */
	private function get_node_id(): string {
		return strtolower( wp_generate_password( 12, false, false ) );
	}

	/**
	 * Check if Avada and its necessary plugins are active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'FLBuilder' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Beaver', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 30;
	}
}
