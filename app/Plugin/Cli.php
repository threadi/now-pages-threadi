<?php
/**
 * This file contains the handler for WP CLI tasks.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\NowPages\Entries;
use NowPagesThreadi\NowPages\Entry;
use NowPagesThreadi\Sources\Sources;
use WP_Query;

/**
 * Object for handler for WP CLI tasks.
 */
class Cli {
	/**
	 * Reset the plugin on this site: removes all entries, sources and settings.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * ## EXAMPLES
	 *
	 *     wp now-pages-threadi reset_plugin
	 *     wp now-pages-threadi reset_plugin --yes --url=example.com/subsite
	 *
	 * @param array<int,string>    $args       The positional arguments.
	 * @param array<string,string> $assoc_args The named arguments.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function reset_plugin( array $args = array(), array $assoc_args = array() ): void {
		\WP_CLI::confirm(
			sprintf( 'Reset Now Pages on %s? All entries, sources and settings of this site will be deleted.', home_url() ),
			$assoc_args
		);

		Deactivation::get_instance()->run();
		Uninstallation::get_instance()->run();
		Activation::get_instance()->run();
	}

	/**
	 * Request all external sources.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 */
	public function request_sources(): void {
		Sources::get_instance()->import();
	}

	/**
	 * Delete all entries of this site.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * @param array<int,string>    $args       The positional arguments.
	 * @param array<string,string> $assoc_args The named arguments.
	 *
	 * @return void
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function delete_entries( array $args = array(), array $assoc_args = array() ): void {
		\WP_CLI::confirm(
			sprintf( 'Delete all Now Pages entries on %s? This cannot be undone.', home_url() ),
			$assoc_args
		);

		$query  = array(
			'post_type'      => Entries::get_instance()->get_slug(),
			'post_status'    => array( 'any', 'trash', 'auto-draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$result = new WP_Query( $query );

		// show cli hint.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting all entries', $result->found_posts );

		// delete them.
		foreach ( (array) $result->posts as $post_id ) {
			// bail if post ID is not an integer.
			if ( ! is_int( $post_id ) ) {
				continue;
			}

			// delete it.
			wp_delete_post( $post_id, true );

			// show progress.
			$progress->tick();
		}

		// finish the progress.
		$progress->finish();

		// reset the deleted options.
		delete_option( Entries::OPTION_DELETED );
		// the hook on deleting the option does not fire if it was never saved: forget the pending ones, too.
		Entries::get_instance()->forget_pending_deletions();
		delete_option( Entries::OPTION_CLEANED_BEFORE );
	}
}
