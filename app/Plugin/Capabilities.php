<?php
/**
 * File to handle the capabilities on roles and users used from this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use WP_User;

/**
 * Object to handle capabilities on roles and users from this plugin.
 */
class Capabilities {
	/**
	 * Instance of this object.
	 *
	 * @var ?Capabilities
	 */
	private static ?Capabilities $instance = null;

	/**
	 * Constructor for this object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Capabilities {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {}

	/**
	 * Install the roles we use.
	 *
	 * @return void
	 */
	public function install(): void {
		// add our custom role.
		$manager_role = get_role( 'manage_now_pages_threadi' );
		if ( is_null( $manager_role ) ) {
			add_role( 'manage_now_pages_threadi', __( 'Manage Now Pages', 'now-pages-threadi' ) );
		}

		// set the capabilities.
		$this->set_capabilities_for_role( 'manage_now_pages_threadi' );
		$this->set_capabilities_for_role( 'administrator' );
	}

	/**
	 * Add the capabilities for a single given role.
	 *
	 * Hint: the administrator gets some more.
	 *
	 * @param string $role The role.
	 *
	 * @return void
	 */
	public function set_capabilities_for_role( string $role ): void {
		foreach ( Post_Types::get_instance()->get_post_type_slugs() as $slug ) {
			$role_obj = get_role( $role );
			if ( $role_obj ) {
				foreach ( $this->get_default_capabilities( $slug ) as $cap ) {
					$role_obj->add_cap( $cap );
				}

				// if this is the administrator, add some more.
				if ( 'administrator' === $role ) {
					$role_obj->add_cap( 'edit_others_' . $slug . 's' );
					$role_obj->add_cap( 'edit_private_' . $slug . 's' );
					$role_obj->add_cap( 'read_private_' . $slug . 's' );
					$role_obj->add_cap( 'delete_others_' . $slug . 's' );
					$role_obj->add_cap( 'delete_private_' . $slug . 's' );
				}
			}
		}
	}

	/**
	 * Set capability for a single user.
	 *
	 * @param WP_User $user The given user.
	 *
	 * @return void
	 */
	public function set_capabilities_for_user( WP_User $user ): void {
		foreach ( Post_Types::get_instance()->get_post_type_slugs() as $slug ) {
			foreach ( $this->get_default_capabilities( $slug ) as $cap ) {
				$user->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the capability for a single user.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return void
	 */
	public function remove_capabilities_for_user( WP_User $user ): void {
		// bail if the user is administrator.
		if ( array_intersect( $user->roles, array( 'administrator' ) ) ) {
			return;
		}

		// remove our own capabilities on this user.
		foreach ( Post_Types::get_instance()->get_post_type_slugs() as $slug ) {
			foreach ( $this->get_default_capabilities( $slug ) as $cap ) {
				$user->remove_cap( $cap );
			}
		}
	}

	/**
	 * Return the list of the default cpt-specific capabilities for roles and users.
	 *
	 * @param string $slug The given slug.
	 *
	 * @return array<int,string>
	 */
	private function get_default_capabilities( string $slug ): array {
		return array(
			'read',
			'edit_' . $slug . 's',
			'edit_published_' . $slug . 's',
			'publish_' . $slug . 's',
			'delete_' . $slug . 's',
			'delete_published_' . $slug . 's',
			'read_' . $slug . 's',
		);
	}

	/**
	 * Remove the role we use.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		/**
		 * Remove our own role.
		 */
		remove_role( 'manage_now_pages_threadi' );

		/**
		 * Remove our capabilities from other roles.
		 */
		$this->remove_capabilities_for_each_role();
		$this->remove_capabilities_for_role( 'administrator' );

		/**
		 * Remove our capabilities from single users.
		 */
		$this->remove_capabilities_for_all_users();
	}

	/**
	 * Remove our capabilities from every user which got them individually.
	 *
	 * @return void
	 */
	private function remove_capabilities_for_all_users(): void {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time cleanup during uninstallation.
					array(
						'key'     => $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities',
						'value'   => 'np_threadi_',
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $users as $user ) {
			foreach ( Post_Types::get_instance()->get_post_type_slugs() as $slug ) {
				foreach ( $this->get_default_capabilities( $slug ) as $cap ) {
					// never remove the basic read capability of a user.
					if ( 'read' === $cap ) {
						continue;
					}
					$user->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove the capabilities for a given role.
	 *
	 * @param string $role The role.
	 *
	 * @return void
	 */
	public function remove_capabilities_for_role( string $role ): void {
		foreach ( Post_Types::get_instance()->get_post_type_slugs() as $slug ) {
			$role_obj = get_role( $role );
			if ( $role_obj ) {
				// remove the capabilities.
				$role_obj->remove_cap( 'edit_' . $slug . 's' );
				$role_obj->remove_cap( 'edit_others_' . $slug . 's' );
				$role_obj->remove_cap( 'edit_published_' . $slug . 's' );
				$role_obj->remove_cap( 'edit_private_' . $slug . 's' );
				$role_obj->remove_cap( 'publish_' . $slug . 's' );
				$role_obj->remove_cap( 'read_' . $slug );
				$role_obj->remove_cap( 'read_' . $slug . 's' );
				$role_obj->remove_cap( 'read_private_' . $slug . 's' );
				$role_obj->remove_cap( 'delete_' . $slug . 's' );
				$role_obj->remove_cap( 'delete_others_' . $slug . 's' );
				$role_obj->remove_cap( 'delete_published_' . $slug . 's' );
				$role_obj->remove_cap( 'delete_private_' . $slug . 's' );
			}
		}
	}

	/**
	 * Remove our capabilities for all roles.
	 *
	 * @return void
	 */
	public function remove_capabilities_for_each_role(): void {
		global $wp_roles;
		foreach ( $wp_roles->roles as $role_name => $settings ) {
			// bail on administrator.
			if ( 'administrator' === $role_name ) {
				continue;
			}

			$this->remove_capabilities_for_role( $role_name );
		}
	}
}
