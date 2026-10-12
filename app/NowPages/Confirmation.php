<?php
/**
 * This file contains the handling of entries which wait for the user.
 *
 * A source can let its entries wait instead of publishing them, e.g. a
 * recurring entry which says "Went to the gym": only the user knows
 * whether that is true this week. Such an entry is stored with the status
 * "pending", which nothing on the now page reads, and is confirmed or
 * discarded by the user:
 *
 * - in the list of entries, with one click each,
 * - through the two links of an email, which lead to a small page with a
 *   button. The link does not decide anything by itself: mail programs and
 *   virus scanners open links ahead of the reader.
 *
 * What is not confirmed stays invisible, and is discarded as soon as the
 * same source lets the next entry wait.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\NowPages;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\Dependencies\easyTransientsForWordPress\Transients;
use ThreadiNowPages\Helper\Format;
use ThreadiNowPages\Helper\Singleton_Interface;
use ThreadiNowPages\Helper\Singleton_Trait;
use ThreadiNowPages\Plugin\Emails\ConfirmEntry;
use WP_Post;
use WP_Query;

/**
 * Object to handle entries which wait for the confirmation of the user.
 */
class Confirmation implements Singleton_Interface {
	use Singleton_Trait;

	/**
	 * The post status of an entry which waits.
	 *
	 * @var string
	 */
	public const STATUS = 'pending';

	/**
	 * The decision to show an entry.
	 *
	 * @var string
	 */
	public const CONFIRM = 'confirm';

	/**
	 * The decision not to show an entry.
	 *
	 * @var string
	 */
	public const DISCARD = 'discard';

	/**
	 * The meta key holding the hash of the token of the links in the email.
	 *
	 * @var string
	 */
	private const META_TOKEN = 'confirm_token';

	/**
	 * The meta key holding the time until the token of the links in the email is valid.
	 *
	 * @var string
	 */
	private const META_TOKEN_EXPIRES = 'confirm_token_expires';

	/**
	 * How long the links in the email are valid, in seconds.
	 *
	 * @var int
	 */
	private const TOKEN_LIFETIME = 14 * DAY_IN_SECONDS;

	/**
	 * The query variable naming the entry a link from the email is about.
	 *
	 * @var string
	 */
	private const QUERY_ENTRY = 'threadi_now_pages_confirm';

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
		// use our own hooks.
		add_action( 'threadi_now_pages_entry_added', array( $this, 'entry_added' ), 10, 3 );

