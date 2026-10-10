import { test, expect } from './fixtures';
import { completeSetup, php, removeContent } from './helpers';

/**
 * Create a source which repeats a sentence every day and asks before it is shown,
 * and let the import run once. Returns the ID of the source.
 */
async function addRoutineWhichAsks(cli: any): Promise<number> {
    const id = await php(
        cli,
        `$source_id = \\NowPagesThreadi\\Sources\\Source_Builder::get_instance()->insert(
            'recurring',
            array(
                'recurring_text'    => 'Went to the gym',
                'recurring_rhythm'  => 'daily',
                'recurring_confirm' => 1,
            ),
            array( 'title' => 'Sports' )
        );
        \\NowPagesThreadi\\Sources\\Sources::get_instance()->import();
        echo $source_id;`
    );

    return parseInt(id, 10);
}

/**
 * Return the status of the only entry of a source.
 */
async function getEntryStatus(cli: any, sourceId: number): Promise<string> {
    return php(
        cli,
        `$entries = get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => array( 'any', 'trash' ), 'post_parent' => (int) $args['source'], 'numberposts' => -1 ) );
        echo implode( ',', wp_list_pluck( $entries, 'post_status' ) );`,
        { source: sourceId }
    );
}

test.describe('Recurring entries', () => {
    // @ts-ignore
    test.beforeEach(async ({ cli }) => {
        await removeContent(cli);
        await completeSetup(cli);
    });

    // @ts-ignore
    test('Something done regularly is added with one sentence', async ({ page, cli }) => {
        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post-new.php?post_type=np_threadi_source`);

        const continueButton = page.getByRole('button', { name: 'Continue' });

        await page.getByRole('button', { name: /^Something I do regularly/ }).click();
        await continueButton.click();

        await page.getByLabel('Text of the entry').fill('Went to the gym');
        await expect(continueButton).toBeEnabled();
        await continueButton.click();

        const completedButton = page.getByRole('button', { name: 'Completed' });
        await expect(completedButton).toBeEnabled({ timeout: 90_000 });
        await completedButton.click();

        // the source is named after its sentence.
        await expect(page).toHaveURL(/edit\.php\?post_type=np_threadi_source/);
        await expect(page.locator('#the-list tr')).toHaveCount(1);
        await expect(page.locator('#the-list tr')).toContainText('Went to the gym');
        await expect(page.locator('#the-list td.column-now_pages_threadi_provider')).toHaveText('Recurring entry');

        // and its first entry is there already.
        const entries = await php(
            cli,
            `foreach ( get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => 'any', 'numberposts' => -1 ) ) as $entry ) {
                echo $entry->post_status . ':' . $entry->post_content . ';';
            }`
        );
        expect(entries).toBe('publish:Went to the gym;');
    });

    // @ts-ignore
    test('The form of a recurring source offers rhythm, days and the question', async ({ page, cli }) => {
        const sourceId = await addRoutineWhichAsks(cli);

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/post.php?post=${sourceId}&action=edit`);

        await expect(page.getByLabel('Text of the entry')).toHaveValue('Went to the gym');
        await expect(page.getByLabel('Repeat').locator('option')).toHaveText([
            'Once per period of the now page',
            'Every day',
            'Every week',
            'Every month',
        ]);
        await expect(page.getByLabel('Repeat').locator('option:checked')).toHaveText('Every day');
        await expect(page.getByLabel('Days of the week').locator('option')).toHaveCount(7);
        await expect(page.getByLabel('Ask me first')).toBeChecked();

        // what is changed here is stored.
        await page.getByLabel('Repeat').selectOption({ label: 'Every week' });
        await page.getByLabel('Days of the week').selectOption([{ label: 'Tuesday' }, { label: 'Thursday' }]);
        await page.getByRole('button', { name: 'Update' }).click();
        await expect(page.getByText('The source has been updated.')).toBeVisible();

        const stored = await php(
            cli,
            `echo get_post_meta( (int) $args['source'], 'recurring_rhythm', true ) . ':' . implode( ',', (array) get_post_meta( (int) $args['source'], 'recurring_weekdays', true ) );`,
            { source: sourceId }
        );
        expect(stored).toBe('weekly:tue,thu');
    });

    // @ts-ignore
    test('An entry which waits is confirmed in the list of entries', async ({ page, cli }) => {
        const sourceId = await addRoutineWhichAsks(cli);

        expect(await getEntryStatus(cli, sourceId)).toBe('pending');

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=np_threadi_entry`);

        // the menu says that something waits, and the row says what.
        const menuItem = page.locator('#adminmenu a.menu-top').filter({ hasText: 'Now Page Entries' });
        await expect(menuItem.locator('.pending-count')).toHaveText('1');

        const row = page.locator('#the-list tr').filter({ hasText: 'Went to the gym' });
        await expect(row).toContainText('Waiting for your confirmation');

        await row.hover();
        await row.getByRole('link', { name: 'Confirm', exact: true }).click();

        const note = page.getByText('The entry "Went to the gym" has been confirmed.');
        await expect(note).toBeVisible();
        await expect(menuItem.locator('.pending-count')).toHaveCount(0);
        expect(await getEntryStatus(cli, sourceId)).toBe('publish');

        // the note is shown once.
        await page.reload();
        await expect(note).toHaveCount(0);
    });

    // @ts-ignore
    test('An entry which waits is discarded in the list of entries, and stays away', async ({ page, cli }) => {
        const sourceId = await addRoutineWhichAsks(cli);

        // @ts-ignore
        await page.goto(`${cli.serverUrl}/wp-admin/edit.php?post_type=np_threadi_entry`);

        const row = page.locator('#the-list tr').filter({ hasText: 'Went to the gym' });
        await row.hover();
        await row.getByRole('link', { name: 'Discard', exact: true }).click();

        await expect(page.getByText('The entry "Went to the gym" has been discarded.')).toBeVisible();
        expect(await getEntryStatus(cli, sourceId)).toBe('trash');

        // the next import does not bring it back.
        await php(cli, `\\NowPagesThreadi\\Sources\\Sources::get_instance()->import();`);
        expect(await getEntryStatus(cli, sourceId)).toBe('trash');
    });

    // @ts-ignore
    test('The link from the email decides only through its button, without a login', async ({ visitor, cli }) => {
        const sourceId = await addRoutineWhichAsks(cli);

        // the address the email contains: it cannot be read from the email, only its hash is stored.
        const link = await php(
            cli,
            `$entries = get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => 'pending', 'post_parent' => (int) $args['source'] ) );
            $confirmation = \\NowPagesThreadi\\NowPages\\Confirmation::get_instance();
            echo $confirmation->get_link( $entries[0]->ID, $confirmation->create_token( $entries[0]->ID ), 'confirm' );`,
            { source: sourceId }
        );

        // opening the link, as a mail program does it ahead of the reader, changes nothing.
        await visitor.goto(link);
        await expect(visitor.getByText('This entry is waiting for your confirmation:')).toBeVisible();
        await expect(visitor.getByText('Went to the gym')).toBeVisible();
        expect(await getEntryStatus(cli, sourceId)).toBe('pending');

        await visitor.getByRole('button', { name: 'Confirm this entry' }).click();
        await expect(visitor.getByText('The entry "Went to the gym" has been confirmed.')).toBeVisible();
        expect(await getEntryStatus(cli, sourceId)).toBe('publish');

        // the link is used up.
        const response = await visitor.goto(link);
        expect(response?.status()).toBe(404);
        await expect(visitor.getByText('This entry is not waiting for a confirmation anymore.')).toBeVisible();
        await expect(visitor.getByRole('button')).toHaveCount(0);
    });

    // @ts-ignore
    test('A link with a wrong token shows nothing', async ({ visitor, cli }) => {
        const sourceId = await addRoutineWhichAsks(cli);

        const entryId = await php(
            cli,
            `$entries = get_posts( array( 'post_type' => 'np_threadi_entry', 'post_status' => 'pending', 'post_parent' => (int) $args['source'] ) );
            echo $entries[0]->ID;`,
            { source: sourceId }
        );

        // @ts-ignore
        const response = await visitor.goto(`${cli.serverUrl}/?now_pages_threadi_confirm=${entryId}&decision=confirm&token=guessed`);
        expect(response?.status()).toBe(404);
        await expect(visitor.getByText('Went to the gym')).toHaveCount(0);
        expect(await getEntryStatus(cli, sourceId)).toBe('pending');
    });
});
