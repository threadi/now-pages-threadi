<?php
/**
 * This file contains the availability check for sources.
 *
 * @package now-pages-threadi
 */

declare(strict_types=1);

namespace NowPagesThreadi\Sources;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use NowPagesThreadi\Helper\Plugin;
use NowPagesThreadi\Helper\Singleton_Interface;
use NowPagesThreadi\Helper\Singleton_Trait;
use NowPagesThreadi\Helper\Url;
use NowPagesThreadi\Providers\Provider_Interface;
use NowPagesThreadi\Providers\Providers;
use TcpAnalyzer\TcpAnalyzer;
use WP_Post;

/**
 * Object to check whether the service behind a source can be reached.
 */
class Availability implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * How long a result is kept, in seconds.
	 *
	 * Short on purpose: the point of the check is the moment it runs. It is
	 * cached at all so closing and reopening the dialog does not mean
	 * waiting through every timeout a second time.
	 *
	 * @var int
	 */
	private const CACHE_TIME = 300;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_action( 'wp_ajax_now_pages_threadi_check_source', array( $this, 'check_source_via_ajax' ) );
	}

	/**
	 * Add the script which runs the check from the sources list.
	 *
	 * @param string $hook The requested hook.
	 *
	 * @return void
	 */
	public function add_scripts( string $hook ): void {
		// bail on any other page than a list table.
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// bail if this is not the list of our sources.
		if ( Sources::get_instance()->get_slug() !== get_query_var( 'post_type' ) ) {
			return;
		}

		// embed our own JS script.
		wp_enqueue_script(
			'now-pages-threadi-check-source',
			Plugin::get_plugin_url() . 'admin/check-source.js',
			array( 'easy-dialog-for-wordpress' ),
			Plugin::get_file_version( Plugin::get_plugin_path() . 'admin/check-source.js' ),
			true
		);

		// add php-vars to our js-script.
		wp_localize_script(
			'now-pages-threadi-check-source',
			'nowPagesThreadiCheckVars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'now-pages-threadi-check-source' ),
				'title'    => __( 'Availability', 'now-pages-threadi' ),
				'running'  => __( 'Checking whether your website can reach this service …', 'now-pages-threadi' ),
				'cancel'   => __( 'Cancel', 'now-pages-threadi' ),
				'ok'       => __( 'OK', 'now-pages-threadi' ),
				'error'    => __( 'The check could not be run. Please try again.', 'now-pages-threadi' ),
			)
		);
	}

	/**
	 * Run the check for one source and answer with the dialog to show.
	 *
	 * @return void
	 */
	public function check_source_via_ajax(): void {
		// check the nonce.
		check_ajax_referer( 'now-pages-threadi-check-source', 'nonce' );

		// get the requested source.
		$source_id = absint( filter_input( INPUT_POST, 'source', FILTER_SANITIZE_NUMBER_INT ) );

		// bail if the user may not see this source.
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->get_dialog_for_source( $source_id ) );
	}

	/**
	 * Return the dialog showing the result for one source.
	 *
	 * @param int $source_id The source to check.
	 *
	 * @return array<string,mixed>
	 */
	public function get_dialog_for_source( int $source_id ): array {
		$post = get_post( $source_id );

		// bail if the source is gone.
		if ( ! $post instanceof WP_Post || Sources::get_instance()->get_slug() !== $post->post_type ) {
			return $this->get_dialog( array( '<p>' . esc_html__( 'This source could not be found.', 'now-pages-threadi' ) . '</p>' ) );
		}

		$url = $this->get_url_for_source( $source_id );

		// bail if this source fetches nothing: a local provider or one that
		// receives its entries has no service to reach.
		if ( '' === $url ) {
			return $this->get_dialog(
				array(
					'<p>' . esc_html__( 'This source does not call any external service, so there is nothing to check.', 'now-pages-threadi' ) . '</p>',
				)
			);
		}

		// calendars are often given as webcal://, which is fetched via https.
		$url = (string) preg_replace( '#^webcal://#i', 'https://', $url );

		// bail if the URL points to a local or private network: we do not
		// probe such hosts (this would allow scanning the internal network).
		if ( ! Url::is_safe_remote( $url ) ) {
			return $this->get_dialog(
				array(
					'<p>' . esc_html__( 'This address points to a local or private network or uses a port that is not allowed. For security reasons it is neither checked nor imported.', 'now-pages-threadi' ) . '</p>',
				)
			);
		}

		// get the results, either from the cache or by running the check.
		$results = $this->get_results( $source_id, $url );

		return $this->get_dialog( $this->get_texts( $url, $results ) );
	}

	/**
	 * Return the URL the provider of a source would call.
	 *
	 * @param int $source_id The source.
	 *
	 * @return string Empty string if this source calls nothing.
	 */
	private function get_url_for_source( int $source_id ): string {
		// read the source itself: a draft is not one of the configured sources, but can be checked as well.
		$provider_obj = Providers::get_instance()->get_provider( (string) get_post_meta( $source_id, 'provider', true ) );

		// bail if the provider of this source does not exist.
		if ( ! $provider_obj instanceof Provider_Interface ) {
			return '';
		}

		$config = Sources::get_instance()->get_source_config( $source_id, $provider_obj );

		// bail if the source has no configuration.
		if ( null === $config ) {
			return '';
		}

		return $provider_obj->get_test_url( $config );
	}

	/**
	 * Return the results for one source, running the check if necessary.
	 *
	 * @param int    $source_id The source.
	 * @param string $url       The URL to check.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_results( int $source_id, string $url ): array {
		$cache_key = 'now_pages_threadi_availability_' . $source_id;
		$cached    = get_transient( $cache_key );

		// return a cached result, if there is one.
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$results = $this->run_check( $url );

		set_transient( $cache_key, $results, self::CACHE_TIME );

		return $results;
	}

	/**
	 * Run the checks against one URL.
	 *
	 * Traceroute is left out on purpose: it needs shell_exec or raw sockets,
	 * which most hosting providers disable, and its result would be noise in
	 * a dialog meant to answer one question.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function run_check( string $url ): array {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// bail if the URL carries no host.
		if ( '' === $host ) {
			return array();
		}

		$port = absint( wp_parse_url( $url, PHP_URL_PORT ) );
		if ( 0 === $port ) {
			$port = 'http' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443;
		}

		// resolve the host once and connect to exactly this address: resolving it
		// again in the analyzer would allow DNS rebinding to an internal address.
		$ip = $this->resolve_public_ip( $host );

		// bail if the host resolves to a local or private address.
		if ( false === $ip ) {
			return array(
				'dns' => array(
					'slug'        => 'dns',
					'status'      => 'error',
					'value'       => null,
					'data'        => array(),
					'error_code'  => 'private_address',
					'duration_ms' => null,
				),
			);
		}

		// the HTTP test is run by us (see below) and not by the analyzer: the
		// analyzer follows redirects without checking their target.
		$tests = array(
			'dns' => array( 'host' => $host ),
		);

		// connect only if the host could be resolved.
		if ( '' !== $ip ) {
			$tests['tcp_connect'] = array(
				// an IPv6 address needs brackets in a socket address.
				'host' => str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip,
				'port' => $port,
			);
		}

		/**
		 * Filter the tests run against a source.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,array<string,mixed>> $tests The tests with their configuration.
		 * @param string                            $url   The URL being checked.
		 */
		$tests = apply_filters( 'now_pages_threadi_availability_tests', $tests, $url );

		$tcp_analyzer = new TcpAnalyzer();
		$tcp_analyzer->set_tests( $tests );
		$tcp_analyzer->run();

		$results = $tcp_analyzer->get_results_as_array();

		// run the HTTP test with the safe WordPress API, which checks every redirect target.
		if ( '' !== $ip && ! isset( $results['http'] ) ) {
			$results['http'] = $this->run_http_test( $url );
		}

		return $results;
	}

	/**
	 * Return the public IP address of a host.
	 *
	 * @param string $host The host name or IP address.
	 *
	 * @return string|false The IP address, an empty string if the host could not be resolved,
	 *                      false if it resolves to a local or private address.
	 */
	private function resolve_public_ip( string $host ): string|false {
		// use the host itself, if it is an IP address.
		$ips = false !== filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );

		// try IPv6 for hosts without IPv4 address.
		if ( ! is_array( $ips ) && function_exists( 'dns_get_record' ) ) {
			$ips     = array();
			$records = dns_get_record( $host, DNS_AAAA );
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}

		// bail if the host could not be resolved: the DNS test will explain this.
		if ( ! is_array( $ips ) || empty( $ips ) ) {
			return '';
		}

		// bail if any of the addresses is local or private.
		foreach ( $ips as $ip ) {
			// also treat the shared address space of carrier-grade NAT (100.64.0.0/10) as internal.
			$is_shared = 1 === preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', (string) $ip );

			if ( $is_shared || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				/** This filter is documented in wp-includes/http.php */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of WordPress core: the check has to honor the same exceptions as the HTTP API itself.
				if ( ! apply_filters( 'http_request_host_is_external', false, $host, '' ) ) {
					return false;
				}
			}
		}

		return (string) $ips[0];
	}

	/**
	 * Run the HTTP test via wp_safe_remote_get().
	 *
	 * @param string $url The URL to request.
	 *
	 * @return array<string,mixed> The result in the format of the analyzer.
	 */
	private function run_http_test( string $url ): array {
		$started  = microtime( true );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 5,
				'limit_response_size' => 64 * KB_IN_BYTES,
			)
		);
		$duration = ( microtime( true ) - $started ) * 1000;

		// the request failed.
		if ( is_wp_error( $response ) ) {
			return array(
				'slug'        => 'http',
				'status'      => 'error',
				'value'       => null,
				'data'        => array(),
				'error_code'  => 'http_request_failed',
				'duration_ms' => $duration,
			);
		}

		$code       = absint( wp_remote_retrieve_response_code( $response ) );
		$is_success = $code >= 200 && $code < 400;

		return array(
			'slug'        => 'http',
			'status'      => $is_success ? 'success' : 'warning',
			'value'       => $code,
			'data'        => array(),
			'error_code'  => $is_success ? null : 'http_status_not_ok',
			'duration_ms' => $duration,
		);
	}

	/**
	 * Return the texts describing the results.
	 *
	 * @param string                            $url     The URL which was checked.
	 * @param array<string,array<string,mixed>> $results The results of the analyzer.
	 *
	 * @return array<int,string>
	 */
	private function get_texts( string $url, array $results ): array {
		// bail if nothing could be checked.
		if ( empty( $results ) ) {
			return array( '<p>' . esc_html__( 'This source has no usable address, so nothing could be checked.', 'now-pages-threadi' ) . '</p>' );
		}

		$texts = array(
			sprintf(
				/* translators: %1$s will be replaced by a URL. */
				'<p>' . esc_html__( 'Checked: %1$s', 'now-pages-threadi' ) . '</p>',
				'<code>' . esc_html( Url::redact( $url ) ) . '</code>'
			),
		);

		// collect the failures first: they are the reason somebody opened
		// this dialog, and the explanation is what actually helps.
		$problems = array();

		foreach ( $results as $result ) {
			$status = (string) ( $result['status'] ?? '' );

			if ( 'success' === $status ) {
				continue;
			}

			$error_code = (string) ( $result['error_code'] ?? '' );
			$text       = $this->get_error_text( $error_code );

			if ( '' === $text ) {
				continue;
			}

			$problems[] = $text;
		}

		if ( empty( $problems ) ) {
			$texts[] = '<p><strong>' . esc_html__( 'Your website can reach this service.', 'now-pages-threadi' ) . '</strong></p>';
		} else {
			$texts[] = '<p><strong>' . esc_html__( 'Your website cannot reach this service.', 'now-pages-threadi' ) . '</strong></p>';

			foreach ( $problems as $problem ) {
				$texts[] = '<p>' . wp_kses_post( $problem ) . '</p>';
			}
		}

		$texts[] = $this->get_details( $results );

		return $texts;
	}

	/**
	 * Return the raw results as a list, for a support request.
	 *
	 * @param array<string,array<string,mixed>> $results The results of the analyzer.
	 *
	 * @return string
	 */
	private function get_details( array $results ): string {
		$rows = '';

		foreach ( $results as $slug => $result ) {
			$duration = $result['duration_ms'] ?? null;

			$rows .= sprintf(
				'<li><code>%1$s</code>: %2$s%3$s</li>',
				esc_html( (string) $slug ),
				esc_html( (string) ( $result['status'] ?? '' ) ),
				is_numeric( $duration ) ? esc_html( ' (' . number_format_i18n( (float) $duration, 1 ) . ' ms)' ) : ''
			);
		}

		return '<details><summary>' . esc_html__( 'Details', 'now-pages-threadi' ) . '</summary><ul>' . $rows . '</ul></details>';
	}

	/**
	 * Return the dialog around the given texts.
	 *
	 * @param array<int,string> $texts The texts to show.
	 *
	 * @return array<string,mixed>
	 */
	private function get_dialog( array $texts ): array {
		return array(
			'title'   => __( 'Availability', 'now-pages-threadi' ),
			'texts'   => $texts,
			'buttons' => array(
				array(
					'action'  => 'closeDialog();',
					'variant' => 'primary',
					'text'    => __( 'OK', 'now-pages-threadi' ),
				),
			),
		);
	}

	/**
	 * Return the text explaining one error code of the analyzer.
	 *
	 * The package returns stable, language-free codes on purpose and leaves
	 * the wording to us. This is where the value of the whole check sits:
	 * not in saying that something failed, but in saying what to do next.
	 *
	 * @param string $error_code The code the analyzer returned.
	 *
	 * @return string Empty string for codes not worth showing.
	 */
	private function get_error_text( string $error_code ): string {
		$texts = array(
			'dns_resolution_failed'   => __( 'The address of this service could not be resolved. Check the address for a typo, and ask your hosting provider whether name resolution works on this server.', 'now-pages-threadi' ),
			'tcp_connect_timeout'     => __( 'The service did not answer within the time limit. This usually means your hosting provider blocks outgoing connections, or the service is unreachable right now.', 'now-pages-threadi' ),
			'tcp_connect_refused'     => __( 'The service refused the connection right away. It may be down, or it may not accept connections on this port.', 'now-pages-threadi' ),
			'http_request_failed'     => __( 'The request to this service failed. Ask your hosting provider whether outgoing HTTP requests are allowed.', 'now-pages-threadi' ),
			'http_status_not_ok'      => __( 'The service answered, but not with a usable response. Check whether the address is still correct - a feed or an account may have moved.', 'now-pages-threadi' ),
			'request_failed'          => __( 'The request to this service failed. Ask your hosting provider whether outgoing HTTP requests are allowed.', 'now-pages-threadi' ),
			'timeout'                 => __( 'The service did not answer within the time limit.', 'now-pages-threadi' ),
			'sockets_unavailable'     => __( 'This website cannot open network connections, so the service cannot be reached. Ask your hosting provider about it.', 'now-pages-threadi' ),
			'missing_config'          => __( 'This source is not configured completely, so there was nothing to check.', 'now-pages-threadi' ),
			'invalid_config'          => __( 'The address configured on this source cannot be used.', 'now-pages-threadi' ),
			'external_ip_unavailable' => __( 'The outgoing IP address of this website could not be determined.', 'now-pages-threadi' ),
			'private_address'         => __( 'This address points to a local or private network. For security reasons it is neither checked nor imported.', 'now-pages-threadi' ),
		);

		/**
		 * Filter the texts explaining the error codes of the analyzer.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param array<string,string> $texts      The texts, keyed by error code.
		 * @param string               $error_code The code being explained.
		 */
		$texts = apply_filters( 'now_pages_threadi_availability_error_texts', $texts, $error_code );

		// a curl error carries it's number, which is of no use to the user.
		if ( str_starts_with( $error_code, 'curl_errno_' ) ) {
			return $texts['request_failed'];
		}

		// these say something about this server, not about the service -
		// showing them would only distract from the actual answer.
		if ( in_array( $error_code, array( 'shell_exec_disabled', 'shell_exec_unavailable', 'traceroute_unavailable' ), true ) ) {
			return '';
		}

		if ( ! isset( $texts[ $error_code ] ) ) {
			return __( 'The service could not be reached, and the reason is unclear. The details below may help in a support request.', 'now-pages-threadi' );
		}

		return $texts[ $error_code ];
	}
}
