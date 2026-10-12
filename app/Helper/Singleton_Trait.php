<?php
/**
 * File for the reusable singleton-boilerplate.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the get_instance()/clone-boilerplate every Singleton_Interface object needs.
 *
 * Uses a single array keyed by static::class instead of a `?self $instance`
 * property, so using classes do NOT need to redeclare their own typed
 * $instance property (property types are invariant per class in PHP, which
 * caused the PHPStan "should return static but returns X" errors). Using
 * classes should still declare `private function __construct() {}`
 * themselves if they want to actually enforce the Singleton pattern.
 */
trait Singleton_Trait {

	/**
	 * Instances of this Singleton object, indexed by class-name.
	 *
	 * @var array<class-string,object>
	 */
	private static array $instances = array();

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return static
	 */
	public static function get_instance(): static {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			self::$instances[ static::class ] = new static();
		}

		/**
		 * The instance.
		 *
		 * @var static $instance
		 */
		$instance = self::$instances[ static::class ];

		// return the instance.
		return $instance;
	}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone(): void {}
}
