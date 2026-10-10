<?php
/**
 * File to handle the privacy tools of WordPress for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Digests\Display_Settings;
use NowPagesThreadi\Fediverse\Fediverse;
use NowPagesThreadi\Fediverse\Updates;
use NowPagesThreadi\Helper\Format;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\Sources\Sources;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * Object to export and erase the personal data this plugin stores for a user.
 *
 * Covers the entries of the user and the display settings in the user profile.
 */
class Privacy {
	use Singleton_Trait;

	/**
	 * Number of entries handled per page of the exporter and eraser.
	 *
	 * @var int
	 */
	private const PER_PAGE = 100;

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
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Add our exporter to the list of personal data exporters.
	 *
	 * @param array<string,array<string,mixed>> $exporters List of exporters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['now-pages-threadi'] = array(
			'exporter_friendly_name' => __( 'Now Pages', 'now-pages-threadi' ),
			'callback'               => array( $this, 'export' ),
		);

		// return the resulting list.
		return $exporters;
	}

	/**
	 * Add our eraser to the list of personal data erasers.
	 *
	 * @param array<string,array<string,mixed>> $erasers List of erasers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['now-pages-threadi'] = array(
			'eraser_friendly_name' => __( 'Now Pages', 'now-pages-threadi' ),
			'callback'             => array( $this, 'erase' ),
		);

		// return the resulting list.
		return $erasers;
	}

	/**
	 * Export the personal data of the user with the given email.
	 *
	 * @param string $email_address The email address of the user.
	 * @param int    $page          The page to export, starting with 1.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$data = array();

		// get the user.
		$user = get_user_by( 'email', $email_address );

		// bail if no user could be found.
		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		// export the display settings with the first page.
		if ( 1 === $page ) {
			$settings = $this->get_user_settings_data( $user );
			if ( ! empty( $settings ) ) {
				$data[] = array(
					'group_id'          => 'now-pages-threadi-settings',
					'group_label'       => __( 'Now Page settings', 'now-pages-threadi' ),
					'group_description' => __( 'The settings for the own now page in the user profile.', 'now-pages-threadi' ),
					'item_id'           => 'now-pages-threadi-settings-' . $user->ID,
					'data'              => $settings,
				);
			}
			$data = array_merge( $data, $this->get_sources_data( $user ), $this->get_updates_data( $user ) );
		}

		// get the entries of this page.
		$entries = $this->get_entries( $user, max( 1, $page ) );

		// export them.
		foreach ( $entries as $entry ) {
			$source_id = absint( $entry->post_parent );

			$data[] = array(
				'group_id'          => 'now-pages-threadi-entries',
				'group_label'       => __( 'Now Page entries', 'now-pages-threadi' ),
				'group_description' => __( 'The entries of the user shown on the now pages.', 'now-pages-threadi' ),
				'item_id'           => 'now-pages-threadi-entry-' . $entry->ID,
				'data'              => array(
					array(
						'name'  => __( 'Date', 'now-pages-threadi' ),
						'value' => (string) get_the_date( 'c', $entry ),
					),
					array(
						'name'  => __( 'Text', 'now-pages-threadi' ),
						'value' => Format::get_entry_text( $entry ),
					),
					array(
						'name'  => __( 'Status', 'now-pages-threadi' ),
						'value' => $entry->post_status,
					),
					array(
						'name'  => __( 'Source', 'now-pages-threadi' ),
						'value' => $source_id > 0 ? get_the_title( $source_id ) : __( 'added manually', 'now-pages-threadi' ),
					),
					array(
						'name'  => __( 'URL', 'now-pages-threadi' ),
						'value' => (string) get_post_meta( $entry->ID, 'source_url', true ),
					),
					array(
						'name'  => __( 'Language', 'now-pages-threadi' ),
						'value' => (string) get_post_meta( $entry->ID, 'language', true ),
					),
				),
			);
		}

		// return the result.
		return array(
			'data' => $data,
			'done' => count( $entries ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase the personal data of the user with the given email.
	 *
	 * Deletes the entries of the user and the display settings in the profile.
	 *
	 * @param string $email_address The email address of the user.
	 * @param int    $page          The page to erase, starting with 1 (not used, as deleted entries leave the query).
	 *
	 * @return array{items_removed:int,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$result = array(
			'items_removed'  => 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		// get the user.
		$user = get_user_by( 'email', $email_address );

		// bail if no user could be found.
		if ( ! $user instanceof WP_User ) {
			return $result;
		}

		// handle the display settings with the first page.
		if ( 1 === $page ) {
			$keys = Display_Settings::user_meta_keys();
			foreach ( $keys as $key => $meta_key ) {
				// keep the privacy settings: removing them would make the user visible again.
				if ( in_array( $key, array( 'disabled', 'delay_hours' ), true ) ) {
					continue;
				}

				if ( delete_user_meta( $user->ID, $meta_key ) ) {
					++$result['items_removed'];
				}
			}

			// disable the now page of this user, so entries of remaining sources are never shown.
			update_user_meta( $user->ID, $keys['disabled'], 1 );

			// tell the administrator about sources which would still import entries for this user.
			$sources = get_posts(
				array(
					'post_type'      => Sources::get_instance()->get_slug(),
					'post_status'    => 'any',
					'author'         => $user->ID,
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! empty( $sources ) ) {
				$result['messages'][] = __( 'The user still owns now page sources. Their now page has been disabled.', 'now-pages-threadi' );
			}
		}

		// get the next entries: always the first page, as deleted entries leave the result.
		$entries = $this->get_entries( $user, 1 );

		remove_action( 'before_delete_post', array( Entries::get_instance(), 'remember_deletion' ), 10 );

		// delete them.
		$failed = false;
		foreach ( $entries as $entry ) {
			// remember the shared update of this entry before the entry and its meta are gone.
			$update_id = absint( get_post_meta( $entry->ID, Fediverse::META_UPDATE, true ) );

			// delete the entry.
			if ( wp_delete_post( $entry->ID, true ) ) {
				++$result['items_removed'];

				// delete the update which was shared for this entry.
				if ( $update_id > 0 && Updates::get_instance()->get_slug() === get_post_type( $update_id ) ) {
					wp_delete_post( $update_id, true );
				}
				continue;
			}

			// the entry could not be deleted.
			$failed                   = true;
			$result['items_retained'] = true;
			/* translators: %1$d will be replaced by the ID of an entry. */
			$result['messages'][] = sprintf( __( 'The now page entry with ID %1$d could not be deleted.', 'now-pages-threadi' ), $entry->ID );
		}

		add_action( 'before_delete_post', array( Entries::get_instance(), 'remember_deletion' ), 10, 2 );

		// we are done if all entries have been loaded or an entry could not be deleted (to prevent an endless loop).
		$result['done'] = count( $entries ) < self::PER_PAGE || $failed;

		// with the last run: remove what is left of this user.
		if ( $result['done'] && ! $failed ) {
			$leftovers = get_posts(
				array(
					'post_type'      => array( Sources::get_instance()->get_slug(), Updates::get_instance()->get_slug() ),
					'post_status'    => array( 'any', 'trash', 'auto-draft' ),
					'author'         => $user->ID,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			);

			foreach ( $leftovers as $post_id ) {
				if ( wp_delete_post( absint( $post_id ), true ) ) {
					++$result['items_removed'];
				}
			}
		}

		// return the result.
		return $result;
	}

	/**
	 * Return the entries of a user.
	 *
	 * @param WP_User $user The user.
	 * @param int     $page The page, starting with 1.
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_entries( WP_User $user, int $page ): array {
		$query = new WP_Query(
			array(
				'post_type'      => Entries::get_instance()->get_slug(),
				'post_status'    => array( 'any', 'trash', 'auto-draft' ),
				'author'         => $user->ID,
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		// collect the entries.
		$entries = array();
		foreach ( (array) $query->posts as $post ) {
			// bail if this is not a post object.
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$entries[] = $post;
		}

		// return the list.
		return $entries;
	}

	/**
	 * Return the display settings of a user for the export.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return array<int,array{name:string,value:string}>
	 */
	private function get_user_settings_data( WP_User $user ): array {
		$data = array();

		// collect each setting the user has set.
		foreach ( Display_Settings::user_meta_keys() as $key => $meta_key ) {
			$value = get_user_meta( $user->ID, $meta_key, true );

			// bail if the setting is not set.
			if ( '' === $value || false === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$data[] = array(
				'name'  => $key,
				'value' => (string) $value,
			);
		}

		// return the list.
		return $data;
	}

	/**
	 * Return the sources of a user for the export.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_sources_data( WP_User $user ): array {
		$data = array();

		$sources = get_posts(
			array(
				'post_type'      => Sources::get_instance()->get_slug(),
				'post_status'    => 'any',
				'author'         => $user->ID,
				'posts_per_page' => -1,
			)
		);

		foreach ( $sources as $source ) {
			$data[] = array(
				'group_id'          => 'now-pages-threadi-sources',
				'group_label'       => __( 'Now Page sources', 'now-pages-threadi' ),
				'group_description' => __( 'The sources the entries of the user are imported from.', 'now-pages-threadi' ),
				'item_id'           => 'now-pages-threadi-source-' . $source->ID,
				'data'              => array(
					array(
						'name'  => __( 'Title', 'now-pages-threadi' ),
						'value' => get_the_title( $source ),
					),
					array(
						'name'  => __( 'Provider', 'now-pages-threadi' ),
						'value' => (string) get_post_meta( $source->ID, 'provider', true ),
					),
					array(
						'name'  => __( 'Status', 'now-pages-threadi' ),
						'value' => $source->post_status,
					),
					array(
						'name'  => __( 'Created', 'now-pages-threadi' ),
						'value' => (string) get_the_date( 'c', $source ),
					),
				),
			);
		}

		return $data;
	}

	/**
	 * Return what was shared in the Fediverse in the name of a user for the export.
	 *
	 * @param WP_User $user The user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_updates_data( WP_User $user ): array {
		$data = array();

		$updates = get_posts(
			array(
				'post_type'      => Updates::get_instance()->get_slug(),
				'post_status'    => array( 'any', 'trash' ),
				'author'         => $user->ID,
				'posts_per_page' => -1,
			)
		);

		foreach ( $updates as $update ) {
			$data[] = array(
				'group_id'          => 'now-pages-threadi-updates',
				'group_label'       => __( 'Now Page updates in the Fediverse', 'now-pages-threadi' ),
				'group_description' => __( 'What was shared in the Fediverse in the name of the user.', 'now-pages-threadi' ),
				'item_id'           => 'now-pages-threadi-update-' . $update->ID,
				'data'              => array(
					array(
						'name'  => __( 'Date', 'now-pages-threadi' ),
						'value' => (string) get_the_date( 'c', $update ),
					),
					array(
						'name'  => __( 'Text', 'now-pages-threadi' ),
						'value' => wp_strip_all_tags( $update->post_content ),
					),
					array(
						'name'  => __( 'Status', 'now-pages-threadi' ),
						'value' => $update->post_status,
					),
				),
			);
		}

		return $data;
	}

	/**
	 * Return the example for privacy policy.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		// bail if wp_add_privacy_policy_content does not exist.
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		// prepare the content.
		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Now Pages imports activities from the services you configure as sources and shows them on a public page. Which data leaves this website depends on the sources and features you use.', 'now-pages-threadi' ) . '</p>'
					. '<p>' . esc_html__( 'We show recent activities of the authors of this website on a public page. For this we request data from the following services: [list your sources, e.g., GitHub, Last.fm, Steam]. With each request the address of this website is transmitted. If summaries are generated by an AI service, the texts of the entries are sent to [name of the AI provider]. If sharing in the Fediverse is enabled, entries are sent to other servers and cannot be reliably deleted there.', 'now-pages-threadi' ) . '</p>';

		// add the content.
		wp_add_privacy_policy_content( __( 'Now Pages', 'now-pages-threadi' ), wp_kses_post( $content ) );
	}
}
