import fs from 'node:fs';
import path from 'node:path';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const roles = [
    { name: 'superadmin', email: process.env.AUDIT_SUPERADMIN_EMAIL, password: process.env.AUDIT_PASSWORD, permission: 'superadmin' },
    { name: 'pearl_admin', email: process.env.AUDIT_PEARL_ADMIN_EMAIL, password: process.env.AUDIT_PASSWORD, permission: 'hospital.admin' },
    { name: 'demo_admin', email: process.env.AUDIT_DEMO_ADMIN_EMAIL, password: process.env.AUDIT_PASSWORD, permission: 'hospital.admin' },
    { name: 'doctor', email: process.env.AUDIT_DOCTOR_EMAIL, password: process.env.AUDIT_PASSWORD, permission: 'doctor' },
    { name: 'patient', email: process.env.AUDIT_PATIENT_EMAIL, password: process.env.AUDIT_PASSWORD, permission: 'patient' },
];
const routeFile = path.resolve('storage/audit/routes.json');
const routeRows = JSON.parse(fs.readFileSync(routeFile, 'utf8').replace(/^\uFEFF/, ''));
const routes = routeRows.flatMap((route) => route.method.split('|')
    .filter((method) => !['GET', 'HEAD', 'OPTIONS'].includes(method))
    .map((method) => ({ ...route, method })))
    .sort((first, second) => Number(first.uri === 'logout') - Number(second.uri === 'logout'));
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const responseRows = [];
const assertionFailures = [];
let retries = 0;

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

function routeUri(route, tenant = 'pearl') {
    return route.uri.replace(/\{([^}?]+)\??\}/g, (_, key) => {
        if (key === 'slug' || key === 'hospitalSlug') {
            return tenant;
        }

        if (key === 'provider') {
            return 'mpesa';
        }

        if (key === 'token') {
            return 'invalid-audit-token';
        }

        if (key === 'name') {
            return 'invalid-audit-permission';
        }

        return '999999';
    });
}

