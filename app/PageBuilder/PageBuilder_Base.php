<?php
/**
 * File for the base object for each page builder support.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\PageBuilder;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object as the base for each page builder.
 */
class PageBuilder_Base {
	/**
	 * True if Page Builder has templates.
	 *
	 * @var bool
	 */
	protected bool $has_templates = false;

	/**
	 * User can enable this extension.
	 *
	 * @var bool
	 */
	protected bool $can_be_enabled_by_user = false;

	/**
	 * Initialize the Page Builder support.
	 *
	 * @return void
	 */
	public function init(): void {}

	/**
	 * Return widgets this page builder supports.
	 *
	 * This means any widgets, block, component ... name it. The returning strings should contain their
	 * class names incl. namespace.
	 *
	 * @return array<string>
	 */
	public function get_widgets(): array {
		return array();
	}

	/**
	 * Return the slug of this page builder.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return '';
	}

	/**
	 * Return the label of this page builder.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return '';
	}

	/**
	 * Return whether this page builder is active on this website.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return false;
	}

	/**
	 * Return the priority of this page builder.
	 *
	 * If more than one is active, the one with the highest priority decides
	 * how a new now page is filled. The shortcode is the fallback, so it
	 * has the lowest.
	 *
	 * We use:
	 * - 0 for the fallback shortcode.
	 * - 10 for Gutenberg
	 * - 20 for each theme-bound pagebuilder
	 * - 30 for all other
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 0;
	}

	/**
	 * Return the content a fresh now page should start with.
	 *
	 * Empty string means this page builder cannot prefill a page - then
	 * the next one in line is asked.
	 *
	 * @return string
	 */
	public function get_page_content(): string {
		return '[threadi_now_pages_digest]';
	}

	/**
	 * Return post meta a fresh now page needs for this page builder.
	 *
	 * Elementor and Avada store their state in meta, not only in the
	 * content. Without it, they would not recognize the page as theirs.
	 *
	 * @return array<string,mixed>
	 */
	public function get_page_meta(): array {
		return array();
	}

	/**
	 * Register hooks which must exist while the plugins are loaded.
	 *
	 * Runs when the main file is included, before "plugins_loaded". Only for page
	 * builders which start themselves during the loading of the plugins. Nothing
	 * else may be initialized here.
	 *
	 * @return void
	 */
	public function early_init(): void {}
}
