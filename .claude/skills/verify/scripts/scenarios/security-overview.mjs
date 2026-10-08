import assert from 'node:assert/strict';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node security-overview.mjs <run>');
const account = fixture(run);
const { page, step, close } = await open(run, 'security-overview');

try {
    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password').fill(account.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key));
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.getByText("You're signed in.").waitFor();

    await page.getByRole('link', { name: 'Security settings' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'the security page opens from home');

    const section = (name) => page.locator('section').filter({ has: page.getByRole('heading', { name, exact: true }) });
    await section('Password').getByText('Set', { exact: true }).waitFor();
    assert.equal(await section('Password').getByText('last used never').count(), 0, 'the password that signed in has a last use');
    assert.equal(await section('Authenticator app').locator('li').count(), 1, 'the authenticator is listed once');
    assert.equal(await section('Authenticator app').getByText('last used never').count(), 0, 'the code that answered the challenge has a last use');
    await section('Recovery codes').getByText('8 codes left').waitFor();
    assert.equal(await page.getByText("You're running low on recovery codes.").count(), 0, 'a full set is not running low');
    await section('Sudo').getByText("Changes won't ask again until").waitFor();
    await step('overview-with-sudo');

    await section('Sudo').getByRole('button', { name: 'End sudo' }).click();
    await page.getByText('Sudo has ended.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'ending sudo lands back on the security page');
    await section('Sudo').getByText("Your next change will ask you to confirm it's you.").waitFor();
    await step('overview-after-ending-sudo');

    await page.reload();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    assert.equal(await page.getByText('Sudo has ended.').count(), 0, 'the status shows once');
    await step('overview-reloaded');
} finally {
    await close();
}
