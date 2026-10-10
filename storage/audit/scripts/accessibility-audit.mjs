import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const accounts = {
    superadmin: { email: process.env.AUDIT_SUPERADMIN_EMAIL },
    pearl_admin: { email: process.env.AUDIT_PEARL_ADMIN_EMAIL },
    demo_admin: { email: process.env.AUDIT_DEMO_ADMIN_EMAIL },
    doctor: { email: process.env.AUDIT_DOCTOR_EMAIL },
    patient: { email: process.env.AUDIT_PATIENT_EMAIL },
};
const password = process.env.AUDIT_PASSWORD;

function parseCsv(contents) {
    return contents.trim().split(/\r?\n/).map((line) => {
        const cells = [];
        let cell = '';
        let quoted = false;

        for (let index = 0; index < line.length; index += 1) {
            const character = line[index];

            if (character === '"' && line[index + 1] === '"' && quoted) {
                cell += '"';
                index += 1;
            } else if (character === '"') {
                quoted = !quoted;
            } else if (character === ',' && !quoted) {
                cells.push(cell);
                cell = '';
            } else {
                cell += character;
            }
        }

        cells.push(cell);

        return cells;
    });
}

const [header, ...data] = parseCsv(fs.readFileSync(path.resolve('storage/audit/query-counts.csv'), 'utf8'));
const pageRows = data.map((cells) => Object.fromEntries(header.map((key, index) => [key, cells[index]])))
    .filter((row) => row.status === '200' && !/export|download/i.test(row.route_name));
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AUDIT_BROWSER ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
});
const results = [];
const keyboardResults = [];
const consoleErrors = [];
const screenshotDir = path.resolve('storage/audit/screenshots');
fs.mkdirSync(screenshotDir, { recursive: true });

async function checkKeyboard(page, role, pagePath) {
    const focusableCount = await page.evaluate(() => [...document.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element.checkVisibility()).length);
    const focusChecks = [];
    const visitedTargets = new Set();

    for (let index = 0; index < focusableCount + 3; index += 1) {
        await page.keyboard.press('Tab');
        const focusStyle = await page.evaluate(() => {
            const element = document.activeElement;
            const style = getComputedStyle(element);
            const visible = element !== document.body
                && (style.outlineStyle !== 'none' && style.outlineWidth !== '0px'
                    || style.boxShadow !== 'none');
            const focusTargets = [...document.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter((candidate) => candidate.checkVisibility());

            return {
                targetIndex: focusTargets.indexOf(element),
                tag: element?.tagName ?? '',
                label: element?.getAttribute('aria-label') ?? element?.textContent?.trim().slice(0, 100) ?? '',
                outlineStyle: style.outlineStyle,
                outlineWidth: style.outlineWidth,
                boxShadow: style.boxShadow,
                visible,
            };
        });

        if (focusStyle.targetIndex >= 0 && !visitedTargets.has(focusStyle.targetIndex)) {
            visitedTargets.add(focusStyle.targetIndex);
            focusChecks.push(focusStyle);
        }

        if (visitedTargets.size === focusableCount) {
            break;
        }
    }

    keyboardResults.push({
        role,
        path: pagePath,
        focusableCount,
        visitedCount: visitedTargets.size,
        missingFocusCount: focusableCount - visitedTargets.size,
        focusChecks,
    });
}

try {
    for (const [role, account] of Object.entries(accounts)) {
        const context = await browser.newContext({ viewport: { width: 7680, height: 4320 } });
        const page = await context.newPage();
        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push({ role, page: page.url(), message: message.text() });
            }
        });
        page.on('pageerror', (error) => consoleErrors.push({ role, page: page.url(), message: error.message }));

        await page.goto(new URL('/login', baseUrl).href, { waitUntil: 'domcontentloaded' });
        await page.locator('input[name="email"]').fill(account.email);
        await page.locator('input[name="password"]').fill(password);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        await page.waitForLoadState('domcontentloaded');

        for (const row of pageRows.filter((candidate) => candidate.role === role)) {
            await page.goto(new URL(row.path, baseUrl).href, { waitUntil: 'domcontentloaded' });
            const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
            const dimensions = await page.evaluate(() => ({
                viewportWidth: window.innerWidth,
                documentWidth: document.documentElement.scrollWidth,
                bodyFontSize: Number.parseFloat(getComputedStyle(document.body).fontSize),
            }));
            const dashboardScreenshot = /dashboard/i.test(row.route_name);

            if (dashboardScreenshot) {
                const fileName = `${role}-${row.route_name.replace(/[^a-z0-9-]/gi, '-')}.png`;
                await page.screenshot({ path: path.join(screenshotDir, fileName), fullPage: true });
            }

            results.push({
                role,
                path: row.path,
                route_name: row.route_name,
                status: page.url().includes('/login') ? 'redirected-to-login' : 'loaded',
                violations: axe.violations.map((violation) => ({
                    id: violation.id,
                    impact: violation.impact,
                    description: violation.description,
                    nodes: violation.nodes.map((node) => ({ target: node.target, failureSummary: node.failureSummary })),
                })),
                serious_critical: axe.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact)).length,
                dimensions,
                screenshot: dashboardScreenshot ? `${role}-${row.route_name.replace(/[^a-z0-9-]/gi, '-')}.png` : '',
            });

            if (dashboardScreenshot || (role === 'patient' && /chat/i.test(row.route_name))) {
                await checkKeyboard(page, role, row.path);
            }
        }

        await context.close();
    }

    const guestContext = await browser.newContext({ viewport: { width: 7680, height: 4320 } });
    const guestPage = await guestContext.newPage();
    guestPage.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push({ role: 'guest', page: guestPage.url(), message: message.text() });
        }
    });
    guestPage.on('pageerror', (error) => consoleErrors.push({ role: 'guest', page: guestPage.url(), message: error.message }));

    for (const publicPath of ['/login', '/register']) {
        await guestPage.goto(new URL(publicPath, baseUrl).href, { waitUntil: 'domcontentloaded' });
        const axe = await new AxeBuilder({ page: guestPage }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        results.push({
            role: 'guest',
            path: publicPath,
            route_name: '',
            status: 'loaded',
            violations: axe.violations.map((violation) => ({
                id: violation.id,
                impact: violation.impact,
                description: violation.description,
                nodes: violation.nodes.map((node) => ({ target: node.target, failureSummary: node.failureSummary })),
            })),
            serious_critical: axe.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact)).length,
            dimensions: await guestPage.evaluate(() => ({
                viewportWidth: window.innerWidth,
                documentWidth: document.documentElement.scrollWidth,
                bodyFontSize: Number.parseFloat(getComputedStyle(document.body).fontSize),
            })),
            screenshot: '',
        });
        await checkKeyboard(guestPage, 'guest', publicPath);
    }

    await guestContext.close();
} finally {
    await browser.close();
}

fs.writeFileSync(path.resolve('storage/audit/accessibility-results.json'), JSON.stringify({
    pages: results.length,
    serious_critical_violations: results.reduce((total, result) => total + result.serious_critical, 0),
    horizontal_overflow: results.filter((result) => result.dimensions.documentWidth > result.dimensions.viewportWidth).length,
    console_errors: consoleErrors,
    keyboard: keyboardResults,
    results,
}, null, 2));

console.log(JSON.stringify({
    pages: results.length,
    serious_critical_violations: results.reduce((total, result) => total + result.serious_critical, 0),
    horizontal_overflow: results.filter((result) => result.dimensions.documentWidth > result.dimensions.viewportWidth).length,
    console_errors: consoleErrors.length,
    dashboards_with_keyboard_checks: keyboardResults.length,
}, null, 2));
