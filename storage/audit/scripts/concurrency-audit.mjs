import fs from 'node:fs';
import path from 'node:path';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const password = process.env.AUDIT_PASSWORD;
const checkoutRequestId = process.env.AUDIT_CHECKOUT_REQUEST_ID;
const receipt = process.env.AUDIT_CALLBACK_RECEIPT ?? 'AUDITRACE001';

function makeSession() {
    const cookies = new Map();

    return {
        async request(uri, options = {}) {
            const headers = new Headers(options.headers ?? {});

            if (cookies.size > 0) {
                headers.set('cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
            }

            const response = await fetch(new URL(uri, baseUrl), {
                ...options,
                headers,
                redirect: 'manual',
            });

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
            };
        },
    };
}

function extractAttribute(html, name) {
    return html.match(new RegExp(`${name}=["']([^"']+)["']`, 'i'))?.[1] ?? '';
}

async function login(email) {
    const session = makeSession();
    const loginPage = await session.request('/login');
    const loginToken = loginPage.body.match(/name=["']_token["']\s+value=["']([^"']+)["']/i)?.[1];

    if (loginPage.status !== 200 || !loginToken) {
        throw new Error(`Login form unavailable (${loginPage.status}).`);
    }

    const loginResult = await session.request('/login', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _token: loginToken, email, password }),
    });

    if (loginResult.status < 300 || loginResult.status >= 400) {
        throw new Error(`Login failed with HTTP ${loginResult.status}.`);
    }

    const profile = await session.request('/profile');
    const csrf = profile.body.match(/name=["']csrf-token["']\s+content=["']([^"']+)["']/i)?.[1] ?? '';

    if (profile.status !== 200 || !csrf) {
        throw new Error(`Authenticated CSRF page unavailable (${profile.status}).`);
    }

    return { session, csrf };
}

async function runParallelChat() {
    const session = makeSession();
    const page = await session.request('/h/pearl/chat');
    const csrf = page.body.match(/name=["']csrf-token["']\s+content=["']([^"']+)["']/i)?.[1] ?? '';
    const resetUrl = extractAttribute(page.body, 'data-reset-url');
    const chatUrl = extractAttribute(page.body, 'data-chat-url');

    if (page.status !== 200 || !csrf || !resetUrl || !chatUrl) {
        throw new Error(`Chat setup failed with HTTP ${page.status}.`);
    }

    const reset = await session.request(resetUrl, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': csrf, accept: 'application/json' },
        body: '{}',
    });

    if (reset.status !== 200) {
        throw new Error(`Chat reset failed with HTTP ${reset.status}.`);
    }

    const responses = await Promise.all(Array.from({ length: 20 }, async (_, index) => {
        const result = await session.request(chatUrl, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': csrf, accept: 'application/json' },
            body: JSON.stringify({ message: index % 2 === 0 ? 'hello' : 'What services do you offer?' }),
        });

        let parsed = null;

        try {
            parsed = JSON.parse(result.body);
        } catch {
            parsed = null;
        }

        return { status: result.status, hasResponse: typeof parsed?.response === 'string' && parsed.response.trim() !== '' };
    }));

    return {
        requests: responses.length,
        statuses: responses.reduce((counts, result) => {
            counts[result.status] = (counts[result.status] ?? 0) + 1;

            return counts;
        }, {}),
        emptyResponses: responses.filter((result) => !result.hasResponse).length,
    };
}

async function runParallelCallbacks() {
    const payload = {
        Body: {
            stkCallback: {
                MerchantRequestID: 'audit-race-merchant',
                CheckoutRequestID: checkoutRequestId,
                ResultCode: 0,
                ResultDesc: 'Audit-only simulated payment success.',
                CallbackMetadata: {
                    Item: [
                        { Name: 'Amount', Value: 1 },
                        { Name: 'MpesaReceiptNumber', Value: receipt },
                        { Name: 'PhoneNumber', Value: '254712345678' },
                    ],
                },
            },
        },
    };
    const responses = await Promise.all(Array.from({ length: 2 }, async () => {
        const response = await fetch(new URL('/api/mpesa/callback', baseUrl), {
            method: 'POST',
            headers: { 'content-type': 'application/json', accept: 'application/json' },
            body: JSON.stringify(payload),
        });

        return { status: response.status, body: await response.json() };
    }));

    return responses;
}

async function runParallelCancels() {
    const { session, csrf } = await login(process.env.AUDIT_PATIENT_EMAIL);
    const responses = await Promise.all(Array.from({ length: 2 }, async () => {
        const response = await session.request('/appointments/14/cancel', {
            method: 'POST',
            headers: {
                'content-type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf,
                accept: 'text/html',
            },
            body: new URLSearchParams({ _token: csrf, reason: 'Disposable audit duplicate-cancel check.' }),
        });

        return { status: response.status, location: response.location, bodyPreview: response.body.slice(0, 300) };
    }));

    return responses;
}

if (!password) {
    throw new Error('AUDIT_PASSWORD is required.');
}

if (!checkoutRequestId) {
    throw new Error('AUDIT_CHECKOUT_REQUEST_ID is required.');
}

const results = {
    base_url: baseUrl,
    payment_amount_ksh: 1,
    chat: await runParallelChat(),
    callbacks: await runParallelCallbacks(),
    cancels: await runParallelCancels(),
};

fs.writeFileSync(path.resolve('storage/audit/concurrency-results.json'), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
