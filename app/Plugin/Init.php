<?php
/**
 * This file contains the main initialization object for this plugin.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Dependencies\easyTransientsForWordPress\Transients;
use NowPagesThreadi\Digests\Diagnostics;
use NowPagesThreadi\Digests\Digests;
use NowPagesThreadi\Digests\Summarizer_Stats;
use NowPagesThreadi\Digests\Summarizers;
use NowPagesThreadi\Digests\Summarizers\Ai_Summarizer;
use NowPagesThreadi\Fediverse\Fediverse;
use NowPagesThreadi\NowPages\Abilities;
use NowPagesThreadi\NowPages\Categories;
use NowPagesThreadi\NowPages\Cleanup;
use NowPagesThreadi\NowPages\Confirmation;
use NowPagesThreadi\NowPages\Content_Filters;
use NowPagesThreadi\NowPages\Frontend;
use NowPagesThreadi\NowPages\Now_Page;
use NowPagesThreadi\PageBuilder\Page_Builders;
use NowPagesThreadi\Plugin\Admin\Admin;
use NowPagesThreadi\Presets\Presets;
use NowPagesThreadi\Providers\Providers;
use NowPagesThreadi\Sources\Advisor;
use NowPagesThreadi\Sources\Availability;
use WP_Post;
use WP_Post_Type;

/**
 * Initialize the plugin, connect all together.
 */
class Init {

	/**
	 * Instance of actual object.
	 *
	 * @var ?Init
	 */
	private static ?Init $instance = null;

	/**
	 * Marker whether the caches have already been cleaned after a change of a post in this request.
	 *
	 * @var bool
	 */
	private bool $option_caches_cleaned_for_post = false;

