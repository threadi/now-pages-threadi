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
class TermsExplained extends KnowledgeCenter_Base {
	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected string $name = 'terms-explained';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Terms explained', 'now-pages-threadi' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords(): array {
		return array(
			__( 'What does Entries mean?', 'now-pages-threadi' ),
			__( 'What are Presets?', 'now-pages-threadi' ),
			__( 'What are Providers?', 'now-pages-threadi' ),
			__( 'What is a Source?', 'now-pages-threadi' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_text(): string {
		$text  = '<p><strong>' . esc_html__( 'These are terms used by the Now Pages plugin to manage entries. Here is a brief explanation:', 'now-pages-threadi' ) . '</strong></p>';
		$text .= '<h4>' . esc_html__( 'Providers', 'now-pages-threadi' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'This term refers to interfaces to external sources. These can vary widely, which is why they are generally regarded as data providers and referred to as such. Examples include RSS feeds or information from Last.fm about recently played music.', 'now-pages-threadi' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Presets', 'now-pages-threadi' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'These are configurations for external service providers that have been set up for you so you can quickly access their data. For example, you can select WordPress.org as your provider, and the plugin will handle the necessary RSS configuration for you. All you have to do is enter your username-that’s it. Presets help you set up configurations for external providers more quickly.', 'now-pages-threadi' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Source', 'now-pages-threadi' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'Sources contain the information the plugin needs to retrieve data from external providers on your behalf through the configured provider. New entries are created with each retrieval.', 'now-pages-threadi' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Entries', 'now-pages-threadi' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'Entries are the data records essential for display on your Now Page. They are generated when sources are retrieved, but can also be created manually.', 'now-pages-threadi' ) . '</p>';
		return $text;
	}
}
