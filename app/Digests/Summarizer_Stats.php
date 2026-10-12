<?php
/**
 * This file contains the statistics about summarizer requests.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Digests;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Object, which collects and shows statistics about summarizer requests.
 */
class Summarizer_Stats {
	/**
	 * The option holding the aggregated numbers.
	 *
	 * @var string
	 */
	public const OPTION = 'threadi_now_pages_summarizer_stats';

	/**
	 * Number of days to keep.
	 *
	 * @var int
	 */
	private const KEEP_DAYS = 90;

	/**
	 * Transient which limits how often requests served from cache are saved.
	 *
	 * @var string
	 */
	private const CACHE_HITS_THROTTLE = 'threadi_now_pages_summarizer_stats_saved';

	/**
	 * Minimal interval between two saves, which only contain requests served from cache, in seconds.
	 *
	 * @var int
	 */
	private const CACHE_HITS_INTERVAL = 5 * MINUTE_IN_SECONDS; // 300 seconds.

	/**
	 * The requests of this page request, which are not saved yet.
	 *
	 * Map of summarizer slug => numbers.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $pending = array();

	/**
	 * Whether the pending requests contain at least one real request to the summarizer.
	 *
	 * @var bool
	 */
	private bool $pending_has_calls = false;

	/**
	 * Instance of actual object.
	 *
	 * @var ?Summarizer_Stats
	 */
	private static ?Summarizer_Stats $instance = null;

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
	 * @return Summarizer_Stats
	 */
	public static function get_instance(): Summarizer_Stats {
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
		// use hooks.
		add_action( 'threadi_now_pages_summarizer_request', array( $this, 'record' ) );
	}

	/**
	 * Record a single summarizer request.
	 *
	 * The numbers are collected during the request and saved once at its end,
	 * see save(). This prevents a database write for every summary.
	 *
	 * @param array<string,mixed> $data The request data, see the action docblock in Ai_Summarizer.
	 *
	 * @return void
	 */
	public function record( array $data ): void {
		$slug = (string) ( $data['summarizer'] ?? 'unknown' );

		// save the numbers at the end of this request.
		if ( empty( $this->pending ) ) {
			add_action( 'shutdown', array( $this, 'save' ) );
		}

		// make sure the bucket exists.
		if ( ! isset( $this->pending[ $slug ] ) ) {
			$this->pending[ $slug ] = $this->get_empty_bucket();
		}

		// a cache hit only counts, it has no timing or volume.
		if ( 'cache' === ( $data['source'] ?? '' ) ) {
			++$this->pending[ $slug ]['cached'];
			return;
		}

		++$this->pending[ $slug ]['calls'];
		$this->pending_has_calls = true;

		if ( empty( $data['success'] ) ) {
			++$this->pending[ $slug ]['errors'];
		}

		$this->pending[ $slug ]['chars_in']  += absint( $data['chars_in'] ?? 0 );
		$this->pending[ $slug ]['chars_out'] += absint( $data['chars_out'] ?? 0 );
		$this->pending[ $slug ]['ms']        += absint( $data['duration'] ?? 0 );
	}

	/**
	 * Save the numbers collected during this request.
	 *
	 * Requests which were only served from cache are saved at most every
	 * CACHE_HITS_INTERVAL seconds, so a page view does not write to the
	 * database. Cache hits in between are therefore counted approximately.
	 *
	 * @return void
	 */
	public function save(): void {
		// bail if nothing has been recorded.
		if ( empty( $this->pending ) ) {
			return;
		}

		// get the pending numbers and reset them.
		$pending                 = $this->pending;
		$has_calls               = $this->pending_has_calls;
		$this->pending           = array();
		$this->pending_has_calls = false;

		// bail if only cache hits were recorded and the last save is not long enough ago.
		if ( ! $has_calls && false !== get_transient( self::CACHE_HITS_THROTTLE ) ) {
			return;
		}
		set_transient( self::CACHE_HITS_THROTTLE, 1, self::CACHE_HITS_INTERVAL );

		// read the actual value from the database, not from the cache of this request, to reduce lost updates.
		wp_cache_delete( self::OPTION, 'options' );
		$stats = $this->get_stats();
		$day   = current_time( 'Y-m-d' );

		// add the pending numbers.
		foreach ( $pending as $slug => $numbers ) {
			// make sure the bucket exists.
			if ( ! isset( $stats[ $day ][ $slug ] ) ) {
				$stats[ $day ][ $slug ] = $this->get_empty_bucket();
			}

			foreach ( $numbers as $key => $value ) {
				$stats[ $day ][ $slug ][ $key ] = absint( $stats[ $day ][ $slug ][ $key ] ?? 0 ) + $value;
			}
		}

		// store without autoload, this is only read on the settings page.
		update_option( self::OPTION, $this->prune( $stats ), false );
	}

	/**
	 * Return an empty bucket for the numbers of one summarizer.
	 *
	 * @return array<string,int>
	 */
	private function get_empty_bucket(): array {
		return array(
			'calls'     => 0,
			'cached'    => 0,
			'errors'    => 0,
			'chars_in'  => 0,
			'chars_out' => 0,
			'ms'        => 0,
		);
	}

	/**
	 * Return the collected statistics.
	 *
	 * @return array<string,array<string,array<string,int>>> Map of day => summarizer => numbers.
	 */
	public function get_stats(): array {
		$stats = get_option( self::OPTION, array() );

		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Show the statistics on the settings page.
	 *
	 * @return void
	 */
	public function show(): void {
		$stats = $this->get_stats();

		// show a hint if nothing has been recorded yet.
		if ( empty( $stats ) ) {
			echo '<p>' . esc_html__( 'No summarizer requests recorded yet.', 'threadi-now-pages' ) . '</p>';

			return;
		}

		// newest day first.
		krsort( $stats );

		?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Day', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'Summarizer', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'Requests', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'From cache', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'Errors', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'Ø duration', 'threadi-now-pages' ); ?></th>
				<th><?php esc_html_e( 'Characters in / out', 'threadi-now-pages' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ( array_slice( $stats, 0, 30, true ) as $day => $summarizers ) {
				foreach ( $summarizers as $slug => $numbers ) {
					$average = $numbers['calls'] > 0 ? (int) round( $numbers['ms'] / $numbers['calls'] ) : 0;
					?>
					<tr>
						<td><?php echo esc_html( (string) $day ); ?></td>
						<td><?php echo esc_html( (string) $slug ); ?></td>
						<td><?php echo esc_html( (string) $numbers['calls'] ); ?></td>
						<td><?php echo esc_html( (string) $numbers['cached'] ); ?></td>
						<td><?php echo esc_html( (string) $numbers['errors'] ); ?></td>
						<td><?php echo esc_html( $average > 0 ? $average . ' ms' : '–' ); ?></td>
						<td><?php echo esc_html( $numbers['chars_in'] . ' / ' . $numbers['chars_out'] ); ?></td>
					</tr>
					<?php
				}
			}
			?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Character counts are a rough volume indicator, not a token count - they are not comparable across models.', 'threadi-now-pages' ); ?>
			<?php esc_html_e( 'Requests served from cache are counted approximately, to prevent a database write on every page view.', 'threadi-now-pages' ); ?>
		</p>
		<?php
	}

	/**
	 * Drop everything older than KEEP_DAYS.
	 *
	 * @param array<string,array<string,array<string,int>>> $stats The statistics.
	 *
	 * @return array<string,array<string,array<string,int>>>
	 */
	private function prune( array $stats ): array {
		// newest day first, then cut.
		krsort( $stats );

		return array_slice( $stats, 0, self::KEEP_DAYS, true );
	}
}
