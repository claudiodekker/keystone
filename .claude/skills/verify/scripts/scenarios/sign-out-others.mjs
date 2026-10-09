import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node sign-out-others.mjs <run>');
const account = fixture(run);
const sql = (query) => execFileSync(join(dirname(fileURLToPath(import.meta.url)), '../app.sh'), ['sql', run, query], { encoding: 'utf8' });

const other = await open(run, 'sign-out-others-other-browser');
const { page, step, close } = await open(run, 'sign-out-others');

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

const signInOther = (code) =>
    signIn(other.page, async (page) => {
        await page.getByRole('button', { name: 'recovery-code' }).click();
        await page.getByLabel('Recovery code').fill(code);
    });

const assertOtherSignedOut = async (label) => {
    await other.page.goto('/');
    await other.page.getByRole('link', { name: 'Sign in' }).waitFor();
    await other.step(label);
};

try {
    await signInOther(account.recovery_codes[0]);
    await other.step('other-browser-signed-in');

    await signIn(page, (page) => page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key)));
    await page.getByRole('link', { name: 'Security settings' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Sign out your other sessions' }).count(), 0, 'the offer waits for an enrollment');
    await step('security-page-links-to-sign-out');

    await page.getByRole('link', { name: 'Sign out other sessions' }).click();
    await page.getByRole('heading', { name: 'Sign out other sessions?' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security/sessions/others/revoke', 'the link opens the confirm step');
    await step('confirm-step');

    await page.getByRole('button', { name: 'Sign out other sessions' }).click();
    await page.getByText('Your other sessions were signed out.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'the sign-out lands on the security page');
    await step('other-sessions-signed-out');

    await page.goto('/');
    await page.getByText("You're signed in.").waitFor();
    await assertOtherSignedOut('other-browser-signed-out');

    await signInOther(account.recovery_codes[1]);
    await other.step('other-browser-signed-in-again');

    await page.goto('/settings/security');
    await page.getByRole('link', { name: 'Set up Authenticator app' }).click();
    await page.getByRole('heading', { name: 'Set up Authenticator app' }).waitFor();
    const key = (await page.locator('code').innerText()).replace(/\s+/g, '');
    await page.getByLabel('Code from your authenticator app').fill(totp(key));
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByText('The new credential was added.').waitFor();
    await page.getByRole('button', { name: 'Sign out your other sessions' }).waitFor();
    await step('enrollment-offers-sign-out');

    await page.getByRole('button', { name: 'Sign out your other sessions' }).click();
    await page.getByText('Your other sessions were signed out.').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Sign out your other sessions' }).count(), 0, 'the offer is gone once taken');
    await step('offer-taken');

    await page.goto('/');
    await page.getByText("You're signed in.").waitFor();
    await assertOtherSignedOut('other-browser-signed-out-again');

    const events = sql("select id, type from user_security_events where type = 'sessions.revoked_others'");
    assert.equal(events.match(/sessions\.revoked_others/g)?.length, 2, 'both sign-outs are on the trail');
    console.log(events.trim());
} finally {
    await close();
    await other.close();
}