function csrfToken(html, fallback = '') {
    return html.match(/name=["']csrf-token["']\s+content=["']([^"']+)["']/i)?.[1]
        ?? html.match(/name=["']_token["']\s+value=["']([^"']+)["']/i)?.[1]
        ?? fallback;
}

function client() {
    const cookies = new Map();
    const state = { csrf: '' };

    function absorbCookies(response) {
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
    }

    async function request(uri, options = {}, routeCall = false) {
        let attempt = 0;

        while (true) {
            if (routeCall) {
                await wait(250);
            }

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
                return { status: null, location: '', body: '', error: error.message, headers: new Headers(), retryCount: attempt };
            }

            absorbCookies(response);
            const body = await response.text();
            const token = csrfToken(body, state.csrf);

            if (token) {
                state.csrf = token;
            }

            if (response.status === 429 && attempt === 0) {
                retries++;
                attempt++;
                await wait(5000);
                continue;
            }

            return {
                status: response.status,
                location: response.headers.get('location') ?? '',
                body,
                error: '',
                headers: response.headers,
                retryCount: attempt,
            };
        }
    }

    return { request, state };
}

async function authenticate(role) {
    const session = client();
    const loginPage = await session.request('/login');
    const loginToken = csrfToken(loginPage.body);

    if (loginPage.status !== 200 || !loginToken) {
        throw new Error(`${role.name}: GET /login failed (${loginPage.status}) or token was missing`);
    }

    const form = new URLSearchParams({
        _token: loginToken,
        email: role.email,
        password: role.password,
    });
    const loginResponse = await session.request('/login', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: form,
    });
    const profile = await session.request('/profile');

    if (loginResponse.status < 300 || loginResponse.status >= 400 || profile.status !== 200) {
        throw new Error(`${role.name}: login=${loginResponse.status}, profile=${profile.status}, location=${loginResponse.location}`);
    }

    if (!session.state.csrf) {
        throw new Error(`${role.name}: post-login CSRF token missing from /profile`);
    }

    return session;
}

async function guestSession() {
    const session = client();
    const loginPage = await session.request('/login');
    const loginToken = csrfToken(loginPage.body);

    if (loginPage.status !== 200 || !loginToken) {
        throw new Error(`Guest: GET /login failed (${loginPage.status}) or token was missing`);
    }

    session.state.csrf = loginToken;

    return session;
}

function recordResult(role, route, uri, variant, result) {
    const row = {
        role,
        method: route.method,
        uri,
        route_name: route.name ?? '',
        variant,
        status: result.status,
        location: result.location,
        body_preview: result.body.replace(/\s+/g, ' ').slice(0, 350),
        error_body: result.status === null || result.status >= 400 ? result.body : '',
        transport_error: result.error,
        retry_count: result.retryCount,
    };
    responseRows.push(row);

    if (result.status === null) {
        assertionFailures.push({ category: 'transport', role, method: route.method, uri, error: result.error });
    } else if (result.status >= 500) {
        assertionFailures.push({ category: '5xx', role, method: route.method, uri, status: result.status, body: result.body });
    }

    if (variant === 'missing_csrf' && route.middleware.includes('web')
        && !uri.startsWith('webhooks/whatsapp')
        && !uri.startsWith('mpesa/callback')
        && result.status !== 419) {
        assertionFailures.push({ category: 'missing_csrf_non_419', role, method: route.method, uri, status: result.status, body: result.body });
    }

    if (variant === 'guest_csrf' || variant === 'guest_missing_csrf') {
        if (route.middleware.includes('auth') && result.status >= 200 && result.status < 300) {
            assertionFailures.push({ category: 'guest_2xx_on_auth', method: route.method, uri, status: result.status });
        }
    }

    if (variant === 'valid_csrf' && role !== 'guest' && result.status >= 200 && result.status < 300) {
        const protectedRole = ['hospital.admin', 'doctor', 'patient', 'superadmin']
            .find((middleware) => route.middleware.includes(middleware));
        const actualRole = roles.find((candidate) => candidate.name === role)?.permission;

        if (protectedRole && actualRole !== protectedRole) {
            assertionFailures.push({ category: 'wrong_role_2xx', role, method: route.method, uri, status: result.status, protectedRole });
        }
    }
}

async function sendRouteCall(session, roleName, route, variant, uri = routeUri(route)) {
    const headers = { 'content-type': 'application/x-www-form-urlencoded' };
    const fields = new URLSearchParams();

    if (variant !== 'missing_csrf' && variant !== 'guest_missing_csrf' && session.state.csrf) {
        fields.set('_token', session.state.csrf);
        headers['X-CSRF-TOKEN'] = session.state.csrf;
    }

    const result = await session.request(uri, {
        method: route.method,
        headers,
        body: fields,
    }, true);

    recordResult(roleName, route, uri, variant, result);

    return result;
}

for (const role of roles) {
    if (!role.email || !role.password) {
        throw new Error(`Missing login environment variables for ${role.name}`);
    }

    const session = await authenticate(role);

    for (const route of routes) {
        const uri = routeUri(route);
        await sendRouteCall(session, role.name, route, 'valid_csrf', uri);
        await sendRouteCall(session, role.name, route, 'missing_csrf', uri);

        if (role.name === 'pearl_admin') {
            let crossTenantUri = null;

            if (route.uri.includes('{doctor}')) {
                crossTenantUri = route.uri.replace('{doctor}', '10');
            } else if (route.uri.includes('{service}')) {
                crossTenantUri = route.uri.replace('{service}', '13');
            } else if (route.uri.includes('{hospital}')) {
                crossTenantUri = route.uri.replace('{hospital}', '2');
            }

            if (crossTenantUri) {
                const crossTenantResult = await sendRouteCall(session, role.name, route, 'cross_tenant', crossTenantUri);

                if (crossTenantResult.status >= 200 && crossTenantResult.status < 300) {
                    assertionFailures.push({
                        category: 'cross_tenant_2xx',
                        role: role.name,
                        method: route.method,
                        uri: crossTenantUri,
                        status: crossTenantResult.status,
                    });
                }
            }
        }
    }
}

const guest = await guestSession();

for (const route of routes) {
    const uri = routeUri(route);
    await sendRouteCall(guest, 'guest', route, 'guest_csrf', uri);
    await sendRouteCall(guest, 'guest', route, 'guest_missing_csrf', uri);
}

writeCsv(path.resolve('storage/audit/route-matrix.csv'), responseRows, [
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
]);
fs.writeFileSync(path.resolve('storage/audit/route-matrix-summary.json'), JSON.stringify({
    routes: routes.length,
    total_calls: responseRows.length,
    retries,
    status_histogram: Object.fromEntries([...new Set(responseRows.map((row) => row.status))].map((status) => [
        status === null ? 'transport_error' : String(status),
        responseRows.filter((row) => row.status === status).length,
    ])),
    failure_counts: Object.fromEntries([...new Set(assertionFailures.map((failure) => failure.category))].map((category) => [
        category,
        assertionFailures.filter((failure) => failure.category === category).length,
    ])),
    failures: assertionFailures,
}, null, 2));

console.log(JSON.stringify({
    routes: routes.length,
    total_calls: responseRows.length,
    retries,
    failure_counts: Object.fromEntries([...new Set(assertionFailures.map((failure) => failure.category))].map((category) => [
        category,
        assertionFailures.filter((failure) => failure.category === category).length,
    ])),
    histogram: Object.fromEntries([...new Set(responseRows.map((row) => row.status))].map((status) => [
        status === null ? 'transport_error' : String(status),
        responseRows.filter((row) => row.status === status).length,
    ])),
}, null, 2));