	/**
	 * Constructor, not used as this a Singleton object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return instance of this object as singleton.
	 *
	 * @return Init
	 */
	public static function get_instance(): Init {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Initialize this object.
	 *
	 * @return void
	 */
	public function init(): void {
		// prepare the roles.
		Capabilities::get_instance()->init();

		// initialize the settings.
		Settings::get_instance()->init();
		ProcessHandler::get_instance()->init();

		// initialize our custom post types.
		Post_Types::get_instance()->init();

		// initialize our custom taxonomy.
		Categories::get_instance()->init();

		// initialize the content filter.
		Content_Filters::get_instance()->init();

		// initialize the abilities for AI assistants and automation.
		Abilities::get_instance()->init();

		// initialize the providers on init, so other plugins and the theme could register their own.
		$this->run_on_init( array( Providers::get_instance(), 'init' ) );
		$this->run_on_init( array( Presets::get_instance(), 'init' ) );

		// initialize the digest summarizers, formats amd periods (also on init for the same reason).
		$this->run_on_init( array( Summarizers::get_instance(), 'init' ) );
		$this->run_on_init( array( Digests::get_instance(), 'init' ) );
		Summarizer_Stats::get_instance()->init();

		// initialize the support for different page builders.
		Page_Builders::get_instance()->init();

		// initialize the admin support.
		Admin::get_instance()->init();
		Now_Page::get_instance()->init();

		// initialize the setup.
		Setup::get_instance()->init();

		// initialize the user support.
		User_Settings::get_instance()->init();

		// initialize the scheduler.
		Intervals::get_instance()->init();
		Schedules::get_instance()->init();

		// initialize the output in frontend.
		Frontend::get_instance()->init();
		Diagnostics::get_instance()->init();

		// initialize emails.
		Emails::get_instance()->init();

		// initialize the knowledge base.
		KnowledgeCenter::get_instance()->init();

		// initialize the intro.
		Intro::get_instance()->init();

		// initialize the availability check.
		Availability::get_instance()->init();

		// initialize the advisor for new sources.
		Advisor::get_instance()->init();

		// initialize the sharing in the Fediverse.
		Fediverse::get_instance()->init();

		// initialize the confirmation of entries which wait for the user.
		Confirmation::get_instance()->init();

		// initialize the clean up.
		Cleanup::get_instance()->init();

		// initialize the privacy tools.
		Privacy::get_instance()->init();

		// register our cli handler, also if WP-CLI fired its hook before this plugin has been initialized.
		if ( did_action( 'cli_init' ) > 0 ) {
			$this->cli();
		} else {
			add_action( 'cli_init', array( $this, 'cli' ) );
		}

		// use hooks.
		add_action( 'admin_init', array( Log::get_instance(), 'maybe_update_table' ) );
		add_action( 'wp_initialize_site', array( Activation::get_instance(), 'initialize_site' ), 200 );

		// remember the display settings for the AI summarizer and clean its cache.
		add_filter( 'now_pages_threadi_digest_query_args', array( Ai_Summarizer::class, 'remember_digest_args' ), 10, 2 );
		add_action( 'before_delete_post', array( Ai_Summarizer::class, 'delete_cached_summaries' ), 10, 2 );

		// clean up things.
		add_action( 'save_post', array( $this, 'delete_option_caches_for_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'delete_option_caches_for_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'delete_option_caches_for_post' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'delete_option_caches_for_post' ), 10, 1 );
		add_action( 'user_register', array( $this, 'delete_option_caches' ), 10, 0 );
		add_action( 'profile_update', array( $this, 'delete_option_caches' ), 10, 0 );
		add_action( 'deleted_user', array( $this, 'delete_option_caches' ), 10, 0 );
		add_action( 'set_user_role', array( $this, 'delete_option_caches' ), 10, 0 );
		add_action( 'permalink_structure_changed', array( $this, 'delete_option_caches' ), 10, 0 );
	}

	/**
	 * Run the given callback on init or directly, if init has already been fired.
	 *
	 * The registries announce themselves via our public hooks. Running them on init
	 * allows plugins loaded after this one and the theme to use these hooks.
	 *
	 * @param callable $callback The callback to run.
	 *
	 * @return void
	 */
	private function run_on_init( callable $callback ): void {
		// run it directly, if init has already been fired (e.g. during the activation of this plugin).
		if ( did_action( 'init' ) > 0 ) {
			$callback();
			return;
		}

		// otherwise run it as early as possible on init, so its own hooks on init are still used.
		add_action( 'init', $callback, 0 );
	}

	/**
	 * Register our cli handler.
	 *
	 * @return void
	 */
	public function cli(): void {
		\WP_CLI::add_command( 'now-pages-threadi', 'NowPagesThreadi\Plugin\Cli' );
	}

	/**
	 * Install db-tables of registered objects.
	 *
	 * Hint: the objects must just have a function "create_table".
	 *
	 * @return void
	 */
	public function install_db_tables(): void {
		$objects = array(
			'\NowPagesThreadi\Plugin\Log',
		);
		/**
		 * Add additional objects for this plugin, which use custom tables.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param array<int,string> $objects List of objects.
		 */
		foreach ( apply_filters( 'now_pages_threadi_objects_with_db_tables', $objects ) as $obj_name ) {
			// bail if class does not exist.
			if ( ! class_exists( $obj_name ) ) {
				continue;
			}

			// get the object.
			$obj = new $obj_name();

			// bail if the object does not have a function "create_table".
			if ( ! method_exists( $obj, 'create_table' ) ) {
				continue;
			}

			// call the function to create its table(s).
			$obj->create_table();
		}
	}

	/**
	 * Clean up the caches we use after a post has been changed.
	 *
	 * Our entries and sources are not part of the cached pages and slug checks,
	 * so they only clear the cache of sources with entries. This prevents the
	 * cleanup from running on every imported entry. For other posts it runs at
	 * most once per request.
	 *
	 * @param int   $post_id The ID of the changed post.
	 * @param mixed $post    The post object, if WordPress passes it (not for trash actions).
	 *
	 * @return void
	 */
	public function delete_option_caches_for_post( int $post_id, mixed $post = null ): void {
		// use the given post object: after a deletion the post can not be loaded anymore.
		$post_type = $post instanceof WP_Post ? $post->post_type : (string) get_post_type( $post_id );

		// bail for revisions and autosaves.
		if ( 'revision' === $post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// bail for post types, which could not occupy the slug of our now page (like menu items or templates).
		$post_type_obj = get_post_type_object( $post_type );
		if ( 'page' !== $post_type && ( ! $post_type_obj instanceof WP_Post_Type || $post_type_obj->exclude_from_search ) ) {
			return;
		}

		// bail if the caches have already been cleaned for a post in this request, e.g. during a bulk edit.
		if ( $this->option_caches_cleaned_for_post ) {
			return;
		}
		$this->option_caches_cleaned_for_post = true;

		// clean up all caches, e.g. a page which could use the slug of our now page.
		$this->delete_option_caches();
	}

	/**
	 * Clean up some transients we use.
	 *
	 * @return void
	 */
	public function delete_option_caches(): void {
		delete_transient( 'now_pages_threadi_wp_users' );
		delete_transient( 'now_pages_threadi_wp_pages' );
		delete_transient( 'now_pages_threadi_slug_conflict_' . md5( (string) get_option( 'now_pages_threadi_slug', 'now' ) ) );
		Transients::get_instance()->get_transient_by_name( 'now_pages_threadi_slug_warning' )->delete();
	}

	/**
	 * Register the hooks which must exist before "plugins_loaded".
	 *
	 * @return void
	 */
	public function early_init(): void {
		Page_Builders::get_instance()->early_init();
	}
}
