<?php
/**
 * This file contains the context a provider is called with.
 *
 * Carries everything a provider needs to know about the source it is
 * running for, plus a small state store for cursors, ETags and similar
 * values it wants to remember between two runs.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Providers;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object which carries the context of a single provider run.
 */
class Provider_Context {
	/**
	 * The post meta key the state is stored under.
	 *
	 * @var string
	 */
	private const STATE_META = 'threadi_now_pages_provider_state';

	/**
	 * The post meta key the timestamp of the last run is stored under.
	 *
	 * @var string
	 */
	private const LAST_RUN_META = 'threadi_now_pages_provider_last_run';

	/**
	 * The post meta key the timestamp of the last started run is stored under.
	 *
	 * @var string
	 */
	private const LAST_ATTEMPT_META = 'threadi_now_pages_provider_last_attempt';

	/**
	 * The prefix of state keys which hold ETags.
	 *
	 * @var string
	 */
	public const ETAG_PREFIX = 'etag_';

	/**
	 * The ID of the source this run belongs to.
	 *
	 * @var int
	 */
	private int $source_id;

	/**
	 * Values which are stored only when this run completes.
	 *
	 * @var array<string,mixed>
	 */
	private array $pending_state = array();

	/**
	 * Constructor.
	 *
	 * @param int $source_id The ID of the source (0 for a run without one).
	 */
	public function __construct( int $source_id ) {
		$this->source_id = $source_id;
	}

	/**
	 * Return the ID of the source this run belongs to.
	 *
	 * @return int Zero if the provider runs without a source.
	 */
	public function get_source_id(): int {
		return $this->source_id;
	}

	/**
	 * Return a value the provider stored during an earlier run.
	 *
	 * Use this for cursors, ETags, "highest ID seen so far" and similar -
	 * everything that makes the next run cheaper or incremental.
	 *
	 * @param string $key     The key, scoped to this source.
	 * @param mixed  $default_value The value to return if nothing is stored.
	 *
	 * @return mixed
	 */
	public function get_state( string $key, mixed $default_value = null ): mixed {
		// bail if this run has no source to store anything on.
		if ( 0 === $this->source_id ) {
			return $default_value;
		}

		$state = get_post_meta( $this->source_id, self::STATE_META, true );

		if ( ! is_array( $state ) || ! array_key_exists( $key, $state ) ) {
			return $default_value;
		}

		return $state[ $key ];
	}

	/**
	 * Store a value for the next run of this provider.
	 *
	 * Values must be serializable - do not store objects or closures.
	 *
	 * @param string $key   The key, scoped to this source.
	 * @param mixed  $value The value to store.
	 *
	 * @return void
	 */
	public function set_state( string $key, mixed $value ): void {
		// bail if this run has no source to store anything on.
		if ( 0 === $this->source_id ) {
			return;
		}

		$state = get_post_meta( $this->source_id, self::STATE_META, true );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state[ $key ] = $value;

		update_post_meta( $this->source_id, self::STATE_META, $state );
	}

	/**
	 * Remember a value which is stored only when this run completes.
	 *
	 * Use this for values like ETags, which must not be stored before the
	 * entries of this run have been saved: otherwise an aborted run would
	 * lose these entries forever.
	 *
	 * @param string $key   The key, scoped to this source.
	 * @param mixed  $value The value to store.
	 *
	 * @return void
	 */
	public function set_pending_state( string $key, mixed $value ): void {
		$this->pending_state[ $key ] = $value;
	}

	/**
	 * Store the pending values of this run.
	 *
	 * ETags which have not been used during this run are removed, so the
	 * state cannot grow with every changing URL.
	 *
	 * @return void
	 */
	public function commit_pending_state(): void {
		// bail if this run has no source to store anything on.
		if ( 0 === $this->source_id ) {
			return;
		}

		$state = get_post_meta( $this->source_id, self::STATE_META, true );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$original = $state;

		// remove the ETags which have not been used during this run.
		foreach ( array_keys( $state ) as $key ) {
			if ( str_starts_with( (string) $key, self::ETAG_PREFIX ) && ! array_key_exists( $key, $this->pending_state ) ) {
				unset( $state[ $key ] );
			}
		}

		// add the pending values.
		$state = array_merge( $state, $this->pending_state );

		$this->pending_state = array();

		// bail if nothing changed.
		if ( $state === $original ) {
			return;
		}

		update_post_meta( $this->source_id, self::STATE_META, $state );
	}

	/**
	 * Remove a stored value.
	 *
	 * @param string $key The key, scoped to this source.
	 *
	 * @return void
	 */
	public function delete_state( string $key ): void {
		// bail if this run has no source to store anything on.
		if ( 0 === $this->source_id ) {
			return;
		}

		$state = get_post_meta( $this->source_id, self::STATE_META, true );

		if ( ! is_array( $state ) || ! array_key_exists( $key, $state ) ) {
			return;
		}

		unset( $state[ $key ] );

		update_post_meta( $this->source_id, self::STATE_META, $state );
	}

	/**
	 * Return the GMT timestamp of the last completed run of this source.
	 *
	 * @return int Zero if the source never ran.
	 */
	public function get_last_run(): int {
		// bail if this run has no source.
		if ( 0 === $this->source_id ) {
			return 0;
		}

		return absint( get_post_meta( $this->source_id, self::LAST_RUN_META, true ) );
	}

	/**
	 * Return the GMT timestamp of the last started run of this source.
	 *
	 * A run which crashed the PHP process has an attempt, but no completed run.
	 *
	 * @return int Zero if the source never started a run.
	 */
	public function get_last_attempt(): int {
		// bail if this run has no source.
		if ( 0 === $this->source_id ) {
			return 0;
		}

		return absint( get_post_meta( $this->source_id, self::LAST_ATTEMPT_META, true ) );
	}

	/**
	 * Remember that a run of this source is starting.
	 *
	 * Called by Sources::import_source() before the provider fetches anything, so a
	 * source which crashes the PHP process is not the first one again on the
	 * next import.
	 *
	 * @return void
	 */
	public function mark_as_attempted(): void {
		// bail if this run has no source.
		if ( 0 === $this->source_id ) {
			return;
		}

		update_post_meta( $this->source_id, self::LAST_ATTEMPT_META, time() );
	}

	/**
	 * Remember that this source just ran.
	 *
	 * Called by Sources::import() after the entries of the provider have
	 * been saved, not by the provider itself. Stores the pending values of
	 * this run, too.
	 *
	 * @return void
	 */
	public function mark_as_run(): void {
		// bail if this run has no source.
		if ( 0 === $this->source_id ) {
			return;
		}

		// store the pending values of this run.
		$this->commit_pending_state();

		update_post_meta( $this->source_id, self::LAST_RUN_META, time() );
	}
}
