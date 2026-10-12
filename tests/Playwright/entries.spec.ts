import { test, expect } from './fixtures';
import { addEntry, completeSetup, hideEditorGuide, php, removeContent, setOptions } from './helpers';

test.describe('Entries', () => {
    // @ts-ignore
    test.beforeEach(async ({ cli }) => {
        await removeContent(cli);
        await completeSetup(cli);
    });

    // @ts-ignore
    test('An entry written by hand ends up on the now page', async ({ page, visitor, cli }) => {
        // without the privacy delay, so the entry does not have to be six hours old to be seen.
        await setOptions(cli, { threadi_now_pages_delay_hours: 0 });
        await hideEditorGuide(cli);

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=threadi_np_entry`);

        // write the entry.
        const canvas = page.frameLocator('iframe[name="editor-canvas"]');
        await canvas.getByRole('button', { name: 'Add default block' }).click();
        await page.keyboard.type('Baked a sourdough bread');

        // publish it: once to open the panel, once to confirm.
        await page.getByRole('button', { name: 'Publish', exact: true }).click();
        await page.getByRole('region', { name: 'Editor publish' }).getByRole('button', { name: 'Publish', exact: true }).click();
        await expect(page.getByTestId('snackbar')).toContainText('published');

        // it is in the list of entries, recognizable by its text ...
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=threadi_np_entry`);
        await expect(page.locator('#the-list tr')).toHaveCount(1);
        await expect(page.locator('#the-list tr .row-title')).toHaveText('Baked a sourdough bread');

        // ... without a title of its own being stored.
        expect(await php(cli, `echo get_posts( array( 'post_type' => 'threadi_np_entry', 'numberposts' => 1 ) )[0]->post_title;`)).toBe('');

        // ... and on the now page.
        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/now/`);
        await expect(visitor.locator('.threadi-now-pages-digest-entry')).toHaveText(['Baked a sourdough bread']);
    });

    // @ts-ignore
    test('A trashed entry disappears from the now page', async ({ page, visitor, cli }) => {
        await addEntry(cli, 'Planted the tomatoes', 8);
        await addEntry(cli, 'Something I would rather not show', 9);

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=threadi_np_entry`);

        // the row actions only show up when the row is hovered.
        const row = page.locator('#the-list tr', { hasText: 'Something I would rather not show' });
        await row.hover();
        await row.getByRole('link', { name: /Trash/ }).click();
        await expect(page.locator('#message')).toContainText('1 entry has been moved to trash');

        // @ts-ignore
        await visitor.goto(`${cli.serverUrl}/now/`);
        await expect(visitor.locator('.threadi-now-pages-digest-entry')).toHaveText(['Planted the tomatoes']);
    });
});
