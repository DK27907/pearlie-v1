import fs from 'node:fs';
import path from 'node:path';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const password = process.env.AUDIT_PASSWORD;
const accounts = [
    { role: 'superadmin', email: process.env.AUDIT_SUPERADMIN_EMAIL, permission: 'superadmin' },
    { role: 'pearl_admin', email: process.env.AUDIT_PEARL_ADMIN_EMAIL, permission: 'hospital.admin', hospitalId: '1' },
    { role: 'demo_admin', email: process.env.AUDIT_DEMO_ADMIN_EMAIL, permission: 'hospital.admin', hospitalId: '2' },
    { role: 'doctor', email: process.env.AUDIT_DOCTOR_EMAIL, permission: 'doctor', hospitalId: '1' },
    { role: 'patient', email: process.env.AUDIT_PATIENT_EMAIL, permission: 'patient', hospitalId: '1' },
];
const routes = JSON.parse(fs.readFileSync(path.resolve('storage/audit/routes.json'), 'utf8').replace(/^\uFEFF/, ''));
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const results = [];
const failures = [];

function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);

    return `"${text.replaceAll('"', '""')}"`;
}

function writeCsv(filePath, rows, columns) {
    const output = [
        columns.map(csvCell).join(','),
        ...rows.map((row) => columns.map((column) => csvCell(row[column])).join(',')),
    ].join('\r\n');

    fs.writeFileSync(filePath, `${output}\r\n`, 'utf8');
}

function readLastAuditRow(startAt) {
    const lines = fs.readFileSync(path.resolve('storage/logs/audit-queries.log'), 'utf8').trimEnd().split(/\r?\n/);
    const row = lines.slice(startAt).at(-1);

    if (!row) {
        return null;
    }

    const queryCount = Number(row.slice(row.lastIndexOf(',') + 1));

    return Number.isFinite(queryCount) ? queryCount : null;
}

function makeSession() {
    const cookies = new Map();

    return {
        async request(uri, options = {}) {
            await wait(250);
            const headers = new Headers(options.headers ?? {});

            if (cookies.size > 0) {
                headers.set('cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
            }

            let response;

            try {
                response = await fetch(new URL(uri, baseUrl), {
                    ...options,
                    headers,
                    redirect: 'manual',
                });
            } catch (error) {
                return { status: null, location: '', body: '', error: error.message };
            }

            for (const cookie of response.headers.getSetCookie()) {
                const match = cookie.match(/^([^=;]+)=([^;]*)/);

                if (match) {
                    if (cookie.includes('Max-Age=0') || match[2] === 'deleted') {
                        cookies.delete(match[1]);
                    } else {
                        cookies.set(match[1], match[2]);
                    }
                }
            }

            return {
                status: response.status,
                location: response.headers.get('location') ?? '',
                body: await response.text(),
                error: '',
            };
        },
    };
}

async function login(account) {
    const session = makeSession();
    const loginPage = await session.request('/login');
    const token = loginPage.body.match(/name=["']_token["']\s+value=["']([^"']+)["']/i)?.[1];

    if (loginPage.status !== 200 || !token) {
        throw new Error(`${account.role}: login form unavailable (${loginPage.status})`);
    }

    const loginResponse = await session.request('/login', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _token: token, email: account.email, password }),
    });
    const profile = await session.request('/profile');

    if (loginResponse.status < 300 || loginResponse.status >= 400 || profile.status !== 200) {
        throw new Error(`${account.role}: login=${loginResponse.status}, profile=${profile.status}`);
    }

    return session;
}

function routeUri(route, account) {
    return route.uri.replace(/\{([^}?]+)\??\}/g, (_, key) => {
        if (key === 'doctor') {
            return account.hospitalId === '2' ? '10' : '5';
        }

        if (key === 'service') {
            return account.hospitalId === '2' ? '11' : '1';
        }

        if (key === 'hospital') {
            return account.hospitalId === '2' ? '2' : '1';
        }

        if (key === 'paymentId') {
            return '1';
        }

        if (key === 'knowledgeBase') {
            return '1';
        }

        if (key === 'conversation') {
            return 'invalid-audit-session';
        }

        return '14';
    });
}

