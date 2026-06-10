import { test as setup } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const authFile = path.join(process.cwd(), '.playwright/.auth/auth.json');

function loadEnvFileIfPresent(): void {
    const envPath = path.join(process.cwd(), '.env');

    if (!fs.existsSync(envPath)) {
        return;
    }

    const content = fs.readFileSync(envPath, 'utf8');

    for (const rawLine of content.split(/\r?\n/)) {
        const line = rawLine.trim();

        if (line === '' || line.startsWith('#')) {
            continue;
        }

        const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);

        if (!match) {
            continue;
        }

        const [, key, valuePart] = match;

        if (process.env[key] !== undefined) {
            continue;
        }

        process.env[key] = valuePart.replace(/^(['"])(.*)\1$/, '$2');
    }
}

// Ensure local .env variables are available in Playwright setup runs.
//loadEnvFileIfPresent();

function requiredEnv(name: string): string {
    const value = process.env[name];

    if (!value) {
        throw new Error(`Missing required environment variable: ${name}`);
    }

    return value;
}

setup('authenticate', async ({ page }) => {
    const email = requiredEnv('PLAYWRIGHT_E2E_USER_EMAIL');
    const password = requiredEnv('PLAYWRIGHT_E2E_USER_PASS');

    // Perform authentication steps. Replace these actions with your own.
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    // Wait until the page receives the cookies.
    //
    // Sometimes login flow sets cookies in the process of several redirects.
    // Wait for the final URL to ensure that the cookies are actually set.
    await page.waitForURL('/dashboard');
    // Alternatively, you can wait until the page reaches a state where all cookies are set.
    //await expect(page.getByRole('button', { val: 'View profile and more' })).toBeVisible();

    // End of authentication steps.

    await page.context().storageState({ path: authFile });
});
