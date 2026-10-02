import assert from 'node:assert/strict';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node sign-in-with-totp.mjs <run>');
const account = fixture(run);
const { page, step, close } = await open(run, 'sign-in-with-totp');

try {
    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password').fill(account.password);
    await step('sign-in-filled');

    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: "Confirm it's you" }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/login/challenge', 'password held the sign-in for the challenge');
    await step('challenge');

    await page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key));
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByText("You're signed in.").waitFor();
    await step('signed-in');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByRole('heading', { name: 'Sign in' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/login', 'sign-out lands on the sign-in page');
    await step('signed-out');
} finally {
    await close();
}
