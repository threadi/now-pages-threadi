<?php
/**
 * This file contains the main initialization for narrative summarizers.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Summarizers\Ai_Summarizer;
use NowPagesThreadi\Digests\Summarizers\Template_Summarizer;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;

/**
 * Object to initialize the narrative summarizers.
 */
class Summarizers implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Option name used to store the configured default summarizer.
	 */
	private const OPTION_DEFAULT = 'now_pages_threadi_summarizer';

	/**
	 * Registered summarizers, keyed by slug.
	 *
	 * @var array<string,Summarizer_Interface>
	 */
	private array $summarizers = array();

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// use hooks.
		add_filter( 'now_pages_threadi_log_categories', array( $this, 'add_log_category' ) );

		// the template-based summarizer works everywhere, no dependency.
		$this->register_summarizer( new Template_Summarizer() );
		$this->register_summarizer( new Ai_Summarizer() );

		$interface = $this;
		/**
		 * Fires once the built-in summarizers are registered.
		 *
		 * @param Summarizers $interface The summarizers object.
		 */
		do_action( 'now_pages_threadi_register_summarizers', $interface );
	}

	/**
	 * Register a single summarizer.
	 *
	 * @param Summarizer_Interface $summarizer The summarizer to register.
	 *
	 * @return void
	 */
	public function register_summarizer( Summarizer_Interface $summarizer ): void {
		$this->summarizers[ $summarizer->get_slug() ] = $summarizer;
	}

	/**
	 * Return all registered summarizers.
	 *
	 * @return array<string,Summarizer_Interface>
	 */
	public function get_summarizers(): array {
		return $this->summarizers;
	}

	/**
	 * Return a single summarizer by its slug.
	 *
	 * @param string $slug The summarizer slug.
	 *
	 * @return Summarizer_Interface|null
	 */
	public function get_summarizer( string $slug ): ?Summarizer_Interface {
		return $this->summarizers[ $slug ] ?? null;
	}

	/**
	 * Return the configured default summarizer slug.
	 *
	 * @return string
	 */
	public function get_default_summarizer_slug(): string {
		return (string) get_option( self::OPTION_DEFAULT );
	}

	/**
	 * Add our own log category.
	 *
	 * @param array<string,string> $categories List of log categories.
	 *
	 * @return array<string,string>
	 */
	public function add_log_category( array $categories ): array {
		// add our category.
		$categories['summarizers'] = __( 'Summarizers', 'now-pages-threadi' );

		// return the resulting categories.
		return $categories;
	}
}
