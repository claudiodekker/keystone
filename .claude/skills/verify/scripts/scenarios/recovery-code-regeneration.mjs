import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node recovery-code-regeneration.mjs <run>');
const account = fixture(run);
const sql = (query) => execFileSync(join(dirname(fileURLToPath(import.meta.url)), '../app.sh'), ['sql', run, query], { encoding: 'utf8' }).trim();
const value = (query) => sql(query).split('\n').pop().trim();

const other = await open(run, 'recovery-code-regeneration-other-browser');
const { page, step, close } = await open(run, 'recovery-code-regeneration');

const signIn = async (page, answer) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password').fill(account.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    await answer(page);
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByText("You're signed in.").waitFor();
};

const staged = () => page.locator('ul.font-mono li').allInnerTexts();

const openStep = async () => {
    await page.goto('/settings/security');
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    await page.getByRole('link', { name: 'Regenerate' }).click();
    await page.getByRole('heading', { name: 'Regenerate recovery codes' }).waitFor();
};

try {
    await signIn(other.page, async (page) => {
        await page.getByRole('button', { name: 'recovery-code' }).click();
        await page.getByLabel('Recovery code').fill(account.recovery_codes[0]);
    });
    await other.step('other-browser-signed-in');

    await signIn(page, (page) => page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key)));
    const epoch = Number(value('select credential_epoch from users where id = 1'));

    await openStep();
    assert.equal(new URL(page.url()).pathname, '/settings/security/recovery-codes/regenerate', 'the Regenerate link opens the step');
    const first = await staged();
    assert.equal(first.length, 8, 'a set of eight codes is staged');
    await page.getByText('Saving these replaces your current recovery codes').waitFor();
    assert.equal(await page.getByLabel('Type one of the codes to confirm you saved them').getAttribute('autocomplete'), 'off', 'the typed code is not remembered by the browser');
    await step('staged-set');

    await page.reload();
    await page.getByRole('heading', { name: 'Regenerate recovery codes' }).waitFor();
    assert.deepEqual(await staged(), first, 'a reload shows the same set');
    assert.equal(Number(value('select count(*) from user_recovery_codes where user_id = 1')), 7, 'nothing is stored before a code is typed back');

    await page.getByLabel('Type one of the codes to confirm you saved them').fill('not-one-of-them');
    await page.getByRole('button', { name: 'I saved them' }).click();
    await page.getByText('The recovery code you entered is incorrect.').waitFor();
    assert.deepEqual(await staged(), first, 'a wrong code keeps the set');
    assert.equal(await page.getByLabel('Type one of the codes to confirm you saved them').inputValue(), '', 'the wrong code is cleared');
    await step('wrong-code-refused');

    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    await page.getByText('7 codes left').waitFor();
    await step('cancelled');

    await page.getByRole('link', { name: 'Regenerate' }).click();
    await page.getByRole('heading', { name: 'Regenerate recovery codes' }).waitFor();
    const second = await staged();
    assert.notDeepEqual(second, first, 'the next visit stages another set');

    await page.getByLabel('Type one of the codes to confirm you saved them').fill(second[3]);
    await page.getByRole('button', { name: 'I saved them' }).click();
    await page.getByText('Your recovery codes were regenerated.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'saving lands on the security page');
    await page.getByText('8 codes left').waitFor();
    await step('regenerated');

    assert.equal(Number(value('select credential_epoch from users where id = 1')), epoch + 1, 'replacing a live set moved the credential epoch');

    await page.goto('/');
    await page.getByText("You're signed in.").waitFor();
    await other.page.goto('/');
    await other.page.getByRole('link', { name: 'Sign in' }).waitFor();
    await other.step('other-browser-signed-out');

    const events = sql("select type, flow, credential_type, reason from user_security_events where credential_type = 'recovery-code' and type in ('proof.rejected', 'recovery_codes.generated') order by id");
    assert.match(events, /proof\.rejected\s+settings\s+recovery-code\s+recovery-code\.mismatch/, 'the wrong code is on the trail in the settings flow');
    assert.match(events, /recovery_codes\.generated\s+settings\s+recovery-code/, 'the new set is on the trail in the settings flow');
    console.log(events);
} finally {
    await close();
    await other.close();
}
