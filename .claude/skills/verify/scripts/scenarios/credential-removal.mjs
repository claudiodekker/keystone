import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node credential-removal.mjs <run>');
const account = fixture(run);
const sql = (query) => execFileSync(join(dirname(fileURLToPath(import.meta.url)), '../app.sh'), ['sql', run, query], { encoding: 'utf8' });

sql("insert into user_credentials (user_id, type, label, created_at, updated_at) values (1, 'uninstalled', 'Old security key', datetime('now'), datetime('now'))");

const other = await open(run, 'credential-removal-other-browser');
const { page, step, close } = await open(run, 'credential-removal');

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

try {
    await signIn(other.page, async (page) => {
        await page.getByRole('button', { name: 'recovery-code' }).click();
        await page.getByLabel('Recovery code').fill(account.recovery_codes[0]);
    });
    await other.step('other-browser-signed-in');

    await signIn(page, (page) => page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key)));
    await page.getByRole('link', { name: 'Security settings' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    await step('security-page-offers-removal');

    await page.getByRole('link', { name: 'Remove Authenticator app' }).click();
    await page.getByRole('heading', { name: 'Remove Authenticator app?' }).waitFor();
    await step('confirm-last-second-factor');
    await page.getByRole('button', { name: 'Remove' }).click();
    await page.getByText('You cannot remove your last two-factor credential while two-factor authentication is required.').waitFor();
    await step('last-second-factor-refused');

    await page.getByRole('link', { name: 'Keep it' }).click();
    await page.getByRole('link', { name: 'Remove Password' }).click();
    await page.getByRole('heading', { name: 'Remove Password?' }).waitFor();
    await page.getByRole('button', { name: 'Remove' }).click();
    await page.getByText('You cannot remove your only way to sign in.').waitFor();
    await step('last-sign-in-credential-refused');

    await page.getByRole('link', { name: 'Keep it' }).click();
    await page.getByRole('button', { name: 'End sudo' }).click();
    await page.getByText('Sudo has ended.').waitFor();
    await page.getByRole('link', { name: 'Remove Old security key' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/sudo', 'the confirm step asks for sudo first');
    await step('confirm-step-asks-for-sudo');
    await page.getByLabel('Password').fill(account.password);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByRole('button', { name: 'recovery-code' }).click();
    await page.getByLabel('Recovery code').fill(account.recovery_codes[1]);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByRole('heading', { name: 'Remove Old security key?' }).waitFor();
    await page.getByText('This app no longer accepts it.').waitFor();
    await step('confirm-leftover-after-sudo');

    await page.getByRole('button', { name: 'Remove' }).click();
    await page.getByText('The credential was removed. Your other sessions were signed out.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'a removal lands on the security page');
    assert.equal(await page.getByText('Old security key').count(), 0, 'the leftover is gone');
    await step('leftover-removed');

    await page.goto('/');
    await page.getByText("You're signed in.").waitFor();

    await other.page.goto('/');
    await other.page.getByRole('link', { name: 'Sign in' }).waitFor();
    await other.step('other-browser-signed-out');

    const events = sql("select type, credential_type, credential_label from user_security_events where type = 'credential.removed'");
    assert.match(events, /credential\.removed\s+uninstalled\s+Old security key/, 'the removal is on the trail');
    console.log(events.trim());
} finally {
    await close();
    await other.close();
}
