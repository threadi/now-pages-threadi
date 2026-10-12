<?php
/**
 * File for the email asking the user to confirm an entry.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin\Emails;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use ThreadiNowPages\NowPages\Confirmation;
use ThreadiNowPages\Plugin\Email_Base;
use WP_User;

/**
 * Object that handles the email about an entry which waits for the user.
 *
 * Sent the moment such an entry is created, see Confirmation. Its two
 * links lead to a page with a button - they do not decide anything by
 * themselves, since mail programs open links ahead of the reader.
 */
class ConfirmEntry extends Email_Base {
	/**
	 * The internal name of this object.
	 *
	 * @var string
	 */
	protected string $name = 'confirm_entry';

	/**
	 * Enabled by default: a source which asks first is of no use if nobody hears the question.
	 *
	 * @var bool
	 */
	protected bool $default_enabled = true;

	/**
	 * The ID of the entry this email is about.
	 *
	 * @var int
	 */
	private int $entry_id = 0;

	/**
	 * The text of the entry.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * The link to the page which confirms the entry.
	 *
	 * @var string
	 */
	private string $confirm_url = '';

	/**
	 * The link to the page which discards the entry.
	 *
	 * @var string
	 */
	private string $discard_url = '';

	/**
	 * Constructor for this object.
	 */
	public function __construct() {}

	/**
	 * Return the title.
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Confirm an entry', 'threadi-now-pages' );
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	protected function get_description(): string {
		return __( 'If activated, an email is sent whenever an entry waits for your confirmation. It contains a link to confirm the entry and a link to discard it.', 'threadi-now-pages' );
	}

	/**
	 * Show the description for this email object.
	 *
	 * @return void
	 */
	public function show_description(): void {
		echo esc_html__( 'A source can ask you before its entries are shown, e.g. a recurring entry. This email is how you hear about it. Without recipients set, it goes to the owner of the source.', 'threadi-now-pages' );
	}

	/**
	 * Set the entry this email is about.
	 *
	 * @param int    $entry_id    The ID of the entry.
	 * @param string $confirm_url The link to the page which confirms it.
	 * @param string $discard_url The link to the page which discards it.
	 *
	 * @return void
	 */
	public function set_entry( int $entry_id, string $confirm_url, string $discard_url ): void {
		$this->entry_id    = $entry_id;
		$this->text        = Confirmation::get_instance()->get_text( $entry_id );
		$this->confirm_url = $confirm_url;
		$this->discard_url = $discard_url;
	}

	/**
	 * Return the subject of the email.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		$this->subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . sprintf(
			/* translators: %1$s will be replaced by the text of an entry. */
			__( 'Please confirm: %1$s', 'threadi-now-pages' ),
			wp_trim_words( $this->text, 10, '…' )
		);

		// return the parent tasks for the subject.
		return parent::get_subject();
	}

	/**
	 * Return the body.
	 *
	 * The addresses of the two links are never printed as text: they contain
	 * the token of this entry, and the body of every email is written to the log.
	 *
	 * @return string
	 */
	public function get_body(): string {
		// bail if there is nothing to ask about.
		if ( '' === $this->text || '' === $this->confirm_url || '' === $this->discard_url ) {
			$this->body = '';

			return '';
		}

		$source = $this->entry_id > 0 ? get_the_title( absint( wp_get_post_parent_id( $this->entry_id ) ) ) : '';

		$this->body = '<p>' . esc_html__( 'This entry is waiting for your confirmation before it is shown on your now page:', 'threadi-now-pages' ) . '</p>';

		$this->body .= '<p><strong>' . esc_html( $this->text ) . '</strong></p>';

		if ( '' !== $source ) {
			/* translators: %1$s will be replaced by the title of a source. */
			$this->body .= '<p>' . esc_html( sprintf( __( 'Source: %1$s', 'threadi-now-pages' ), $source ) ) . '</p>';
		}

		$this->body .= '<p><a href="' . esc_url( $this->confirm_url ) . '"><strong>' . esc_html__( 'Yes, show this entry', 'threadi-now-pages' ) . '</strong></a></p>';
		$this->body .= '<p><a href="' . esc_url( $this->discard_url ) . '">' . esc_html__( 'No, discard it', 'threadi-now-pages' ) . '</a></p>';

		$this->body .= '<p>' . esc_html__( 'Both links open a page where you confirm your choice with a button. If you do nothing, the entry stays hidden and is discarded as soon as the next one is due.', 'threadi-now-pages' ) . '</p>';

		$this->body .= '<p>' . sprintf(
			/* translators: %1$s will be replaced by a URL. */
			__( 'You can also decide in the <a href="%1$s">list of your entries</a>.', 'threadi-now-pages' ),
			esc_url( Confirmation::get_instance()->get_list_url() )
		) . '</p>';

		// return the parent tasks for body.
		return parent::get_body();
	}

	/**
	 * Return the hint about the recipient used if none is set.
	 *
	 * @return string
	 */
	protected function get_recipient_hint(): string {
		return '<strong>' . __( 'If no email is set, the email goes to the owner of the source, the administrator as fallback.', 'threadi-now-pages' ) . '</strong>';
	}

	/**
	 * Return the default recipient: the owner of the entry, the administrator as fallback.
	 *
	 * The links in this email decide about the entry, so they belong to the
	 * user whose now page shows it.
	 *
	 * @return string
	 */
	protected function get_default_recipient(): string {
		// use the owner of the entry, if known.
		if ( $this->entry_id > 0 ) {
			$owner = get_user_by( 'id', absint( get_post_field( 'post_author', $this->entry_id ) ) );

			if ( $owner instanceof WP_User && is_email( $owner->user_email ) ) {
				return $owner->user_email;
			}
		}

		return (string) get_option( 'admin_email' );
	}

	/**
	 * Fill the email with an example for the test.
	 *
	 * @return void
	 */
	protected function prepare_for_test(): void {
		$this->entry_id    = 0;
		$this->text        = __( 'This is what an entry waiting for your confirmation looks like.', 'threadi-now-pages' );
		$this->confirm_url = Confirmation::get_instance()->get_list_url();
		$this->discard_url = Confirmation::get_instance()->get_list_url();
	}
}