const rolePrefixes = {
    superadmin: ['superadmin'],
    hospital_admin: ['admin', 'hospital'],
    doctor: ['doctor'],
    patient: ['appointments'],
};

function isDashboardRoute(route, account) {
    const pathPrefix = route.uri.split('/')[0];
    const prefixes = rolePrefixes[account.permission.replace('.', '_')]
        ?? rolePrefixes[account.permission]
        ?? [];

    return route.middleware.includes(account.permission) && prefixes.includes(pathPrefix);
}

const selectedPages = new Map();

for (const account of accounts) {
    for (const route of routes) {
        if (!route.method.split('|').includes('GET') || !route.middleware.includes('auth')) {
            continue;
        }

        if (isDashboardRoute(route, account)) {
            selectedPages.set(`${account.role}|${route.uri}`, { ...route, account });
        }
    }

    for (const routeName of ['profile.edit', 'settings.profile', 'doctor.profile']) {
        const route = routes.find((candidate) => candidate.name === routeName);

        if (route && (routeName !== 'doctor.profile' || account.permission === 'doctor')) {
            selectedPages.set(`${account.role}|${route.uri}`, { ...route, account });
        }
    }

    const dashboard = routes.find((route) => route.name === 'dashboard');

    if (dashboard) {
        selectedPages.set(`${account.role}|${dashboard.uri}`, { ...dashboard, account });
    }

    selectedPages.set(`${account.role}|h/pearl/chat`, {
        uri: 'h/pearl/chat',
        name: 'tenant.chat.page.pearl',
        method: 'GET',
        account,
    });
    selectedPages.set(`${account.role}|h/demo/chat`, {
        uri: 'h/demo/chat',
        name: 'tenant.chat.page.demo',
        method: 'GET',
        account,
    });
}

const queryLogPath = path.resolve('storage/logs/audit-queries.log');
const sessions = new Map();

for (const { account, ...route } of selectedPages.values()) {
    let session = sessions.get(account.role);

    if (!session) {
        session = await login(account);
        sessions.set(account.role, session);
    }

    const uri = routeUri(route, account);
    const beforeLines = fs.readFileSync(queryLogPath, 'utf8').trimEnd().split(/\r?\n/).length;
    const response = await session.request(uri);
    const queryCount = readLastAuditRow(beforeLines);
    const row = {
        role: account.role,
        path: `/${uri}`,
        route_name: route.name ?? '',
        query_count: queryCount,
        status: response.status,
        location: response.location,
        transport_error: response.error,
        body_preview: response.body.replace(/\s+/g, ' ').slice(0, 350),
    };
    results.push(row);

    if (response.status === null || response.status >= 500) {
        failures.push({ role: account.role, path: row.path, status: response.status, body: row.body_preview, error: response.error });
    }

    if (queryCount === null) {
        failures.push({ role: account.role, path: row.path, error: 'No query-count log row was found.' });
    } else if (queryCount >= 50) {
        failures.push({ role: account.role, path: row.path, query_count: queryCount, error: 'Query count is at or above 50.' });
    }
}

writeCsv(path.resolve('storage/audit/query-counts.csv'), results, [
    'role',
    'path',
    'route_name',
    'query_count',
    'status',
    'location',
    'transport_error',
    'body_preview',
]);

const topPages = [...results].sort((first, second) => (second.query_count ?? -1) - (first.query_count ?? -1)).slice(0, 5);
fs.writeFileSync(path.resolve('storage/audit/query-counts-summary.json'), JSON.stringify({
    pages: results.length,
    pages_over_50: results.filter((result) => result.query_count !== null && result.query_count >= 50).length,
    status_failures: failures.filter((failure) => failure.status >= 500 || failure.status === null).length,
    failures,
    top_five: topPages,
}, null, 2));

console.log(JSON.stringify({
    pages: results.length,
    pages_over_50: results.filter((result) => result.query_count !== null && result.query_count >= 50).length,
    status_failures: failures.filter((failure) => failure.status >= 500 || failure.status === null).length,
    top_five: topPages.map(({ role, path: page, query_count, status }) => ({ role, path: page, query_count, status })),
}, null, 2));
