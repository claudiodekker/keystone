import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node registration.mjs <run>');
const appSh = join(dirname(fileURLToPath(import.meta.url)), '../app.sh');
const sql = (query) => execFileSync(appSh, ['sql', run, query], { encoding: 'utf8' }).trim();
const value = (query) => sql(query).split('\n').pop().trim();
const address = `new-${Date.now()}@example.com`;
const cancelled = `cancelled-${Date.now()}@example.com`;
const password = 'violet harbor lantern 42';

const mailedLink = (mails, to) => {
    const message = mails.split(/^\[[^\]]+\] \S+: From: /m).find((mail) => mail.includes(`\nTo: ${to}\n`));
    const body = message?.replace(/=\r?\n/g, '').replaceAll('=3D', '=') ?? '';
    const href = body.match(/href="([^"]+\/auth\/register\/verify\?[^"]+)"/)?.[1];

    return href?.replaceAll('&amp;', '&');
};

const other = await open(run, 'registration-other-browser');
const { page, step, close } = await open(run, 'registration');

const spendLink = async (link) => {
    await page.goto(link);
    await page.getByRole('heading', { name: 'Continue from your email' }).waitFor();
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.getByRole('heading', { name: 'Finish creating your account' }).waitFor();
};

const askForLink = async (email) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Sign in' }).click();
    await page.getByRole('link', { name: 'Create an account' }).click();
    await page.getByRole('heading', { name: 'Create an account' }).waitFor();
    await page.getByLabel('Email address').fill(email);
    await page.getByRole('button', { name: 'Send me a link' }).click();
    await page.getByRole('heading', { name: 'Check your email' }).waitFor();
};

try {
    await askForLink(address);
    assert.equal(new URL(page.url()).pathname, '/auth/register/link-sent', 'a free address lands on "check your email"');
    await step('free-address-sent');

    await askForLink('jane@example.com');
    assert.equal(new URL(page.url()).pathname, '/auth/register/link-sent', 'a taken address lands on the same step');
    await step('taken-address-sent');

    const mails = execFileSync(appSh, ['mail', run], { encoding: 'utf8' }).replaceAll('\r\n', '\n');
    const link = mailedLink(mails, address) ?? assert.fail(`no registration link was mailed to ${address}`);
    assert.equal(mailedLink(mails, 'jane@example.com'), undefined, 'a taken address is mailed no link');
    assert.match(mails, /To: jane@example\.com\nSubject: Someone tried to sign up with your email address/, 'the owner of a taken address is alerted');
    assert.ok(link.startsWith(page.url().split('/auth/')[0]), 'the link is built on app.url');
    assert.ok(!decodeURIComponent(link).includes(address), 'the link never shows the address');

    const opened = await page.goto(link);
    await page.getByRole('heading', { name: 'Continue from your email' }).waitFor();
    assert.equal(opened.headers()['referrer-policy'], 'no-referrer', "the link's step sends no referrer");
    assert.equal(value('select count(*) from used_email_links'), '0', 'opening the link spends nothing');
    await step('link-opened');

    await page.getByRole('button', { name: 'Continue' }).click();
    await page.getByRole('heading', { name: 'Finish creating your account' }).waitFor();
    await page.getByText(address).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/register/finish', 'spending the link lands on the finish page');
    assert.equal(value('select count(*) from used_email_links'), '1', 'the button spends the link');
    await step('link-spent');

    await other.page.goto(link);
    await other.page.getByRole('heading', { name: 'Continue from your email' }).waitFor();
    await other.page.getByRole('button', { name: 'Continue' }).click();
    await other.page.getByRole('heading', { name: 'That link is no longer valid' }).waitFor();
    assert.equal(new URL(other.page.url()).pathname, '/auth/register/link-expired', 'a spent link is refused in another browser');
    await other.step('link-replayed');

    await other.page.goto('/auth/register/finish');
    await other.page.getByRole('heading', { name: 'Create an account' }).waitFor();
    assert.equal(await other.page.getByText('Your registration expired').count(), 0, 'the finish page says nothing to a browser that spent no link');
    await other.step('finish-without-a-link');

    const events = sql("select type, flow, user_id from user_security_events where type = 'address.claim_attempted' order by id");
    assert.match(events, /address\.claim_attempted\s+registration\s+1/, "the claim on Jane's address is on her trail");
    console.log(events);

    await page.getByLabel('Name').fill('Nova Tester');
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await step('finish-filled');
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('heading', { name: 'Set up two-factor authentication' }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/login/enrollment', 'a new account the mandates hold lands on the enrollment it owes');
    const account = value(`select user_id from user_emails where address = '${address}' and verified_address = '${address}' and is_primary = 1`);
    assert.match(account, /^\d+$/, 'the account holds the proven address, verified and primary');
    await page.getByRole('button', { name: 'Sign out' }).waitFor();
    await step('enrollment-owed');

    await page.getByRole('link', { name: 'totp' }).click();
    await page.getByLabel('Code from your authenticator app').waitFor();
    await page.getByLabel('Code from your authenticator app').fill(totp(await page.locator('code').innerText()));
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByRole('heading', { name: 'Save your recovery codes' }).waitFor();
    await step('recovery-codes-owed');
    await page.getByLabel('Type one of the codes to confirm you saved them').fill(await page.locator('li').first().innerText());
    await page.getByRole('button', { name: 'I saved them' }).click();
    await page.getByText("You're signed in.").waitFor();
    await step('signed-in');

    const trail = sql(`select type, flow, known_device from user_security_events where user_id = ${account} order by id`);
    console.log(trail);
    const rows = trail.split('\n').map((row) => row.trim().split(/\s+/));
    const has = (type, flow) => rows.some(([t, f]) => t === type && f === flow);
    assert.ok(has('account.registered', 'registration'), 'creating the account records account.registered');
    assert.ok(has('sign_in.held', 'registration'), 'the new account is held for its enrollment');
    assert.ok(has('enrollment.completed', 'enrollment'), 'finishing the owed enrollment records enrollment.completed');
    assert.ok(rows.some(([t, f, known]) => t === 'signed_in' && f === 'enrollment' && known === '0'), 'the new account signs in from a browser it never knew');
    assert.ok(!has('credential.added', 'registration'), 'the first password records no credential.added');

    const welcome = execFileSync(appSh, ['mail', run], { encoding: 'utf8' }).replaceAll('\r\n', '\n');
    assert.match(welcome, new RegExp(`To: ${address.replaceAll('.', '\\.')}\nSubject: Welcome to `), 'the new address gets the welcome mail');
    assert.doesNotMatch(welcome, /Subject: New sign-in/, 'the sign-in ending the registration alerts nobody');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.getByRole('heading', { name: 'Sign in' }).waitFor();
    await askForLink(cancelled);
    const cancelledMails = execFileSync(appSh, ['mail', run], { encoding: 'utf8' }).replaceAll('\r\n', '\n');
    await spendLink(mailedLink(cancelledMails, cancelled) ?? assert.fail(`no registration link was mailed to ${cancelled}`));
    await page.getByRole('button', { name: 'Cancel registration' }).click();
    await page.getByText('Registration cancelled. No account was created.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/auth/register', 'cancelling lands on the register page');
    assert.equal(value(`select count(*) from user_emails where address = '${cancelled}'`), '0', 'a cancelled registration creates nothing');
    await step('registration-cancelled');
} finally {
    await close();
    await other.close();
}
