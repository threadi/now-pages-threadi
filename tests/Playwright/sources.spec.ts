import { test, expect } from './fixtures';
import { completeSetup, php, removeContent } from './helpers';

test.describe('Sources', () => {
    // @ts-ignore
    test.beforeEach(async ({ cli }) => {
        await removeContent(cli);
        await completeSetup(cli);
    });

    // @ts-ignore
    test('Adding a source offers the known services', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=np_threadi_source`);

        for (const service of ['This website', 'Mastodon', 'GitHub', 'Last.fm', 'Steam', 'Goodreads', 'Something I do regularly']) {
            await expect(page.getByRole('button', { name: new RegExp(`^${service.replace('.', '\\.')}`) })).toBeVisible();
        }

        // nothing is chosen yet, so there is nothing to continue with.
        await expect(page.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    // @ts-ignore
    test('Without an AI service the advice is explained instead of offered', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=np_threadi_source`);

        await expect(page.getByRole('button', { name: /^This website/ })).toBeVisible();
        await expect(page.getByRole('button', { name: /^Get advice/ })).toHaveCount(0);
        await expect(page.getByText('Connect an AI service under')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Settings → Connectors' })).toHaveAttribute('href', /options-connectors\.php/);
    });

    // @ts-ignore
    test('A source added through the assistant is imported at once', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=np_threadi_source`);

        const continueButton = page.getByRole('button', { name: 'Continue' });

        await page.getByRole('button', { name: /^This website/ }).click();
        await expect(continueButton).toBeEnabled();
        await continueButton.click();

        await expect(page.getByText('This website needs no further details.')).toBeVisible();
        await continueButton.click();

        const completedButton = page.getByRole('button', { name: 'Completed' });
        await expect(completedButton).toBeEnabled({ timeout: 90_000 });
        await completedButton.click();

        await expect(page).toHaveURL(/edit\.php\?post_type=np_threadi_source/);
        await expect(page.locator('#the-list tr')).toHaveCount(1);
        await expect(page.locator('#the-list tr')).toContainText('This website');

        // the posts of this website have become entries.
        const entries = await php(cli, `echo count( get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => 'publish', 'numberposts' => -1 ) ) );`);
        expect(parseInt(entries, 10)).toBeGreaterThan(0);
    });

    // @ts-ignore
    test('The form for a source by hand asks for a provider first', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=np_threadi_source&now_pages_threadi_manual=1`);

        await expect(page.getByRole('heading', { level: 1 })).toContainText('Add New Now Page Source');

        const provider = page.getByLabel('Choose a provider');
        await expect(provider.locator('option')).toContainText(['Please choose', 'RSS / Atom feed', 'GitHub', 'This website']);
        await expect(page.getByText('Please choose a provider first.')).toBeVisible();
    });

    // @ts-ignore
    test('Changing the category of a source moves the entries it has already imported', async ({ page, cli }) => {
        // a source which is not published, so saving it does not start an import.
        const sourceId = await php(
            cli,
            `$taxonomy = \\NowPagesThreadi\\NowPages\\Categories::TAXONOMY;
            $forum     = wp_insert_term( 'Forum', $taxonomy );
            wp_insert_term( 'Translations', $taxonomy );

            $source_id = wp_insert_post(
                array(
                    'post_type'   => 'np_threadi_source',
                    'post_status' => 'draft',
                    'post_title'  => 'A feed',
                    'meta_input'  => array(
                        'provider'         => 'rss',
                        'feed_url'         => 'https://example.com/feed/',
                        'default_category' => (int) $forum['term_id'],
                    ),
                )
            );

            foreach ( array( 'First entry', 'Second entry' ) as $text ) {
                $entry_id = wp_insert_post(
                    array(
                        'post_type'    => 'np_threadi_entry',
                        'post_status'  => 'publish',
                        'post_parent'  => $source_id,
                        'post_content' => $text,
                    )
                );
                wp_set_object_terms( $entry_id, array( (int) $forum['term_id'] ), $taxonomy );
            }

            echo $source_id;`
        );

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post.php?post=${sourceId}&action=edit`);

        // the place where the categories are managed is one click away.
        await expect(page.getByRole('link', { name: /^Manage the categories/ })).toHaveAttribute(
            'href',
            /edit-tags\.php\?taxonomy=now_pages_threadi_category&post_type=np_threadi_entry/
        );

        await page.getByLabel('Default category for imported entries').selectOption({ label: 'Translations' });
        await page.getByRole('button', { name: 'Save Draft' }).click();

        const hint = page.getByText('2 entries of this source have been moved to the category "Translations".');
        await expect(hint).toBeVisible();

        const categories = await php(
            cli,
            `foreach ( get_posts( array( 'post_type' => 'np_threadi_entry', 'post_parent' => (int) $args['source'], 'numberposts' => -1 ) ) as $entry ) {
                echo implode( ',', wp_get_object_terms( $entry->ID, \\NowPagesThreadi\\NowPages\\Categories::TAXONOMY, array( 'fields' => 'names' ) ) ) . ';';
            }`,
            { source: parseInt(sourceId, 10) }
        );
        expect(categories).toBe('Translations;Translations;');

        // the hint is shown once.
        await page.reload();
        await expect(page.getByLabel('Default category for imported entries').locator('option:checked')).toHaveText('Translations');
        await expect(hint).toHaveCount(0);

        // the list of the sources names the category as well.
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=np_threadi_source`);
        await expect(page.locator('#the-list td.column-now_pages_threadi_category')).toHaveText('Translations');
    });
});
