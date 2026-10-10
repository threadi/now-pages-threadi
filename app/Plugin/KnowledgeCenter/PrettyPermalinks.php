<?php
/**
 * File to handle a knowledge center entry.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin\KnowledgeCenter;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Plugin\KnowledgeCenter_Base;

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
		return __( 'Pretty Permalinks', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords(): array {
		return array(
			__( 'The Now Page has a parameterized URL.', 'now-pages-threadi' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_text(): string {
		return '<p class="now-pages-threadi-knowledge-entry">' . __( 'To resolve this, please change your permalink settings to use pretty URLs.', 'now-pages-threadi' ) . '</p>';
	}
}