		// use hooks.
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'set_post_state' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_menu_count' ), PHP_INT_MAX );
		add_action( 'admin_action_threadi_now_pages_' . self::CONFIRM . '_entry', array( $this, 'decide_by_request' ) );
		add_action( 'admin_action_threadi_now_pages_' . self::DISCARD . '_entry', array( $this, 'decide_by_request' ) );
		add_action( 'template_redirect', array( $this, 'show_decision_page' ), 0 );
	}

	/**
	 * Return whether an entry waits for the user.
	 *
	 * @param mixed $post The post, or its ID.
	 *
	 * @return bool
	 */
	public function is_waiting( mixed $post ): bool {
		$post = get_post( $post instanceof WP_Post ? $post : absint( $post ) );

		return $post instanceof WP_Post && Entries::get_instance()->get_slug() === $post->post_type && self::STATUS === $post->post_status;
	}

	/**
	 * Return how many entries wait for the current user.
	 *
	 * @return int
	 */
	public function get_count(): int {
		$counts = wp_count_posts( Entries::get_instance()->get_slug(), 'readable' );

		return isset( $counts->{self::STATUS} ) ? absint( $counts->{self::STATUS} ) : 0;
	}

	/**
	 * Show a waiting entry on the now page.
	 *
	 * The entry keeps its date: it belongs to the day it was due on, not
	 * to the day the user got around to it.
	 *
	 * @param int $entry_id The ID of the entry.
	 *
	 * @return bool False if the entry does not wait, or could not be published.
	 */
	public function confirm( int $entry_id ): bool {
		// bail if this entry does not wait.
		if ( ! $this->is_waiting( $entry_id ) ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'          => $entry_id,
				'post_status' => 'publish',
			),
			true
		);

		// bail if it could not be published.
		if ( is_wp_error( $result ) ) {
			return false;
		}

		delete_post_meta( $entry_id, self::META_TOKEN );
		delete_post_meta( $entry_id, self::META_TOKEN_EXPIRES );

		/**
		 * Fires after the user confirmed an entry which was waiting.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $entry_id The ID of the entry.
		 */
		do_action( 'threadi_now_pages_entry_confirmed', $entry_id );

		return true;
	}

	/**
	 * Discard a waiting entry.
	 *
	 * It is moved to the trash, where the import still finds it: the same
	 * entry is not created again.
	 *
	 * @param int $entry_id The ID of the entry.
	 *
	 * @return bool False if the entry does not wait, or could not be removed.
	 */
	public function discard( int $entry_id ): bool {
		// bail if this entry does not wait.
		if ( ! $this->is_waiting( $entry_id ) ) {
			return false;
		}

		delete_post_meta( $entry_id, self::META_TOKEN );
		delete_post_meta( $entry_id, self::META_TOKEN_EXPIRES );

		// bail if it could not be removed.
		if ( ! wp_trash_post( $entry_id ) ) {
			return false;
		}

		/**
		 * Fires after an entry which was waiting has been discarded.
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 *
		 * @param int $entry_id The ID of the entry.
		 */
		do_action( 'threadi_now_pages_entry_discarded', $entry_id );

		return true;
	}

	/**
	 * Take care of a new entry which waits: make room for it and tell the user.
	 *
	 * @param mixed $post_id   The ID of the new entry.
	 * @param mixed $entry     The entry object it was created from.
	 * @param mixed $source_id The ID of the source it came from.
	 *
	 * @return void
	 */
	public function entry_added( mixed $post_id, mixed $entry, mixed $source_id ): void {
		$post_id = absint( $post_id );

		// bail if this entry does not wait.
		if ( ! $this->is_waiting( $post_id ) ) {
			return;
		}

		// what the user did not confirm until now is not confirmed anymore.
		$this->discard_older( absint( $source_id ), $post_id );

		// tell the user, with links which belong to this entry only.
		$token = $this->create_token( $post_id );

		$email = new ConfirmEntry();
		$email->set_entry( $post_id, $this->get_link( $post_id, $token, self::CONFIRM ), $this->get_link( $post_id, $token, self::DISCARD ) );
		$email->send();
	}

	/**
	 * Discard the entries of a source which still wait, except the given one.
	 *
	 * @param int $source_id The ID of the source.
	 * @param int $keep      The ID of the entry which stays.
	 *
	 * @return int The number of discarded entries.
	 */
	public function discard_older( int $source_id, int $keep ): int {
		// bail without a source: entries without one have nothing in common.
		if ( $source_id <= 0 ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => Entries::get_instance()->get_slug(),
				'post_status'            => self::STATUS,
				'post_parent'            => $source_id,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$discarded = 0;

		foreach ( (array) $query->posts as $entry_id ) {
			// the entry which stays.
			if ( absint( $entry_id ) === $keep ) {
				continue;
			}

			if ( is_int( $entry_id ) && $this->discard( $entry_id ) ) {
				++$discarded;
			}
		}

		return $discarded;
	}

	/**
	 * Create the token of the links in the email, and return it.
	 *
	 * Only its hash is stored: whoever reads the database cannot build a link from it.
	 *
	 * @param int $entry_id The ID of the entry.
	 *
	 * @return string
	 */
	public function create_token( int $entry_id ): string {
		$token = wp_generate_password( 40, false );

		update_post_meta( $entry_id, self::META_TOKEN, $this->get_hash( $token ) );
		update_post_meta( $entry_id, self::META_TOKEN_EXPIRES, time() + self::TOKEN_LIFETIME );

		return $token;
	}

	/**
	 * Return whether a token belongs to an entry which waits.
	 *
	 * @param int    $entry_id The ID of the entry.
	 * @param string $token    The token.
	 *
	 * @return bool
	 */
	public function is_valid_token( int $entry_id, string $token ): bool {
		// bail if this entry does not wait, or no token was given.
		if ( '' === $token || ! $this->is_waiting( $entry_id ) ) {
			return false;
		}

		// get the expiry. a token from before expiries existed gets its lifetime from the date of the entry.
		$expires = absint( get_post_meta( $entry_id, self::META_TOKEN_EXPIRES, true ) );
		if ( 0 === $expires ) {
			$expires = absint( get_post_time( 'U', true, $entry_id ) ) + self::TOKEN_LIFETIME;
		}

		// bail if the token has expired.
		if ( $expires < time() ) {
			return false;
		}

		$hash = (string) get_post_meta( $entry_id, self::META_TOKEN, true );

		return '' !== $hash && hash_equals( $hash, $this->get_hash( $token ) );
	}

	/**
	 * Return the hash of a token.
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private function get_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Return the link to the page where a decision about an entry is made.
	 *
	 * @param int    $entry_id The ID of the entry.
	 * @param string $token    The token of this entry.
	 * @param string $decision What the page offers: confirm or discard.
	 *
	 * @return string
	 */
	public function get_link( int $entry_id, string $token, string $decision ): string {
		return add_query_arg(
			array(
				self::QUERY_ENTRY => $entry_id,
				'decision'        => self::DISCARD === $decision ? self::DISCARD : self::CONFIRM,
				'token'           => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Return the URL of the list of entries which wait.
	 *
	 * @return string
	 */
	public function get_list_url(): string {
		return add_query_arg(
			array(
				'post_type'   => Entries::get_instance()->get_slug(),
				'post_status' => self::STATUS,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Return the URL which decides about an entry from within the backend.
	 *
	 * @param int    $entry_id The ID of the entry.
	 * @param string $decision Confirm or discard.
	 *
	 * @return string
	 */
	public function get_action_url( int $entry_id, string $decision ): string {
		$decision = self::DISCARD === $decision ? self::DISCARD : self::CONFIRM;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'threadi_now_pages_' . $decision . '_entry',
					'entry'  => $entry_id,
				),
				admin_url( 'admin.php' )
			),
			'threadi-now-pages-' . $decision . '-entry-' . $entry_id
		);
	}

	/**
	 * Offer both decisions for a waiting entry in the list of entries.
	 *
	 * @param array<string,string> $actions The existing actions.
	 * @param WP_Post              $post    The post the actions belong to.
	 *
	 * @return array<string,string>
	 */
	public function add_row_actions( array $actions, WP_Post $post ): array {
		// bail if this entry does not wait.
		if ( ! $this->is_waiting( $post ) ) {
			return $actions;
		}

		$own = array();

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$own[ 'threadi_now_pages_' . self::CONFIRM ] = '<a href="' . esc_url( $this->get_action_url( $post->ID, self::CONFIRM ) ) . '">' . esc_html__( 'Confirm', 'threadi-now-pages' ) . '</a>';
		}

		if ( current_user_can( 'delete_post', $post->ID ) ) {
			$own[ 'threadi_now_pages_' . self::DISCARD ] = '<a href="' . esc_url( $this->get_action_url( $post->ID, self::DISCARD ) ) . '">' . esc_html__( 'Discard', 'threadi-now-pages' ) . '</a>';
		}

		// the two decisions are what this row is about, so they come first.
		return array_merge( $own, $actions );
	}

	/**
	 * Confirm or discard an entry from within the backend.
	 *
	 * @return void
	 */
	public function decide_by_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ID is part of the nonce action, which is checked right below.
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$decision = 'admin_action_threadi_now_pages_' . self::DISCARD . '_entry' === current_action() ? self::DISCARD : self::CONFIRM;

		// check nonce.
		check_admin_referer( 'threadi-now-pages-' . $decision . '-entry-' . $entry_id );

		// bail if the user is not allowed to decide about this entry.
		if ( ! current_user_can( self::DISCARD === $decision ? 'delete_post' : 'edit_post', $entry_id ) ) {
			wp_die( esc_html__( 'You are not allowed to decide about this entry.', 'threadi-now-pages' ), '', array( 'response' => 403 ) );
		}

		$this->decide( $entry_id, $decision );

		// return to where the user came from.
		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : $this->get_list_url() );
		exit;
	}

	/**
	 * Confirm or discard an entry and leave a note about it for the next page.
	 *
	 * @param int    $entry_id The ID of the entry.
	 * @param string $decision Confirm or discard.
	 *
	 * @return bool Whether the decision could be carried out.
	 */
	public function decide( int $entry_id, string $decision ): bool {
		$text    = wp_trim_words( $this->get_text( $entry_id ), 12, '…' );
		$decided = self::DISCARD === $decision ? $this->discard( $entry_id ) : $this->confirm( $entry_id );

		// say what happened, once: no dismissible time, it is gone after the next page.
		$transient_obj = Transients::get_instance()->add();
		$transient_obj->set_name( 'threadi_now_pages_entry_decision' );
		$transient_obj->set_type( $decided ? 'success' : 'error' );
		$transient_obj->set_message( esc_html( $this->get_result_text( $decision, $decided, $text ) ) );
		$transient_obj->save();

		return $decided;
	}

	/**
	 * Return the text of an entry.
	 *
	 * @param int $entry_id The ID of the entry.
	 *
	 * @return string Empty if the entry does not exist.
	 */
	public function get_text( int $entry_id ): string {
		$post = get_post( $entry_id );

		// bail if this is not an entry.
		if ( ! $post instanceof WP_Post || Entries::get_instance()->get_slug() !== $post->post_type ) {
			return '';
		}

		return Format::get_entry_text( $post );
	}

	/**
	 * Return what to tell the user after a decision.
	 *
	 * @param string $decision Confirm or discard.
	 * @param bool   $decided  Whether it worked.
	 * @param string $text     The text of the entry, shortened.
	 *
	 * @return string
	 */
	private function get_result_text( string $decision, bool $decided, string $text ): string {
		if ( ! $decided ) {
			return __( 'This entry is not waiting for a confirmation anymore. It has probably been confirmed or discarded already.', 'threadi-now-pages' );
		}

		if ( self::DISCARD === $decision ) {
			/* translators: %1$s will be replaced by the text of an entry. */
			return sprintf( __( 'The entry "%1$s" has been discarded. It will not be shown.', 'threadi-now-pages' ), $text );
		}

		/* translators: %1$s will be replaced by the text of an entry. */
		return sprintf( __( 'The entry "%1$s" has been confirmed. It is shown on your now page as soon as its delay has passed.', 'threadi-now-pages' ), $text );
	}

	/**
	 * Name the state of a waiting entry in the list of entries.
	 *
	 * @param array<string,string> $post_states The states of the post.
	 * @param WP_Post              $post        The post.
	 *
	 * @return array<string,string>
	 */
	public function set_post_state( array $post_states, WP_Post $post ): array {
		// bail if this entry does not wait.
		if ( ! $this->is_waiting( $post ) ) {
			return $post_states;
		}

		$post_states[ self::STATUS ] = __( 'Waiting for your confirmation', 'threadi-now-pages' );

		return $post_states;
	}

	/**
	 * Show the number of waiting entries at the menu item of the entries.
	 *
	 * @return void
	 */
	public function add_menu_count(): void {
		global $menu;

		// bail if there is no menu.
		if ( ! is_array( $menu ) ) {
			return;
		}

		$count = $this->get_count();

		// bail if nothing waits.
		if ( 0 === $count ) {
			return;
		}

		$slug = 'edit.php?post_type=' . Entries::get_instance()->get_slug();

		foreach ( $menu as $index => $item ) {
			// bail if this is not the menu item of the entries.
			if ( ! is_array( $item ) || ! isset( $item[0], $item[2] ) || $slug !== $item[2] ) {
				continue;
			}

			$hint = sprintf(
				/* translators: %1$d will be replaced by a number. */
				_n( '%1$d entry waits for your confirmation', '%1$d entries wait for your confirmation', $count, 'threadi-now-pages' ),
				$count
			);

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the only way to add a count to an existing menu item, core does the same for comments and updates.
			$menu[ $index ][0] .= ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $hint ) . '</span></span>';

			break;
		}
	}

	/**
	 * Show the page a link from the email leads to, and act on its button.
	 *
	 * Opening the link changes nothing. Only the button does, which is sent
	 * as a form: something a mail program which looks at links ahead of the
	 * reader does not do.
	 *
	 * @return void
	 */
	public function show_decision_page(): void {
		// the token is what allows this request, it replaces the nonce: the reader of the email is not necessarily logged in.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$entry_id = isset( $_GET[ self::QUERY_ENTRY ] ) ? absint( wp_unslash( $_GET[ self::QUERY_ENTRY ] ) ) : 0;

		// bail if this request is not about an entry.
		if ( 0 === $entry_id ) {
			return;
		}

		$token    = isset( $_REQUEST['token'] ) && is_scalar( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : '';
		$decision = isset( $_REQUEST['decision'] ) && self::DISCARD === $_REQUEST['decision'] ? self::DISCARD : self::CONFIRM;
		$is_post  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['threadi_now_pages_decide'] );
		// phpcs:enable

		$title = __( 'Entry for your now page', 'threadi-now-pages' );

		// bail if the link does not belong to an entry which waits. The same answer for every reason: nothing to learn from it.
		if ( ! $this->is_valid_token( $entry_id, $token ) ) {
			$this->show_page( '<p>' . esc_html( $this->get_result_text( $decision, false, '' ) ) . '</p>', $title, 404 );

			return;
		}

		$text = $this->get_text( $entry_id );

		// the button has been used: decide.
		if ( $is_post ) {
			$decided = self::DISCARD === $decision ? $this->discard( $entry_id ) : $this->confirm( $entry_id );

			$this->show_page( '<p>' . esc_html( $this->get_result_text( $decision, $decided, wp_trim_words( $text, 12, '…' ) ) ) . '</p>', $title, 200 );

			return;
		}

		// show the entry with the button for this decision, and a way to the other one.
		$other = self::DISCARD === $decision ? self::CONFIRM : self::DISCARD;

		$html  = '<p>' . esc_html__( 'This entry is waiting for your confirmation:', 'threadi-now-pages' ) . '</p>';
		$html .= '<blockquote><p><strong>' . esc_html( $text ) . '</strong></p></blockquote>';
		$html .= '<form method="post" action="' . esc_url( $this->get_link( $entry_id, $token, $decision ) ) . '">';
		$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		$html .= '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
		$html .= '<p><button type="submit" name="threadi_now_pages_decide" value="1" class="button button-large">' . esc_html( self::DISCARD === $decision ? __( 'Discard this entry', 'threadi-now-pages' ) : __( 'Confirm this entry', 'threadi-now-pages' ) ) . '</button></p>';
		$html .= '</form>';
		$html .= '<p><a href="' . esc_url( $this->get_link( $entry_id, $token, $other ) ) . '">' . esc_html( self::DISCARD === $other ? __( 'I would rather discard it', 'threadi-now-pages' ) : __( 'I would rather confirm it', 'threadi-now-pages' ) ) . '</a></p>';

		$this->show_page( $html, $title, 200 );
	}

	/**
	 * Show a small page of its own and end the request.
	 *
	 * @param string $html     The content.
	 * @param string $title    The title of the page.
	 * @param int    $response The HTTP status.
	 *
	 * @return void
	 */
	private function show_page( string $html, string $title, int $response ): void {
		// the page depends on a secret in its address: keep it out of every cache.
		nocache_headers();

		// what a post may contain, plus the form with its button.
		$allowed = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'form'   => array(
					'method' => true,
					'action' => true,
				),
				'input'  => array(
					'type'  => true,
					'name'  => true,
					'value' => true,
				),
				'button' => array(
					'type'  => true,
					'name'  => true,
					'value' => true,
					'class' => true,
				),
			)
		);

		wp_die(
			wp_kses( $html, $allowed ),
			esc_html( $title ),
			array(
				'response' => absint( $response ),
			)
		);
	}
}
