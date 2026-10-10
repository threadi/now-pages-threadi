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

use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\Plugin\KnowledgeCenter_Base;
use NowPagesThreadi\Plugin\Settings;

/**
 * Object for a single knowledge center entry.
 */
class FindTheNowPage extends KnowledgeCenter_Base {
	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected string $name = 'find-the-now-page';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'How to find the Now Page?', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords(): array {
		return array(
			__( 'You cannot find the URL of your Now Page.', 'now-pages-threadi' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_text(): string {
		$text  = '<p>' . esc_html__( 'There are several ways to find your “Now Page” on your website:', 'now-pages-threadi' ) . '</p>';
		$text .= '<ul>';
		/* translators: %1$s will be replaced by a URL. */
		$text .= '<li>' . sprintf( __( 'Open "<a href="%1$s">Now Page Entries</a>" in the menu on the left. There, at the top of the admin bar, you will find a link that says "View your Now Page in frontend."', 'now-pages-threadi' ), Entries::get_instance()->get_backend_list_url() ) . '</li>';
		/* translators: %1$s will be replaced by a URL. */
		$text .= '<li>' . sprintf( __( 'Open "<a href="%1$s">the settings</a>". There, at the top of the admin bar, you will find a link that says "View your Now Page in frontend."', 'now-pages-threadi' ), Settings::get_instance()->get_url() ) . '</li>';
		/* translators: %1$s will be replaced by a URL. */
		$text .= '<li>' . sprintf( __( 'Or just click <a href="%1$s">here</a>.', 'now-pages-threadi' ), Frontend::get_instance()->get_global_now_page_url() ) . '</li>';
		$text .= '</ul>';
		return $text;
	}
}
