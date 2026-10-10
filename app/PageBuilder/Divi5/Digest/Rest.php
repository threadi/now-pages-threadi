<?php
/**
 * File for the object to handle the REST tasks.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\PageBuilder\Divi5\Digest;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ET\Builder\Framework\Controllers\RESTController;
use ET\Builder\Framework\UserRole\UserRole;
use NowPagesThreadi\Digests\Digest_Builder;
use NowPagesThreadi\Digests\Digests;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for this module.
 */
class Rest extends RESTController {

	/**
	 * Return data for the Dynamic Module.
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'period' => (string) $request->get_param( 'period' ),
			'format' => (string) $request->get_param( 'format' ),
		);

		// use the same defaults as the frontend, see Digest::render_content().
		if ( '' === $args['period'] ) {
			$args['period'] = Digests::get_instance()->get_default_period();
		}
		if ( '' === $args['format'] ) {
			$args['format'] = Digests::get_instance()->get_default_format();
		}

		if ( 'on' === $request->get_param( 'override_display' ) ) {
			$names = array( 'show_time', 'show_source', 'link_source', 'show_period', 'group_by_category' );

			foreach ( $names as $name ) {
				$args[ $name ] = 'on' === $request->get_param( $name );
			}
		}

		$response = array(
			'data' => ( new Digest_Builder() )->build( $args ),
		);

		return self::response_success( $response );
	}

	/**
	 * Index action arguments.
	 *
	 * Endpoint arguments as used in `register_rest_route()`.
	 *
	 * @return array
	 */
	public static function index_args(): array {
		return array();
	}

	/**
	 * Index action permission.
	 *
	 * Endpoint permission callback as used in `register_rest_route()`.
	 *
	 * @return bool
	 */
	public static function index_permission(): bool {
		return UserRole::can_current_user_use_visual_builder();
	}
}
