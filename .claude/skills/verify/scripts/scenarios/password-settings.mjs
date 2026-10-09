import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node password-settings.mjs <run>');
const account = fixture(run);
const sql = (query) => execFileSync(join(dirname(fileURLToPath(import.meta.url)), '../app.sh'), ['sql', run, query], { encoding: 'utf8' }).trim();
const value = (query) => sql(query).split('\n').pop().trim();
const changed = 'violet-harbour-lantern-42';
const added = 'copper-meadow-whistle-17';

const guest = await open(run, 'password-settings-guest');
const { page, step, close } = await open(run, 'password-settings');

const section = (name) => page.locator('section').filter({ has: page.getByRole('heading', { name, exact: true }) });
const field = (label) => page.locator('div').filter({ has: page.getByLabel(label, { exact: true }) }).last();
const autocomplete = (label) => page.getByLabel(label, { exact: true }).getAttribute('autocomplete');

const signIn = async (password, answer) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    await answer();
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByText("You're signed in.").waitFor();
};

const openSecurity = async () => {
    await page.goto('/settings/security');
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
};

try {
    await guest.page.goto('/.well-known/change-password');
    await guest.page.getByRole('heading', { name: 'Sign in' }).waitFor();
    assert.equal(new URL(guest.page.url()).pathname, '/auth/login', 'a guest goes on from the change-password URL to sign in');
    await guest.step('guest-well-known-signs-in');

    await signIn(account.password, () => page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key)));

    await page.goto('/.well-known/change-password');
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'the change-password URL opens the security page');
    await section('Password').getByText('Set', { exact: true }).waitFor();
    const epoch = Number(value('select credential_epoch from users where id = 1'));
    await step('security-page-offers-change');

    await page.getByRole('link', { name: 'Change Password' }).click();
    await page.getByRole('heading', { name: 'Change password' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security/enroll/password', 'the Change link opens the password step');
    assert.equal(await autocomplete('Current password'), 'current-password', 'the current password is filled from the password manager');
    assert.equal(await autocomplete('New password'), 'new-password', 'the new password is one the password manager makes');
    assert.equal(await autocomplete('Confirm new password'), 'new-password', 'the confirmation is the new password too');
    assert.equal(await page.getByRole('link', { name: 'Remove password' }).count(), 0, 'the only way to sign in offers no removal');
    await step('change-form');

    await page.getByLabel('Current password', { exact: true }).fill('not my password');
    await page.getByLabel('New password', { exact: true }).fill(changed);
    await page.getByLabel('Confirm new password', { exact: true }).fill(changed);
    await page.getByRole('button', { name: 'Change password' }).click();
    await field('Current password').getByText('The provided credential is invalid.').waitFor();
    assert.equal(await field('New password').getByText('The provided credential is invalid.').count(), 0, 'the refusal is not shown under the new password');
    assert.equal(await page.getByLabel('Current password', { exact: true }).inputValue(), '', 'the typed passwords are cleared');
    await step('wrong-current-password-refused');

    await page.getByLabel('Current password', { exact: true }).fill(account.password);
    await page.getByLabel('New password', { exact: true }).fill('short');
    await page.getByLabel('Confirm new password', { exact: true }).fill('short');
    await page.getByRole('button', { name: 'Change password' }).click();
    await field('New password').getByText('The password field must be at least 8 characters.').waitFor();
    await step('weak-new-password-refused');

    await page.getByLabel('Current password', { exact: true }).fill(account.password);
    await page.getByLabel('New password', { exact: true }).fill(changed);
    await page.getByLabel('Confirm new password', { exact: true }).fill(changed);
    await page.getByRole('button', { name: 'Change password' }).click();
    await page.getByText('Your password was changed. Your other sessions were signed out.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'a change lands on the security page');
    assert.equal(await page.getByRole('button', { name: 'Sign out your other sessions' }).count(), 0, 'a change already signed the others out, so it offers nothing');
    await step('password-changed');
    assert.equal(Number(value('select credential_epoch from users where id = 1')), epoch + 1, 'the change moved the credential epoch');

    await page.getByRole('link', { name: 'Remove Password' }).click();
    await page.getByRole('heading', { name: 'Remove Password?' }).waitFor();
    await page.getByRole('button', { name: 'Remove' }).click();
    await page.getByText('You cannot remove your only way to sign in.').waitFor();
    await step('only-password-kept');

    sql("delete from user_credentials where user_id = 1 and type = 'password'");
    await openSecurity();
    await section('Password').getByText('Not set', { exact: true }).waitFor();
    await page.getByRole('link', { name: 'Set up Password' }).click();
    await page.getByRole('heading', { name: 'Set up Password' }).waitFor();
    await page.getByText('Your account does not have a password set.').waitFor();
    assert.equal(await page.getByLabel('Current password', { exact: true }).count(), 0, 'a first password asks for no current one');
    assert.equal(await autocomplete('New password'), 'new-password', 'the new password is one the password manager makes');
    await step('add-form');

    await page.getByLabel('New password', { exact: true }).fill(added);
    await page.getByLabel('Confirm new password', { exact: true }).fill(added);
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByText('The new credential was added.').waitFor();
    await page.getByRole('button', { name: 'Sign out your other sessions' }).waitFor();
    await step('password-added');
    assert.equal(Number(value('select credential_epoch from users where id = 1')), epoch + 1, 'a first password moved no epoch');

    await page.goto('/');
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await page.getByRole('heading', { name: 'Sign in' }).waitFor();
    await signIn(added, async () => {
        await page.getByRole('button', { name: 'recovery-code' }).click();
        await page.getByLabel('Recovery code').fill(account.recovery_codes[0]);
    });
    await step('signed-in-with-added-password');

    const events = sql("select type, flow, credential_type, reason from user_security_events where credential_type = 'password' and type <> 'signed_in' order by id");
    assert.match(events, /proof\.rejected\s+settings\s+password\s+password\.mismatch/, 'the wrong current password is on the trail in the settings flow');
    assert.match(events, /credential\.replaced\s+settings\s+password/, 'the change is on the trail');
    assert.match(events, /credential\.added\s+settings\s+password/, 'the first password is on the trail');
    console.log(events);
} finally {
    await close();
    await guest.close();
}
