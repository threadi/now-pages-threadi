/**
 * Helpers for the Playwright tests: prepare the website through PHP instead of clicking.
 *
 * All tests share one website (see fixtures.ts). Each test therefore says what it
 * needs, and does not rely on what a test before it left behind - Playwright starts
 * a fresh website whenever a test fails.
 */

// what we use of the Playground the fixture starts.
export type Cli = {
    serverUrl: string;
    playground: { run: (options: { code: string }) => Promise<{ text: string }> };
};

/**
 * Run PHP in the website, as administrator. Returns what the code prints.
 *
 * Values are handed over as "$args", so no test has to build PHP strings from its data.
 */
export async function php(cli: Cli, code: string, args: Record<string, unknown> = {}): Promise<string> {
    // @ts-ignore
    const encoded = Buffer.from(JSON.stringify(args)).toString('base64');
    const result = await cli.playground.run({
        code: `<?php
            require_once '/wordpress/wp-load.php';
            wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
            $args = json_decode( base64_decode( '${encoded}' ), true );
            ${code}`,
    });

    return result.text;
}

/**
 * Make the website look like the setup has been run: the now page is reachable under /now/.
 */
export async function completeSetup(cli: Cli): Promise<void> {
    await setNowPageEnabled(cli, true);

    // mark the setup as completed directly: Setup::set_completed() only stores the marker if the
    // setup configuration is registered, which happens in the backend only - not in this request.
    await php(
        cli,
        `$completed = get_option( 'esfw_completed', array() );
        if ( ! is_array( $completed ) ) {
            $completed = array();
        }
        if ( ! in_array( 'threadi-now-pages', $completed, true ) ) {
            $completed[] = 'threadi-now-pages';
            update_option( 'esfw_completed', $completed );
        }`
    );
}

/**
 * Switch the global now page on or off, like the setting "Global URL" does.
 */
export async function setNowPageEnabled(cli: Cli, enabled: boolean): Promise<void> {
    await php(cli, `update_option( 'threadi_now_pages_frontend_global_url', $args['enabled'] ? 1 : 0 );`, { enabled });

    // the addresses are registered when WordPress starts, so they have to be
    // rebuilt in a request of their own: this one already knows the new value.
    await php(
        cli,
        `\\ThreadiNowPages\\NowPages\\Frontend::trigger_slug_update();
        \\ThreadiNowPages\\NowPages\\Frontend::get_instance()->update_slugs();`
    );
}

/**
 * Put the website back to before the setup: no sources, no entries, no page, setup not run.
 */
export async function resetSetup(cli: Cli): Promise<void> {
    await removeContent(cli);
    await php(cli, `\\ThreadiNowPages\\Plugin\\Setup::get_instance()->uninstall();`);
}

/**
 * Remove everything the tests create, and set the display back to its defaults.
 */
export async function removeContent(cli: Cli): Promise<void> {
    await php(
        cli,
        `$posts = get_posts( array( 'post_type' => array( 'threadi_np_entry', 'threadi_np_source' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
        foreach ( $posts as $post_id ) {
            wp_delete_post( $post_id, true );
        }

        // the page of the now page, and pages created by tests.
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_threadi_now_pages_test', 'fields' => 'ids' ) );
        $pages[] = absint( get_option( \\ThreadiNowPages\\NowPages\\Now_Page::OPTION, 0 ) );
        foreach ( array_filter( $pages ) as $post_id ) {
            wp_delete_post( $post_id, true );
        }
        delete_option( \\ThreadiNowPages\\NowPages\\Now_Page::OPTION );

        foreach ( get_terms( array( 'taxonomy' => \\ThreadiNowPages\\NowPages\\Categories::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
            wp_delete_term( $term_id, \\ThreadiNowPages\\NowPages\\Categories::TAXONOMY );
        }

        update_option( 'threadi_now_pages_delay_hours', 6 );
        update_option( 'threadi_now_pages_group_by_category', 1 );`
    );
}

/**
 * Create an entry which happened the given number of hours ago. Returns its ID.
 */
export async function addEntry(cli: Cli, text: string, hoursAgo: number, category = ''): Promise<number> {
    const id = await php(
        cli,
        `$time = time() - (int) round( $args['hours'] * HOUR_IN_SECONDS );
        $post_id = wp_insert_post(
            array(
                'post_type'     => 'threadi_np_entry',
                'post_status'   => 'publish',
                'post_title'    => '',
                'post_content'  => $args['text'],
                'post_date'     => wp_date( 'Y-m-d H:i:s', $time ),
                'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $time ),
                'meta_input'    => array( 'language' => substr( get_locale(), 0, 5 ) ),
            )
        );

        if ( '' !== $args['category'] ) {
            $taxonomy = \\ThreadiNowPages\\NowPages\\Categories::TAXONOMY;
            $term     = term_exists( $args['category'], $taxonomy );
            if ( ! $term ) {
                $term = wp_insert_term( $args['category'], $taxonomy );
            }
            wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), $taxonomy );
        }

        echo $post_id;`,
        { text, hours: hoursAgo, category }
    );

    return parseInt(id, 10);
}

/**
 * Set options of the website.
 */
export async function setOptions(cli: Cli, options: Record<string, unknown>): Promise<void> {
    await php(
        cli,
        `foreach ( $args as $name => $value ) {
            update_option( $name, $value );
        }`,
        options
    );
}

/**
 * Keep the block editor from greeting the administrator with its guide, which covers the editor.
 */
export async function hideEditorGuide(cli: Cli): Promise<void> {
    await php(
        cli,
        `global $wpdb;
        update_user_meta(
            get_current_user_id(),
            $wpdb->get_blog_prefix() . 'persisted_preferences',
            array(
                'core/edit-post' => array( 'welcomeGuide' => false ),
                '_modified'      => gmdate( 'c' ),
            )
        );`
    );
}

