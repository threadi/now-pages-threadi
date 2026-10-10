<?php
/**
 * REST controller for entries: allow read access only to users who can edit entries.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Format;
use WP_Error;
use WP_Post;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Restrict the REST endpoints of entries to editors.
 */
class Entries_Rest_Controller extends WP_REST_Posts_Controller {

	/**
	 * Return whether the current user may edit entries.
	 *
	 * @return bool
	 */
	private function current_user_can_edit(): bool {
		$post_type = get_post_type_object( $this->post_type );
		return $post_type instanceof \WP_Post_Type && current_user_can( $post_type->cap->edit_posts );
	}

	/**
	 * Return whether the current user may edit entries of other users.
	 *
	 * @return bool
	 */
	private function current_user_can_edit_others(): bool {
		$post_type = get_post_type_object( $this->post_type );
		return $post_type instanceof \WP_Post_Type && current_user_can( $post_type->cap->edit_others_posts );
	}

	/**
	 * Return the error for missing permissions.
	 *
	 * @return WP_Error
	 */
	private function get_forbidden_error(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'now-pages-threadi' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Check permission for the list.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ): true|WP_Error {
		if ( ! $this->current_user_can_edit() ) {
			return $this->get_forbidden_error();
		}
		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Check permission for a single entry.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ): WP_Error|bool {
		if ( ! $this->current_user_can_edit() ) {
			return $this->get_forbidden_error();
		}
		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Also cover embeds and other internal read checks.
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public function check_read_permission( $post ): bool {
		// bail if the user could not edit entries at all.
		if ( ! $this->current_user_can_edit() ) {
			return false;
		}

		// bail if the entry belongs to another user and the user may not edit those.
		if ( ! $this->current_user_can_edit_others() && get_current_user_id() !== absint( $post->post_author ) ) {
			return false;
		}

		return parent::check_read_permission( $post );
	}

	/**
	 * Prepare a single entry for the response.
	 *
	 * Imported entries come from external services: rendering their content
	 * for "content.rendered" must not run shortcodes or blocks of the feed
	 * author. They are rendered as escaped plain text instead, the raw
	 * content for the editor stays untouched.
	 *
	 * @param mixed           $item    The entry.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		// bail if this is a manual entry, it is rendered like any other post.
		if ( ! $item instanceof WP_Post || Entries::get_instance()->is_manual( $item ) ) {
			return parent::prepare_item_for_response( $item, $request );
		}

		// use the plain text without markup, and mask the brackets so no shortcode runs.
		$safe_item               = clone $item;
		$safe_item->post_content = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( Format::get_entry_text( $item ) ) );

		// prepare the response with the safe content.
		$response = parent::prepare_item_for_response( $safe_item, $request );

		// get the data.
		$data = $response->get_data();

		// bail if the raw content is not part of the response.
		if ( ! is_array( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) || ! array_key_exists( 'raw', $data['content'] ) ) {
			return $response;
		}

		// restore the original raw content for editing.
		$data['content']['raw'] = $item->post_content;
		if ( array_key_exists( 'block_version', $data['content'] ) ) {
			$data['content']['block_version'] = block_version( $item->post_content );
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Limit the list to the own entries for users who may not edit others.
	 *
	 * Without this, the author parameter would list the entries of every
	 * other user.
	 *
	 * @param array<string,mixed> $prepared_args Optional. Prepared WP_Query arguments. Default empty array.
	 * @param WP_REST_Request     $request       Optional. Full details about the request.
	 *
	 * @return array<string,mixed>
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ): array {
		$query_args = parent::prepare_items_query( $prepared_args, $request );

		// bail if the user may see the entries of other users.
		if ( $this->current_user_can_edit_others() ) {
			return $query_args;
		}

		// force the query to the own entries.
		unset( $query_args['author'], $query_args['author_name'], $query_args['author__not_in'] );
		$query_args['author__in'] = array( get_current_user_id() );

		return $query_args;
	}
}
