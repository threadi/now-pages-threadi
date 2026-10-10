import { test, expect } from './fixtures';
import { php, removeContent, resetSetup } from './helpers';
// @ts-ignore
import type { Page } from '@playwright/test';

/**
 * Click through the setup with the source "This website", which needs no account and no other server.
 */
async function runSetup(page: Page, serverUrl: string, choice: string): Promise<void> {
    await page.goto(`${serverUrl}/wp-admin/options-general.php?page=now_pages_threadi_settings-setup`);

    const continueButton = page.getByRole('button', { name: 'Continue' });
    const firstChoice = page.getByRole('radio', { name: 'Create a page I can design myself' });

    // wait until the setup has loaded its values: before that, a choice would be overwritten by the default.
    await expect(firstChoice).toBeChecked();
    await page.getByRole('radio', { name: choice }).check();
    await continueButton.click();

    // choose what to show.
    const source = page.getByRole('button', { name: /^This website/ });
    await expect(source).toBeVisible();
    await source.click();
    await expect(continueButton).toBeEnabled();
    await continueButton.click();

    // nothing to fill in for this source.
    await expect(page.getByText('This website needs no further details.')).toBeVisible();
    await continueButton.click();

    // the setup creates the source and imports its entries.
    const completedButton = page.getByRole('button', { name: 'Completed' });
    await expect(completedButton).toBeEnabled({ timeout: 90_000 });
    await completedButton.click();

    // it ends on the list of sources, with the new one in it.
    await expect(page).toHaveURL(/edit\.php\?post_type=np_threadi_source/);
    await expect(page.getByRole('link', { name: 'This website', exact: true })).toBeVisible();
}

test.describe('Setup', () => {
    // @ts-ignore
    test.beforeEach(async ({ cli }) => {
        await resetSetup(cli);
    });

    // @ts-ignore
    test.afterEach(async ({ cli }) => {
        await removeContent(cli);
    });

    // @ts-ignore
    test('Before the setup the now page does not exist', async ({ visitor, cli }) => {
        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/now/`);

        expect(response?.status()).toBe(404);
    });

    // @ts-ignore
    test('Setup without a page makes the address /now/ available', async ({ page, visitor, cli }) => {
        // @ts-ignore
        await runSetup(page, cli.serverUrl, 'Just the address /now/, without a page');

        // no page has been created.
        expect(await php(cli, `echo \\NowPagesThreadi\\NowPages\\Now_Page::get_instance()->get_page() ? 'page' : 'no page';`)).toBe('no page');

        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/now/`);
        expect(response?.status()).toBe(200);
        await expect(visitor.getByRole('heading', { level: 1, name: 'Now' })).toBeVisible();
    });

    // @ts-ignore
    test('Setup with a page creates a page for the now page', async ({ page, visitor, cli }) => {
        // @ts-ignore
        await runSetup(page, cli.serverUrl, 'Create a page I can design myself');

        // the page exists, is published and has the address of the now page.
        expect(await php(cli, `$page = \\NowPagesThreadi\\NowPages\\Now_Page::get_instance()->get_page(); echo $page ? $page->post_type . '/' . $page->post_status . '/' . $page->post_name : 'no page';`)).toBe('page/publish/now');

        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/now/`);
        expect(response?.status()).toBe(200);
        await expect(visitor.getByRole('heading', { level: 1, name: 'Now' })).toBeVisible();
    });

    // needs easy-setup-for-wordpress with the lock of the "Continue" button (newer than 1.5.0).
    // @ts-ignore
    test('Clicking "Continue" twice does not skip a step', async ({ page, cli }) => {
        // count how often the setup saves.
        let saves = 0;
        page.on('request', (request: { url: () => string; method: () => string }) => {
            if ('POST' === request.method() && request.url().includes('/easy-setup-for-wordpress/v1/save-values')) {
                saves++;
            }
        });

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/options-general.php?page=now_pages_threadi_settings-setup`);

        // choose as soon as the choice is offered: it is only offered once the setup has loaded its values.
        await page.getByRole('radio', { name: 'Just the address /now/, without a page' }).check();
        await page.getByRole('button', { name: 'Continue' }).dblclick();

        // the next step is the choice of the source, and it stays that once everything has been answered.
        const source = page.getByRole('button', { name: /^This website/ });
        await expect(source).toBeVisible();
        await page.waitForLoadState('networkidle');
        await expect(source).toBeVisible();

        // one click has been ignored, and the choice has been saved as made.
        expect(saves).toBe(1);
        expect(await php(cli, `echo \\easySetupForWordPress\\Setup::get_instance()->get_field_value( 'now-pages-threadi', 'now_pages_threadi_setup_create_page', '' );`)).toBe('virtual');
    });
});
