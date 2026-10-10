import { test, expect } from './fixtures';
import { addEntry, completeSetup, php, removeContent, setNowPageEnabled, setOptions } from './helpers';

test.describe('Now page', () => {
    // @ts-ignore
    test.beforeEach(async ({ cli }) => {
        await removeContent(cli);
        await completeSetup(cli);
    });

    // @ts-ignore
    test('Entries are shown once the privacy delay has passed', async ({ visitor, page, cli }) => {
        await addEntry(cli, 'Planted the tomatoes', 8);
        await addEntry(cli, 'Having lunch right now', 1);

        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/now/`);
        expect(response?.status()).toBe(200);

        // make sure this really is what a visitor sees.
        await expect(visitor.locator('#wpadminbar')).toHaveCount(0);

        const entries = visitor.locator('.now-pages-threadi-digest-entry');
        await expect(entries.filter({ hasText: 'Planted the tomatoes' })).toBeVisible();

        // what happened within the delay must not be visible.
        await expect(visitor.getByText('Having lunch right now')).toHaveCount(0);

        // only who can edit entries is told why something may be missing.
        await expect(visitor.locator('.now-pages-threadi-digest-delay')).toHaveCount(0);

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/now/`);
        await expect(page.getByText('Having lunch right now')).toHaveCount(0);
        await expect(page.locator('.now-pages-threadi-digest-delay')).toContainText('privacy delay');
    });

    // @ts-ignore
    test('Without a privacy delay fresh entries are shown at once', async ({ visitor, cli }) => {
        await setOptions(cli, { now_pages_threadi_delay_hours: 0 });
        await addEntry(cli, 'Having lunch right now', 1);

        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/now/`);

        await expect(visitor.locator('.now-pages-threadi-digest-entry').filter({ hasText: 'Having lunch right now' })).toBeVisible();
        await expect(visitor.locator('.now-pages-threadi-digest-delay')).toHaveCount(0);
    });

    // @ts-ignore
    test('Entries are grouped by their category', async ({ visitor, cli }) => {
        await addEntry(cli, 'Read a chapter of Dune', 8, 'Reading');
        await addEntry(cli, 'Fixed the garden gate', 9);

        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/now/`);

        await expect(visitor.locator('.now-pages-threadi-digest-category')).toHaveCount(2);

        // each entry has to be in the list below its own heading.
        const list = (name: string) => visitor.locator('.now-pages-threadi-digest-category', { hasText: name }).locator('+ .now-pages-threadi-digest-list');
        await expect(list('Reading')).toContainText('Read a chapter of Dune');
        await expect(list('Reading')).not.toContainText('Fixed the garden gate');
        await expect(list('Uncategorized')).toContainText('Fixed the garden gate');
    });

    // @ts-ignore
    test('Without grouping there is one list without headings', async ({ visitor, cli }) => {
        await setOptions(cli, { now_pages_threadi_group_by_category: 0 });
        await addEntry(cli, 'Read a chapter of Dune', 8, 'Reading');
        await addEntry(cli, 'Fixed the garden gate', 9);

        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/now/`);

        await expect(visitor.locator('.now-pages-threadi-digest-entry')).toHaveCount(2);
        await expect(visitor.locator('.now-pages-threadi-digest-category')).toHaveCount(0);
    });

    // @ts-ignore
    test('The shortcode shows the entries on any page', async ({ visitor, cli }) => {
        await addEntry(cli, 'Planted the tomatoes', 8);
        await php(
            cli,
            `wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'What I do', 'post_name' => 'what-i-do', 'post_content' => '[now_pages_threadi_digest]', 'meta_input' => array( '_now_pages_threadi_test' => 1 ) ) );`
        );

        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/what-i-do/`);

        await expect(visitor.getByRole('heading', { level: 1, name: 'What I do' })).toBeVisible();
        await expect(visitor.locator('.now-pages-threadi-digest-entry')).toHaveText(['Planted the tomatoes']);
    });

    // @ts-ignore
    test('A switched off now page cannot be reached', async ({ visitor, cli }) => {
        await addEntry(cli, 'Planted the tomatoes', 8);
        await setNowPageEnabled(cli, false);

        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/now/`);

        expect(response?.status()).toBe(404);
        await expect(visitor.getByText('Planted the tomatoes')).toHaveCount(0);
    });
});
