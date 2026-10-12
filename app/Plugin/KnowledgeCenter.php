<?php
/**
 * File for handling the knowledge center with help texts for different situations the user could have with this plugin.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Helper\Instance_Registry;
use ThreadiNowPages\Helper\Plugin;
use WP_Screen;

/**
 * Object to handle the knowledge center.
 */
class KnowledgeCenter {
	/**
	 * Variable for the instance of this Singleton object.
	 *
	 * @var ?KnowledgeCenter
	 */
	private static ?KnowledgeCenter $instance = null;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	protected function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): KnowledgeCenter {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Extend the help tab with our knowledge center entries.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_action( 'current_screen', array( $this, 'add_help' ) );

		// add tabs in help.
		add_filter( 'threadi_now_pages_help_tabs', array( $this, 'add_entries' ), 20 );
		add_filter( 'threadi_now_pages_help_tabs', array( $this, 'add_documentation_help' ) );
	}

	/**
	 * Return the list of entries in the knowledge center.
	 *
	 * @return array<int,class-string<KnowledgeCenter_Base>>
	 */
	public function get_entries(): array {
		$entries = array(
			'\ThreadiNowPages\Plugin\KnowledgeCenter\FindTheNowPage',
			'\ThreadiNowPages\Plugin\KnowledgeCenter\PrettyPermalinks',
			'\ThreadiNowPages\Plugin\KnowledgeCenter\TermsExplained',
		);

		/**
		 * Filter the list of available knowledge center entries.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,class-string<KnowledgeCenter_Base>> $entries List of knowledge center entries.
		 */
		return apply_filters( 'threadi_now_pages_knowledge_center_entries', $entries );
	}

	/**
	 * Return the entry for a given text.
	 *
	 * @param string $text The text.
	 *
	 * @return false|KnowledgeCenter_Base
	 */
	public function get_entry_by_text( string $text ): false|KnowledgeCenter_Base {
		foreach ( Instance_Registry::get_instance()->get_new_instances_of( $this->get_entries(), KnowledgeCenter_Base::class ) as $entry ) {
			// return the entry if the text matches.
			if ( in_array( $text, $entry->get_keywords(), true ) ) {
				return $entry;
			}
		}

		// return false if no entry was found.
		return false;
	}

	/**
	 * Add the help box to our own pages with the configured contents.
	 *
	 * @param WP_Screen $screen The screen object.
	 *
	 * @return void
	 */
	public function add_help( WP_Screen $screen ): void {
		$allowed = false;
		/**
		 * Prevent adding the WordPress-internal help for our plugin.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param bool $allowed True if the help should be visible.
		 * @param WP_Screen $screen The actual visible screen.
		 */
		if ( ! apply_filters( 'threadi_now_pages_show_help', $allowed, $screen ) ) {
			return;
		}

		// get the help tabs.
		$help_tabs = $this->get_help_tabs();

		// bail if the list is empty.
		if ( empty( $help_tabs ) ) {
			return;
		}

		// add our own help tabs.
		foreach ( $help_tabs as $help_tab ) {
			$screen->add_help_tab( $help_tab );
		}

		// add the sidebar.
		$this->add_sidebar( $screen );
	}

	/**
	 * Return the list of help tabs.
	 *
	 * @return array<string,mixed>
	 */
	private function get_help_tabs(): array {
		$list = array();

		/**
		 * Filter the list of help tabs with its contents.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<string,mixed> $list List of help tabs.
		 */
		return apply_filters( 'threadi_now_pages_help_tabs', $list );
	}

	/**
	 * Add the sidebar with its content.
	 *
	 * @param WP_Screen $screen The screen object.
	 *
	 * @return void
	 */
	private function add_sidebar( WP_Screen $screen ): void {
		// get content for sidebar.
		$sidebar_content = '<p><strong>' . __( 'Question not answered?', 'threadi-now-pages' ) . '</strong></p><p><a href="' . esc_url( Plugin::get_plugin_support_url() ) . '" target="_blank">' . esc_html__( 'Ask in our forum', 'threadi-now-pages' ) . '</a></p>';

		/**
		 * Filter the sidebar content.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param string $sidebar_content The content.
		 */
		$sidebar_content = apply_filters( 'threadi_now_pages_help_sidebar_content', $sidebar_content );

		// add the sidebar with the given content.
		$screen->set_help_sidebar( $sidebar_content );
	}

	/**
	 * Add a hint for our documentation..
	 *
	 * @param array<int,array<string,mixed>> $help_list List of help tabs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_documentation_help( array $help_list ): array {
		// collect the content for the help.
		/* translators: %1$s will be replaced by a URL. */
		$content = '<h2>' . __( 'Documentation', 'threadi-now-pages' ) . '</h2><p>' . sprintf( __( 'We provide some documentation for the WordPress plugin <i>Now Pages</i> at <a href="%1$s" target="_blank">GitHub (opens a new window)</a>.', 'threadi-now-pages' ), esc_url( Plugin::get_github_documentation_link() ) ) . '</p>';

		// add the help entry.
		$help_list[] = array(
			'id'      => 'threadi-now-pages-documentation',
			'title'   => __( 'Documentations', 'threadi-now-pages' ),
			'content' => $content,
		);

		// return the resulting list.
		return $help_list;
	}

	/**
	 * Add the knowledge base entries to the help tabs.
	 *
	 * @param array<int,array<string,mixed>> $help_list List of help tabs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function add_entries( array $help_list ): array {
		foreach ( Instance_Registry::get_instance()->get_new_instances_of( $this->get_entries(), KnowledgeCenter_Base::class ) as $entry ) {
			// create the content.
			$content = '<h2>' . esc_html( $entry->get_title() ) . '</h2><h3>' . esc_html__( 'The problem:', 'threadi-now-pages' ) . '</h3><p>' . wp_kses_post( implode( '<br>', $entry->get_keywords() ) ) . '</p><h3>' . esc_html__( 'The solution:', 'threadi-now-pages' ) . '</h3> ' . wp_kses_post( $entry->get_text() );

			// add help for the now pages in general.
			$help_list[] = array(
				'id'      => 'threadi-now-pages-' . $entry->get_name(),
				'title'   => $entry->get_title(),
				'content' => $content,
			);
		}

		// return the resulting list.
		return $help_list;
	}
}
