<?php
/**
 * Default /now template – uses the active theme’s header & footer.
 *
 * Override in the theme (theme root or child theme):
 * - now-pages-threadi.php
 * - now.php
 *
 * Data:
 * - Frontend::get_instance()->get_content()  string        Rendered digest HTML
 * - Frontend::get_instance()->get_user()     WP_User|null  Author on /author/…/now, else null
 * - Frontend::get_instance()->get_args()     array         Args passed to Digest_Builder
 *
 * The same values remain available via get_query_var( 'now_pages_threadi_content' ) etc.
 *
 * @version: 1.0.0
 * @package now-pages-threadi
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

$now_pages_threadi_frontend = \NowPagesThreadi\NowPages\Frontend::get_instance();
$now_pages_threadi_content  = $now_pages_threadi_frontend->get_content();
$now_pages_threadi_user     = $now_pages_threadi_frontend->get_user();

get_header();
?>
	<main id="primary" class="site-main now-pages-threadi-frontend<?php echo $now_pages_threadi_user instanceof WP_User ? ' now-pages-threadi-frontend--author' : ''; ?>">
		<header class="page-header">
			<h1 class="page-title">
				<?php
				if ( $now_pages_threadi_user instanceof WP_User ) {
					echo esc_html(
						sprintf(
						/* translators: %s: display name */
							__( 'Now – %s', 'now-pages-threadi' ),
							$now_pages_threadi_user->display_name
						)
					);
				} else {
					esc_html_e( 'Now', 'now-pages-threadi' );
				}
				?>
			</h1>
		</header>

		<div class="page-content now-pages-threadi-frontend__content">
			<?php
			if ( $now_pages_threadi_content ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside digest formats.
				echo $now_pages_threadi_content;
			} else {
				echo '<p>' . esc_html__( 'Nothing to show right now.', 'now-pages-threadi' ) . '</p>';
			}
			?>
		</div>
	</main>
<?php
get_footer();
