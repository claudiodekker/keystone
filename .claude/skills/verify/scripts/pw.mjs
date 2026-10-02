import { createHmac } from 'node:crypto';
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../../..');
const playwright = join(homedir(), '.cache/keystone-verify/node_modules/playwright/index.mjs');

if (!existsSync(playwright)) {
    throw new Error('Playwright is missing: run .claude/skills/verify/scripts/install-playwright.sh');
}

const { chromium } = await import(playwright);

export function fixture(run) {
    const file = join(root, '.verify/runs', run, 'second-factor.json');

    if (!existsSync(file)) {
        throw new Error(`No second factor on run ${run}: run app.sh second-factor ${run} first`);
    }

    return JSON.parse(readFileSync(file, 'utf8'));
}

export function totp(base32Key, at = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...base32Key].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
    const step = Buffer.alloc(8);
    step.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
    const hmac = createHmac('sha1', key).update(step).digest();
    const offset = hmac[19] & 0x0f;

    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

export async function open(run, name) {
    const url = readFileSync(join(root, '.verify/runs', run, 'url'), 'utf8').trim();
    const dir = join(root, '.verify/evidence', run, name);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'steps.log'), '');
    writeFileSync(join(dir, 'network.log'), '');

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: url });
    const page = await context.newPage();
    let count = 0;
    let pending = 0;
    let lastActivity = Date.now();

    const log = async (request, status) => {
        pending -= 1;
        lastActivity = Date.now();

        if (!request.url().includes('/build/')) {
            appendFileSync(join(dir, 'network.log'), `${request.method()} ${request.url().replace(url, '')} -> ${await status}\n`);
        }
    };

    page.on('request', () => {
        pending += 1;
        lastActivity = Date.now();
    });
    page.on('requestfinished', (request) => log(request, request.response().then((response) => response?.status())));
    page.on('requestfailed', (request) => log(request, request.failure()?.errorText));
    page.on('pageerror', (error) => appendFileSync(join(dir, 'steps.log'), `PAGE ERROR ${error.message}\n`));

    // Inertia navigates over XHR after the click resolves, so load states never reset; wait for the requests to settle instead.
    const settled = async (quietMs = 300, timeoutMs = 15_000) => {
        const deadline = Date.now() + timeoutMs;

        while (pending > 0 || Date.now() - lastActivity < quietMs) {
            if (Date.now() > deadline) {
                throw new Error(`requests did not settle within ${timeoutMs} ms (${pending} in flight)`);
            }

            await page.waitForTimeout(50);
        }
    };

    const step = async (label) => {
        await settled();
        count += 1;
        const file = `${String(count).padStart(2, '0')}-${label}.png`;
        await page.screenshot({ path: join(dir, file), fullPage: true });
        const heading = (await page.locator('h1, main p').first().textContent().catch(() => ''))?.trim();
        appendFileSync(join(dir, 'steps.log'), `${file} ${new URL(page.url()).pathname} "${heading}"\n`);
    };

    const close = async () => {
        await browser.close();
        console.log(`evidence: ${dir}`);
    };

    return { page, step, close, dir };
}
