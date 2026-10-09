import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node sessions-list.mjs <run>');
const scripts = join(dirname(fileURLToPath(import.meta.url)), '..');
const runDir = join(scripts, '../../../../.verify/runs', run);
assert.equal(readFileSync(join(runDir, 'session-driver'), 'utf8').trim(), 'database', 'start the run with SESSION_DRIVER=database');

const account = fixture(run);
const sql = (query) => execFileSync(join(scripts, 'app.sh'), ['sql', run, query], { encoding: 'utf8' });
const rows = () => Number(sql('select count(*) as n from sessions where user_id = 1').match(/\d+\s*$/)[0]);

const firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
const other = await open(run, 'sessions-list-other-browser', { userAgent: firefox });
const { page, step, close } = await open(run, 'sessions-list');

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

const sessionsSection = () => page.locator('section').filter({ has: page.getByRole('heading', { name: 'Sessions' }) });

try {
    await signInOther(account.recovery_codes[0]);
    await other.step('other-browser-signed-in');

    await signIn(page, (page) => page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key)));
    await page.getByRole('link', { name: 'Security settings' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    await sessionsSection().getByText('This device').waitFor();
    assert.equal(await sessionsSection().locator('li').count(), 2, 'both browsers are listed');
    assert.equal(await sessionsSection().getByText('This device').count(), 1, 'one row is this device');
    await sessionsSection().getByText('Firefox on Windows').waitFor();
    await step('security-page-lists-both-sessions');

    await page.goto('/settings/security');
    const listed = await page.evaluate(() => JSON.parse(document.querySelector('script[data-page="app"]').textContent).props.sessions);
    const ids = sql('select id from sessions').match(/[A-Za-z0-9]{40}/g);
    const html = await page.content();
    assert.ok(listed.every((session) => /^[0-9a-f]{64}$/.test(session.handle)), 'each row is named by a 64-character handle');
    assert.ok(ids.every((id) => !html.includes(id)), 'no session id reaches the page');

    await page.goto(`/settings/security/sessions/${listed.find((session) => session.current).handle}/revoke`);
    await page.getByText('You cannot revoke your current session; sign out instead.').waitFor();
    await step('this-device-refused');

    await sessionsSection().getByRole('link', { name: 'Sign out Firefox on Windows' }).click();
    await page.getByRole('heading', { name: 'Sign out this session?' }).waitFor();
    await page.getByText('Firefox on Windows').waitFor();
    await step('confirm-step');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByText('The session was signed out.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'the revoke lands on the security page');
    assert.equal(await sessionsSection().locator('li').count(), 1, 'only this device is left');
    await step('session-revoked');

    await other.page.goto('/');
    await other.page.getByRole('link', { name: 'Sign in' }).waitFor();
    await other.step('other-browser-signed-out');

    await page.goto('/');
    await page.getByText("You're signed in.").waitFor();

    const revoked = sql("select type, user_id from user_security_events where type = 'session.revoked'");
    assert.equal(revoked.match(/session\.revoked/g)?.length, 1, 'the revoke is on the trail');
    console.log(revoked.trim());

    await signInOther(account.recovery_codes[1]);
    await other.step('other-browser-signed-in-again');
    assert.equal(rows(), 2, 'both sessions have a row');

    await page.goto('/settings/security');
    await sessionsSection().getByRole('link', { name: 'Sign out other sessions' }).click();
    await page.getByRole('heading', { name: 'Sign out other sessions?' }).waitFor();
    await page.getByRole('button', { name: 'Sign out other sessions' }).click();
    await page.getByText('Your other sessions were signed out.').waitFor();
    assert.equal(rows(), 1, 'signing out the others deletes their rows');
    await step('other-sessions-signed-out');

    await other.page.goto('/');
    await other.page.getByRole('link', { name: 'Sign in' }).waitFor();
    await other.step('other-browser-signed-out-again');

    console.log(sql('select user_id, ip_address, substr(user_agent, 1, 40) as user_agent, last_activity from sessions order by last_activity').trim());
} finally {
    await close();
    await other.close();
}
