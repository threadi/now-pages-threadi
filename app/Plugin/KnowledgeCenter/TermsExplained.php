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

use ThreadiNowPages\NowPages\Entries;
use ThreadiNowPages\NowPages\Frontend;
use ThreadiNowPages\Plugin\KnowledgeCenter_Base;
use ThreadiNowPages\Plugin\Settings;

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
		return __( 'Terms explained', 'threadi-now-pages' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords(): array {
		return array(
			__( 'What does Entries mean?', 'threadi-now-pages' ),
			__( 'What are Presets?', 'threadi-now-pages' ),
			__( 'What are Providers?', 'threadi-now-pages' ),
			__( 'What is a Source?', 'threadi-now-pages' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_text(): string {
		$text  = '<p><strong>' . esc_html__( 'These are terms used by the Now Pages plugin to manage entries. Here is a brief explanation:', 'threadi-now-pages' ) . '</strong></p>';
		$text .= '<h4>' . esc_html__( 'Providers', 'threadi-now-pages' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'This term refers to interfaces to external sources. These can vary widely, which is why they are generally regarded as data providers and referred to as such. Examples include RSS feeds or information from Last.fm about recently played music.', 'threadi-now-pages' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Presets', 'threadi-now-pages' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'These are configurations for external service providers that have been set up for you so you can quickly access their data. For example, you can select WordPress.org as your provider, and the plugin will handle the necessary RSS configuration for you. All you have to do is enter your username-that’s it. Presets help you set up configurations for external providers more quickly.', 'threadi-now-pages' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Source', 'threadi-now-pages' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'Sources contain the information the plugin needs to retrieve data from external providers on your behalf through the configured provider. New entries are created with each retrieval.', 'threadi-now-pages' ) . '</p>';
		$text .= '<h4>' . esc_html__( 'Entries', 'threadi-now-pages' ) . '</h4>';
		$text .= '<p>' . esc_html__( 'Entries are the data records essential for display on your Now Page. They are generated when sources are retrieved, but can also be created manually.', 'threadi-now-pages' ) . '</p>';
		return $text;
	}
}
