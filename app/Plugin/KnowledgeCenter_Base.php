<?php
/**
 * File to handle basic functions for each knowledge center entry.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Base object for each knowledge center entry.
 */
class KnowledgeCenter_Base {
	/**
	 * The internal name of the knowledge center entry.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * Return the internal name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Return the title of the knowledge center entry.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return '';
	}

	/**
	 * Return the list of keywords for this knowledge center entry.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords(): array {
		return array();
	}

	/**
	 * Return the text for this knowledge center entry.
	 *
	 * @return string
	 */
	public function get_text(): string {
		return '';
	}
}
