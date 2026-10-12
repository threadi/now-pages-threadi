<?php
/**
 * File to handle Divi Extension.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder\Divi4;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use DiviExtension;
use ThreadiNowPages\Helper\Plugin;

/**
 * Extend the DiviExtension to create our own extension for Divi.
 */
class Extend_Divi extends DiviExtension {

	/**
	 * The gettext domain for the extension's translations.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $gettext_domain = 'threadi-now-pages';

	/**
	 * The extension's WP Plugin name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $name = 'threadi-now-pages';

	/**
	 * The extension's version
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $version = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @param string $name Internal name for the extension.
	 * @param array  $args Arguments for the call.
	 */
	public function __construct( $name = 'threadi-now-pages', $args = array() ) {
		$this->plugin_dir     = Plugin::get_plugin_path() . 'legacy-classes/Divi4/includes/';
		$this->plugin_dir_url = Plugin::get_plugin_url() . 'legacy-classes/Divi4/';

		// use the plugin version, so browsers load the scripts again after an update.
		$this->version = NOWPAGES_PLUGIN_VERSION;

		parent::__construct( $name, $args );
	}
}
