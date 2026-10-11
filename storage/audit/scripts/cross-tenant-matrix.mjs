import fs from 'node:fs';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const routes = [
    { role: 'pearl_admin', email: process.env.AUDIT_PEARL_ADMIN_EMAIL, uri: '/admin/appointments/16', route_name: 'admin.appointments.show' },
    { role: 'pearl_admin', email: process.env.AUDIT_PEARL_ADMIN_EMAIL, uri: '/admin/escalations/1', route_name: 'admin.escalations.show' },
    { role: 'demo_admin', email: process.env.AUDIT_DEMO_ADMIN_EMAIL, uri: '/admin/appointments/14', route_name: 'admin.appointments.show' },
    { role: 'demo_admin', email: process.env.AUDIT_DEMO_ADMIN_EMAIL, uri: '/admin/escalations/2', route_name: 'admin.escalations.show' },
    { role: 'doctor', email: process.env.AUDIT_DOCTOR_EMAIL, uri: '/doctor/appointments/16', route_name: 'doctor.appointments.show' },
    { role: 'doctor', email: process.env.AUDIT_DOCTOR_EMAIL, uri: '/doctor/escalations/1', route_name: 'doctor.escalations.show' },
    { role: 'patient', email: process.env.AUDIT_PATIENT_EMAIL, uri: '/appointments/16', route_name: 'appointments.show' },
];
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function tokenFrom(html) {
    return html.match(/name=["']csrf-token["']\s+content=["']([^"']+)["']/i)?.[1]
        ?? html.match(/name=["']_token["']\s+value=["']([^"']+)["']/i)?.[1]
        ?? '';
}

async function login(email) {
    const cookies = new Map();
    async function request(uri, options = {}) {
        let retryCount = 0;
        const headers = new Headers(options.headers ?? {});
        while (true) {
            await wait(250);
            if (cookies.size > 0) {
                headers.set('cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
            }
            const response = await fetch(new URL(uri, baseUrl), { ...options, headers, redirect: 'manual' });
            for (const cookie of response.headers.getSetCookie()) {
                const match = cookie.match(/^([^=;]+)=([^;]*)/);
                if (match) {
                    cookies.set(match[1], match[2]);
                }
            }
            if (response.status === 429 && retryCount === 0) {
                retryCount++;
                await wait(5000);
                continue;
            }
            return response;
        }
    }

    const loginPage = await request('/login');
    const loginHtml = await loginPage.text();
    const loginToken = tokenFrom(loginHtml);
    if (loginPage.status !== 200 || !loginToken) {
        throw new Error(`${email}: login page=${loginPage.status}, CSRF token=${Boolean(loginToken)}`);
    }

    const form = new URLSearchParams({ _token: loginToken, email, password: process.env.AUDIT_PASSWORD });
    const loginResponse = await request('/login', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: form,
    });
    const profileResponse = await request('/profile');
    const profileHtml = await profileResponse.text();
    const csrf = tokenFrom(profileHtml);
    if (loginResponse.status < 300 || loginResponse.status >= 400 || profileResponse.status !== 200 || !csrf) {
        throw new Error(`${email}: login=${loginResponse.status}, profile=${profileResponse.status}, post-login CSRF=${Boolean(csrf)}`);
    }

    return { request, csrf };
}

if (!process.env.AUDIT_PASSWORD) {
    throw new Error('AUDIT_PASSWORD is required.');
}

const outputRows = [];
const sessions = new Map();
for (const route of routes) {
    if (!route.email) {
        throw new Error(`Missing configured account for ${route.role}.`);
    }
    if (!sessions.has(route.role)) {
        sessions.set(route.role, await login(route.email));
    }
    const session = sessions.get(route.role);
    const response = await session.request(route.uri, {
        headers: { 'X-CSRF-TOKEN': session.csrf },
    });
    const body = await response.text();
    const row = {
        role: route.role,
        method: 'GET',
        uri: route.uri.slice(1),
        route_name: route.route_name,
        variant: 'cross-tenant',
        status: response.status,
        location: response.headers.get('location') ?? '',
        body_preview: body.replace(/\s+/g, ' ').slice(0, 350),
        error_body: response.status >= 400 ? body : '',
        transport_error: '',
        retry_count: 0,
    };
    outputRows.push(row);
    if (response.status !== 404) {
        console.error(`${route.role} ${route.uri}: expected 404, got ${response.status}`);
    }
}

const columns = [
    'role',
    'method',
    'uri',
    'route_name',
    'variant',
    'status',
    'location',
    'body_preview',
    'error_body',
    'transport_error',
    'retry_count',
];
fs.appendFileSync(
    'storage/audit/route-matrix.csv',
    `${outputRows.map((row) => columns.map((column) => csvCell(row[column])).join(',')).join('\r\n')}\r\n`,
    'utf8',
);
const summary = {
    checks: outputRows.length,
    statuses: outputRows.map(({ role, uri, status }) => ({ role, uri, status })),
    passed: outputRows.every((row) => row.status === 404),
};
fs.writeFileSync('storage/audit/cross-tenant-summary.json', `${JSON.stringify(summary, null, 2)}\n`, 'utf8');
console.log(JSON.stringify(summary, null, 2));
if (!summary.passed) {
    process.exitCode = 1;
}
