// @ts-ignore
import { test as base, type Page } from '@playwright/test';
// @ts-ignore
import { runCLI } from '@wp-playground/cli';
// @ts-ignore
import { execSync } from 'node:child_process';
// @ts-ignore
import { existsSync, statSync } from 'node:fs';
// @ts-ignore
import path from 'node:path';

// @ts-ignore
const pluginRoot = path.resolve(__dirname, '../..');
const autoloadPath = path.join(pluginRoot, 'vendor', 'autoload.php');
const lockPath = path.join(pluginRoot, 'composer.lock');

function needsComposerInstall(): boolean {
    if (!existsSync(autoloadPath)) return true;
    if (!existsSync(lockPath)) return false;
    return statSync(lockPath).mtimeMs > statSync(autoloadPath).mtimeMs;
}

function ensureComposerDeps(): void {
    if (!needsComposerInstall()) {
        console.log('vendor/autoload.php is up to date – do not run composer install.');
        return;
    }
    console.log('Running composer install …');
    execSync('composer install --optimize-autoloader', {
        cwd: pluginRoot,
        stdio: 'inherit',
    });
}

type CliServer = Awaited<ReturnType<typeof runCLI>>;

export const test = base.extend<{ visitor: Page }, { cli: CliServer }>({
    // a page of somebody who is not logged in. "page" is always the administrator:
    // Playground logs every browser in, unless this cookie says it already did.
    // @ts-ignore
    visitor: async ({ browser, cli }, use) => {
        const context = await browser.newContext();
        await context.addCookies([
            // @ts-ignore
            { name: 'playground_auto_login_already_happened', value: '1', url: cli.serverUrl },
        ]);

        await use(await context.newPage());

        await context.close();
    },

    // worker-scoped: läuft einmal pro Test-Worker, nicht pro Test
    cli: [
        // @ts-ignore
        async ({}, use) => {
            ensureComposerDeps();

            const cli = await runCLI({
                command: 'server',
                workers: 6,
                mount: [
                    {
                        hostPath: './',
                        vfsPath: '/wordpress/wp-content/plugins/threadi-now-pages',
                    },
                ],
                blueprint: {
                    preferredVersions: { php: '8.3', wp: 'latest' },
                    login: true,
                    steps: [
                        {
                            step: 'activatePlugin',
                            pluginPath: 'threadi-now-pages/threadi-now-pages.php',
                        },
                    ],
                },
            });

            // the very first request to a new Playground server is answered with a redirect
            // which also removes the cookie the "visitor" above relies on. Get it out of the way.
            // @ts-ignore
            await fetch(`${cli.serverUrl}/`, { redirect: 'manual' });

            await use(cli);

            await cli?.server?.close();
        },
        { scope: 'worker' },
    ],
});

// @ts-ignore
export { expect } from '@playwright/test';
