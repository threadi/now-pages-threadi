<?php
/**
 * This file contains the support for Oxygen.
 *
 * Oxygen 6 is Breakdance: the plugin ships the Breakdance codebase and
 * switches it with define('BREAKDANCE_MODE', 'oxygen'). Namespaces, hooks,
 * the element format and the post meta key are identical, so this class
 * only has to answer "which plugin is running" differently.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Trait;

/**
 * Registers and handles the support for Oxygen.
 */
class Oxygen extends Breakdance {
	/**
	 * Re-use the trait so this class gets its own singleton entry instead of
	 * sharing the one of its parent.
	 */
	use Singleton_Trait;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Oxygen', 'now-pages-threadi' );
	}

	/**
	 * Check if Oxygen is active.
	 *
	 * Only Oxygen 6 and newer is supported: that is the version built on the
	 * Breakdance codebase. Oxygen 4 and older used the OxyEl element API and
	 * its main file is functions.php, so it does not match here.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return Plugin::is_plugin_active( 'oxygen/plugin.php' );
	}
}
