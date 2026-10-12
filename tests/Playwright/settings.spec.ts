import { test, expect } from './fixtures';
import { addEntry, completeSetup, removeContent, setOptions } from './helpers';

test.describe('Settings', () => {
    // @ts-ignore
    test('Loading settings page without error', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=threadi_now_pages_settings`);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            'Settings for Now Pages'
        );
    });

    // @ts-ignore
    test('Save max. age of log entries in advanced tab', async ({ page, cli }) => {
        // @ts-ignore
        const settingsUrl = `${cli.serverUrl}/wp-admin/options-general.php?page=threadi_now_pages_settings`;
        const label = 'max. Age for log entries in days';

        await page.goto(settingsUrl);

        // the tabs of the settings are tabs, not links: they switch without loading a new page.
        await page.getByRole('tab', { name: 'Advanced', exact: true }).click();

        await page.getByLabel(label).fill('45');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByTestId('snackbar')).toContainText('Settings saved');

        // load the page again: only then the value comes from the database and not from the form.
        await page.goto(settingsUrl);
        await page.getByRole('tab', { name: 'Advanced', exact: true }).click();
        await expect(page.getByLabel(label)).toHaveValue('45');
    });

    // @ts-ignore
    test('Every tab opens without an error', async ({ page, cli }) => {
        // errors of earlier tests mark the tab "Logs" with a "!", which becomes part of its name.
        await setOptions(cli, { threadi_now_pages_log_error_count: 0 });

        const errors: string[] = [];
        page.on('pageerror', (error: Error) => errors.push(error.message));

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=threadi_now_pages_settings`);

        for (const name of ['Now page', 'Display', 'Import', 'Provider', 'Permissions', 'AI', 'Fediverse', 'Advanced', 'Emails', 'Logs']) {
            const tab = page.getByRole('tab', { name, exact: true });
            await tab.click();
            await expect(tab).toHaveAttribute('aria-selected', 'true');

            // each tab has to show something. Some tabs have tabs of their own, the first panel is the one of this tab.
            const panel = page.getByRole('tabpanel', { name, exact: true });
            await expect(panel).toBeVisible();
            await expect(panel).not.toBeEmpty();
        }

        expect(errors).toEqual([]);
    });

    // @ts-ignore
    test('Changed privacy delay is used by the now page', async ({ page, cli }) => {
        await removeContent(cli);
        await completeSetup(cli);
        await addEntry(cli, 'Happened three hours ago', 3);

        // @ts-ignore
        const settingsUrl = `${cli.serverUrl}/wp-admin/options-general.php?page=threadi_now_pages_settings`;

        // with the default of six hours the entry is held back.
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/now/`);
        await expect(page.getByText('Happened three hours ago')).toHaveCount(0);

        // shorten the delay in the settings.
        await page.goto(settingsUrl);
        await page.getByRole('tab', { name: 'Display', exact: true }).click();
        await page.getByLabel('Delay (hours)').fill('2');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByTestId('snackbar')).toContainText('Settings saved');

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/now/`);
        await expect(page.locator('.threadi-now-pages-digest-entry')).toHaveText(['Happened three hours ago']);
        await expect(page.locator('.threadi-now-pages-digest-delay')).toContainText('last 2 hours');
    });

    // @ts-ignore
    test('Sharing in the Fediverse is locked without the ActivityPub plugin', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=threadi_now_pages_settings`);
        await page.getByRole('tab', { name: 'Fediverse', exact: true }).click();

        const panel = page.getByRole('tabpanel');
        await expect(panel).toContainText('This needs the plugin ActivityPub');
        await expect(panel.getByRole('link', { name: 'Install it' })).toBeVisible();

        // nothing can be chosen, and nothing is shared.
        await expect(panel.getByRole('combobox')).toBeDisabled();
        await expect(panel.getByRole('combobox')).toHaveValue('off');
    });
});
