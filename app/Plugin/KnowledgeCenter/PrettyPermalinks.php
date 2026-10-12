<?php
/**
 * File to handle a knowledge center entry.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin\KnowledgeCenter;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Plugin\KnowledgeCenter_Base;

/**
 * Object for a single knowledge center entry.
 */
class PrettyPermalinks extends KnowledgeCenter_Base {
	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected string $name = 'pretty-permalinks';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Pretty Permalinks', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords(): array {
		return array(
			__( 'The Now Page has a parameterized URL.', 'threadi-now-pages' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_text(): string {
		return '<p class="threadi-now-pages-knowledge-entry">' . __( 'To resolve this, please change your permalink settings to use pretty URLs.', 'threadi-now-pages' ) . '</p>';
	}
}
