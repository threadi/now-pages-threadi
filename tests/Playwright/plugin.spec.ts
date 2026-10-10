import { test, expect } from './fixtures';

// @ts-ignore
test('Plugin active & sources loading', async ({ page, cli }) => {
    // @ts-ignore
    await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=np_threadi_source`);
    await expect(page.locator('#wpbody-content')).toBeVisible();
    await expect(page).toHaveTitle(/Now Page Sources/);
});

// @ts-ignore
test('Plugin settings can be saved', async ({ page, cli }) => {
    // @ts-ignore
    await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=now_pages_threadi_settings`);
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    // the confirmation is on the page twice: as the visible notice and as a hidden
    // announcement for screen readers. Only the notice is what a user sees.
    await expect(page.getByTestId('snackbar')).toContainText('Settings saved');
});

// @ts-ignore
test('The plugin works without logging an error', async ({ page, cli }) => {
    // use the plugin a little: the list of entries, the settings and the now page.
    for (const path of ['/wp-admin/edit.php?post_type=np_threadi_entry', '/wp-admin/options-general.php?page=now_pages_threadi_settings', '/now/']) {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}${path}`);
    }

    // the log of the plugin, filtered by errors, has to be empty.
    // @ts-ignore
    await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=now_pages_threadi_settings&tab=logs&errors=1`);
    await expect(page.getByRole('tab', { name: 'Logs', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel')).toContainText('No log entries found.');
});

