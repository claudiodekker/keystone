import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixture, open, totp } from '../pw.mjs';

const run = process.argv[2] ?? assert.fail('usage: node totp-enrollment.mjs <run>');
const account = fixture(run);
const sql = (query) => execFileSync(join(dirname(fileURLToPath(import.meta.url)), '../app.sh'), ['sql', run, query], { encoding: 'utf8' }).trim();
const value = (query) => sql(query).split('\n').pop().trim();
const { page, step, close } = await open(run, 'totp-enrollment');

const section = (name) => page.locator('section').filter({ has: page.getByRole('heading', { name, exact: true }) });
const qr = page.getByRole('img', { name: 'QR code holding the key for your authenticator app' });
const shownKey = () => page.locator('code').innerText();

const openEnrollment = async () => {
    await page.getByRole('link', { name: 'Set up Authenticator app' }).click();
    await page.getByRole('heading', { name: 'Set up Authenticator app' }).waitFor();
    await qr.waitFor();
};

const wrongCode = (key) => {
    const accepted = [-30000, 0, 30000].map((offset) => totp(key, Date.now() + offset));

    return ['000000', '111111', '222222', '333333'].find((code) => !accepted.includes(code));
};

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
    const epoch = Number(value('select credential_epoch from users where id = 1'));
    await step('security-page-offers-set-up');

    await openEnrollment();
    assert.equal(new URL(page.url()).pathname, '/settings/security/enroll/totp', 'the Set up link opens the type\'s enrollment step');
    assert.ok(await qr.evaluate((image) => image.complete && image.naturalWidth > 0), 'the browser draws the QR code');
    assert.match(await qr.getAttribute('src'), /^data:image\/svg\+xml;base64,/, 'the QR code is an SVG the server rendered');
    const key = await shownKey();
    const uri = await page.getByRole('link', { name: 'Open in authenticator app' }).getAttribute('href');
    assert.match(key, /^[A-Z2-7]{32}$/, 'the key is 160 bits in Base32');
    assert.ok(uri.startsWith('otpauth://totp/') && uri.includes(`secret=${key}`), 'the link carries the shown key');
    await page.getByText('this one replaces it').waitFor();

    const scanned = await qr.evaluate(async (image) => ('BarcodeDetector' in window ? (await new BarcodeDetector({ formats: ['qr_code'] }).detect(image))[0]?.rawValue : null));
    if (scanned === null) {
        console.log('This browser has no BarcodeDetector, so the QR code was not scanned.');
    } else {
        assert.equal(scanned, uri, 'the QR code scans to the otpauth URI');
        console.log('The QR code scans to the otpauth URI.');
    }
    await step('qr-code-and-key');

    await page.reload();
    await qr.waitFor();
    assert.equal(await shownKey(), key, 'a refresh shows the same key');

    await page.getByLabel('Code from your authenticator app').fill(wrongCode(key));
    await step('wrong-code-typed');
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByText('The provided credential is invalid.').waitFor();
    assert.equal(await shownKey(), key, 'a wrong code keeps the key');
    await step('wrong-code-refused');

    await page.getByLabel('Code from your authenticator app').fill(totp(key));
    await step('code-typed');
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByText('The new credential was added.').waitFor();
    assert.equal(new URL(page.url()).pathname, '/settings/security', 'an enrollment lands on the security page');
    assert.equal(await section('Authenticator app').locator('li').count(), 1, 'the new authenticator took the place of the old one');
    await section('Sudo').getByText("Changes won't ask again until").waitFor();
    await step('enrolled');

    assert.equal(value("select count(*) from user_credentials where user_id = 1 and type = 'totp'"), '1', 'one TOTP credential is stored');
    assert.equal(Number(value('select credential_epoch from users where id = 1')), epoch + 1, 'replacing the authenticator moved the credential epoch');

    await openEnrollment();
    const second = await shownKey();
    assert.notEqual(second, key, 'the next enrollment makes a new key');
    await page.getByLabel('Code from your authenticator app').fill(totp(second));
    await page.getByRole('button', { name: 'Set up' }).click();
    await page.getByText('The new credential was added.').waitFor();
    await step('enrolled-again');

    assert.equal(value("select count(*) from user_credentials where user_id = 1 and type = 'totp'"), '1', 'one TOTP credential is stored after the second enrollment');
    assert.equal(Number(value('select credential_epoch from users where id = 1')), epoch + 2, 'the second enrollment moved the credential epoch again');

    await openEnrollment();
    const abandoned = await shownKey();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.getByRole('heading', { name: 'Security settings' }).waitFor();
    await openEnrollment();
    assert.notEqual(await shownKey(), abandoned, 'cancelling forgets the key');
    await step('new-key-after-cancel');

    const events = sql("select type, flow, credential_type, reason from user_security_events where flow = 'settings' order by id");
    assert.match(events, /proof\.rejected\s+settings\s+totp\s+totp\.mismatch/, 'the wrong code is on the trail in the settings flow');
    assert.equal(events.match(/credential\.added\s+settings\s+totp/g)?.length, 2, 'both enrollments are on the trail in the settings flow');
    assert.equal(value("select count(*) from user_security_events where type = 'credential.removed'"), '0', 'a replacement records no removal');
    console.log(events);
    console.log(`credential_epoch ${epoch} -> ${value('select credential_epoch from users where id = 1')}`);
} finally {
    await close();
}
