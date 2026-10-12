<?php
/**
 * Default /now template – uses the active theme’s header & footer.
 *
 * Override in the theme (theme root or child theme):
 * - threadi-now-pages.php
 * - now.php
 *
 * Data:
 * - Frontend::get_instance()->get_content()  string        Rendered digest HTML
 * - Frontend::get_instance()->get_user()     WP_User|null  Author on /author/…/now, else null
 * - Frontend::get_instance()->get_args()     array         Args passed to Digest_Builder
 *
 * The same values remain available via get_query_var( 'threadi_now_pages_content' ) etc.
 *
 * @version: 1.0.0
 * @package threadi-now-pages
 */

declare(strict_types=1);

// prevent direct access.
defined( 'ABSPATH' ) || exit;

$threadi_now_pages_frontend = \ThreadiNowPages\NowPages\Frontend::get_instance();
$threadi_now_pages_content  = $threadi_now_pages_frontend->get_content();
$threadi_now_pages_user     = $threadi_now_pages_frontend->get_user();

get_header();
?>
	<main id="primary" class="site-main threadi-now-pages-frontend<?php echo $threadi_now_pages_user instanceof WP_User ? ' threadi-now-pages-frontend--author' : ''; ?>">
		<header class="page-header">
			<h1 class="page-title">
				<?php
				if ( $threadi_now_pages_user instanceof WP_User ) {
					echo esc_html(
						sprintf(
						/* translators: %s: display name */
							__( 'Now – %s', 'threadi-now-pages' ),
							$threadi_now_pages_user->display_name
						)
					);
				} else {
					esc_html_e( 'Now', 'threadi-now-pages' );
				}
				?>
			</h1>
		</header>

		<div class="page-content threadi-now-pages-frontend__content">
			<?php
			if ( $threadi_now_pages_content ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside digest formats.
				echo $threadi_now_pages_content;
			} else {
				echo '<p>' . esc_html__( 'Nothing to show right now.', 'threadi-now-pages' ) . '</p>';
			}
			?>
		</div>
	</main>
<?php
get_footer();
