import assert from 'node:assert/strict';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node sudo-replay.mjs <run>');
const account = fixture(run);
const { page, step, close } = await open(run, 'sudo-replay');

try {
    // The replayed-code step needs the sign-in and the replay inside one 30-second TOTP step.
    const intoStep = Date.now() % 30_000;
    if (intoStep > 5_000) {
        await new Promise((resolve) => setTimeout(resolve, 30_000 - intoStep + 500));
    }

    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password').fill(account.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    await page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key));
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByText("You're signed in.").waitFor();
    await step('signed-in');

    await page.getByRole('link', { name: 'Page behind sudo' }).click();
    await page.getByRole('heading', { name: 'A page behind sudo' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/gated', 'a fresh sign-in passes the gate');
    await step('gated-page-with-sudo');

    await page.getByRole('link', { name: 'Home' }).click();
    await page.getByRole('button', { name: 'End sudo' }).waitFor();
    await page.getByRole('button', { name: 'End sudo' }).click();
    await page.getByText('Sudo has ended.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'ending sudo lands on the security page with the status');
    await step('sudo-ended');

    await page.goto('/');
    await page.getByRole('link', { name: 'Page behind sudo' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/sudo', 'the gate sends a session without sudo to the sudo page');
    assert.equal(await page.getByLabel('Email address').count(), 0, 'the sudo page asks for no email address');
    await step('sudo-page-first-step');

    await page.getByLabel('Password').fill('wrong ' + account.password);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByText('The provided credential is invalid.').waitFor();
    await step('sudo-page-refused');

    await page.getByLabel('Password').fill(account.password);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByLabel('Code from your authenticator app').waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/sudo', 'the second step renders on the same page');
    await step('sudo-page-second-step');

    await page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key));
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByText('The provided credential is invalid.').waitFor();
    await step('sudo-page-replayed-code-refused');

    await page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key, Date.now() + 30_000));
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.getByRole('heading', { name: 'A page behind sudo' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/gated', 'the granted sudo lands back on the intended page');
    await step('granted-back-on-intended-page');
} finally {
    await close();
}
