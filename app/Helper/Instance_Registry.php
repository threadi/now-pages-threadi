<?php
/**
 * File for helper to load instances of a given object.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Helper;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object as helper for the object registry.
 */
class Instance_Registry implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * Return initialized singleton-instances of all given classes that extend/implement $base_class.
	 *
	 * @template T of Singleton_Interface
	 *
	 * @param array<class-string> $class_names Fully qualified class names to check.
	 * @param class-string<T>     $base_class  The base class/interface each instance must extend.
	 *
	 * @return T[]
	 */
	public function get_instances_of( array $class_names, string $base_class ): array {
		$instances = array();

		foreach ( $class_names as $class_name ) {
			if ( ! is_subclass_of( $class_name, $base_class ) ) {
				continue;
			}

			$instances[] = $class_name::get_instance();
		}

		return $instances;
	}

	/**
	 * Return new instances of all given classes that extend/implement $base_class.
	 *
	 * Counterpart to get_instances_of(): use this for classes where you
	 * explicitly want a fresh object every time (e.g. because callers
	 * mutate instance-state like a label), not a shared singleton.
	 *
	 * @template T of object
	 *
	 * @param array<class-string> $class_names Fully qualified class names to check.
	 * @param class-string<T>     $base_class  The base class/interface each instance must extend.
	 *
	 * @return T[]
	 */
	public function get_new_instances_of( array $class_names, string $base_class ): array {
		$instances = array();

		foreach ( $class_names as $class_name ) {
			if ( $class_name !== $base_class && ! is_subclass_of( $class_name, $base_class ) ) {
				continue;
			}

			$instances[] = new $class_name();
		}

		return $instances;
	}
}
