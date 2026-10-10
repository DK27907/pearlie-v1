import fs from 'node:fs';
import path from 'node:path';

const baseUrl = process.env.AUDIT_BASE_URL ?? 'http://127.0.0.1:8001';
const cases = [
    ...[
        ['i want to bok an apointment', 'booking', 'COLLECTING', ['What service do you need']],
        ['wats the price of dental', 'pricing', 'IDLE', ['price']],
        ['do u have a dentst', 'doctors', 'IDLE', ['doctor']],
        ['whre are you locatd', 'location', 'IDLE', ['Pearl Hospital', 'Nyahururu']],
        ['helo', 'greeting', 'IDLE', ['Hello']],
        ['book apointment tomorow at 11', 'booking', 'COLLECTING', ['What service do you need']],
        ['i ned a humn', 'human_escalation', 'IDLE', ['phone']],
        ['ct scn', 'service_match', 'COLLECTING', []],
        ['dntl cleanin', 'service_match', 'COLLECTING', []],
    ].map(([message, intent, state, terms]) => ({ category: 'typos', message, intent, state, terms })),
    ...[
        ['gimme a dentist', 'doctors', 'IDLE', ['doctor']],
        ['wanna book', 'booking', 'COLLECTING', ['What service do you need']],
        ['hey can i see a doc', 'doctors', 'IDLE', ['doctor']],
        ['whats up', 'greeting', 'IDLE', ['Hello']],
        ['yo', 'greeting', 'IDLE', ['Hello']],
        ['sup', 'greeting', 'IDLE', ['Hello']],
    ].map(([message, intent, state, terms]) => ({ category: 'slang', message, intent, state, terms })),
    ...[
        ['nataka kuweka miadi', 'booking', 'COLLECTING', ['What service do you need']],
        ['nina homa', 'health_question', 'IDLE', []],
        ['daktari yupi yuko', 'doctors', 'IDLE', ['doctor']],
        ['mnapatikana wapi', 'location', 'IDLE', ['Pearl Hospital', 'Nyahururu']],
        ['bei ya kuchunguza meno', 'pricing', 'IDLE', ['bei']],
        ['naomba msaada', 'help', 'IDLE', []],
        ['asante', 'thanks', 'IDLE', []],
        ['habari', 'greeting', 'IDLE', ['Habari']],
        ['shikamoo', 'greeting', 'IDLE', ['Habari']],
        ['mambo vipi', 'greeting', 'IDLE', ['Habari']],
    ].map(([message, intent, state, terms]) => ({ category: 'swahili', message, intent, state, terms })),
    ...[
        ['I want to book miadi ya kesho', 'booking', 'COLLECTING', ['What service do you need']],
        ['nataka appointment ya dental', 'booking', 'COLLECTING', []],
        ['habari, what services do you offer', 'services', 'IDLE', ['Available services']],
    ].map(([message, intent, state, terms]) => ({ category: 'mixed', message, intent, state, terms })),
    ...[
        ['booking', 'booking', 'COLLECTING', []],
        ['doctor', 'doctors', 'IDLE', ['doctor']],
        ['no', 'clarification', 'IDLE', []],
        ['yes', 'clarification', 'IDLE', []],
        ['?', 'clarification', 'IDLE', ['using words']],
        ['...', 'clarification', 'IDLE', ['using words']],
        ['help', 'help', 'IDLE', []],
    ].map(([message, intent, state, terms]) => ({ category: 'ambiguous', message, intent, state, terms })),
    ...[
        ['chest pain', 'emergency', 'IDLE', ['emergency']],
        ["I can't breathe", 'emergency', 'IDLE', ['emergency']],
        ['dharura', 'emergency', 'IDLE', ['dharura']],
        ['nina maumivu makali', 'emergency', 'IDLE', ['dharura']],
    ].map(([message, intent, state, terms]) => ({ category: 'emergency', message, intent, state, terms })),
    ...[
        ['capital of France', 'out_of_scope', 'IDLE', ['Pearl Hospital', 'appointments']],
        ['tell me a joke', 'out_of_scope', 'IDLE', ['Pearl Hospital', 'appointments']],
        ['who is the president', 'out_of_scope', 'IDLE', ['Pearl Hospital', 'appointments']],
        ['what is the weather', 'out_of_scope', 'IDLE', ['Pearl Hospital', 'appointments']],
    ].map(([message, intent, state, terms]) => ({ category: 'out_of_scope', message, intent, state, terms })),
    ...[
        ['', 'empty', 'IDLE', []],
        ['   \t ', 'empty', 'IDLE', []],
        ['x'.repeat(5000), 'long', 'IDLE', []],
        ['🤒😷💊🩺', 'emoji_only', 'IDLE', ['using words']],
        [null, 'null_payload', 'IDLE', []],
    ].map(([message, intent, state, terms]) => ({ category: 'empty_edge', message, intent, state, terms })),
];

const outcomes = [];
const setupErrors = [];
const transportErrors = [];
let retryCount = 0;

function makeClient() {
    const cookies = new Map();

    return {
        async request(url, options = {}) {
            await new Promise((resolve) => setTimeout(resolve, 250));
            const headers = new Headers(options.headers ?? {});

            if (cookies.size > 0) {
                headers.set('cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
            }

            let attempt = 0;

            while (true) {
                let response;

                try {
                    response = await fetch(new URL(url, baseUrl), {
                        ...options,
                        headers,
                        redirect: 'manual',
                    });
                } catch (error) {
                    return { status: null, body: '', error: error.message, headers: new Headers() };
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

                const body = await response.text();

                if (response.status === 429 && attempt === 0) {
                    retryCount++;
                    attempt++;
                    await new Promise((resolve) => setTimeout(resolve, 5000));
                    continue;
                }

                return {
                    status: response.status,
                    body,
                    error: '',
                    headers: response.headers,
                };
            }
        },
    };
}

function htmlAttribute(html, name) {
    const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return html.match(new RegExp(`${escapedName}=["']([^"']+)["']`, 'i'))?.[1] ?? '';
}

for (const [index, testCase] of cases.entries()) {
    const session = makeClient();
    const page = await session.request('/h/pearl/chat');
    const csrf = page.body.match(/name=["']csrf-token["']\s+content=["']([^"']+)["']/i)?.[1] ?? '';
    const resetUrl = htmlAttribute(page.body, 'data-reset-url');
    const chatUrl = htmlAttribute(page.body, 'data-chat-url');

    if (page.status !== 200 || !csrf || !resetUrl || !chatUrl) {
        setupErrors.push({
            index,
            category: testCase.category,
            message: testCase.message,
            status: page.status,
            body: page.body,
            error: 'Unable to initialize a fresh chat session.',
        });
        continue;
    }

    const resetResponse = await session.request(resetUrl, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': csrf, accept: 'application/json' },
        body: JSON.stringify({}),
    });

    if (resetResponse.status !== 200) {
        setupErrors.push({
            index,
            category: testCase.category,
            message: testCase.message,
            status: resetResponse.status,
            body: resetResponse.body,
            error: 'Fresh chat reset failed.',
        });
        continue;
    }

    const messageBody = JSON.stringify({ message: testCase.message });
    const response = await session.request(chatUrl, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': csrf, accept: 'application/json' },
        body: messageBody,
    });
    let parsed = null;
    let parseError = '';

    try {
        parsed = JSON.parse(response.body);
    } catch (error) {
        parseError = error.message;
    }

    const failures = [];
    const actualMessage = typeof parsed?.response === 'string' ? parsed.response : '';

    if (response.status !== 200) {
        failures.push(`expected HTTP 200, received ${response.status}`);
    }

    if (actualMessage.trim() === '') {
        failures.push('response text was empty');
    }

    if (parsed && parsed.state !== testCase.state) {
        failures.push(`expected state ${testCase.state}, received ${parsed.state}`);
    }

    if (parsed && testCase.terms.length > 0
        && !testCase.terms.some((term) => actualMessage.toLocaleLowerCase().includes(term.toLocaleLowerCase()))) {
        failures.push(`response did not contain any expected terms: ${testCase.terms.join(' | ')}`);
    }

    if (response.status === null) {
        transportErrors.push({ index, category: testCase.category, message: testCase.message, error: response.error });
    }

    outcomes.push({
        index,
        category: testCase.category,
        input: testCase.message,
        expected_intent: testCase.intent,
        expected_state: testCase.state,
        http_status: response.status,
        response: actualMessage,
        actual_state: parsed?.state ?? null,
        actual_source: parsed?.source ?? null,
        collected_fields: parsed?.collected_fields ?? null,
        parse_error: parseError,
        error_body: response.status === null || response.status >= 400 ? response.body : '',
        failures,
    });
}

const allErrors = [...setupErrors, ...transportErrors];
const categorySummary = Object.fromEntries([...new Set(cases.map((testCase) => testCase.category))].map((category) => {
    const categoryCases = outcomes.filter((result) => result.category === category);

    return [category, {
        cases: cases.filter((testCase) => testCase.category === category).length,
        passed: categoryCases.filter((result) => result.failures.length === 0).length,
        failed: categoryCases.filter((result) => result.failures.length > 0).length
            + setupErrors.filter((result) => result.category === category).length,
    }];
}));

fs.writeFileSync(path.resolve('storage/audit/language-matrix.json'), JSON.stringify({
    total_cases: cases.length,
    retry_count: retryCount,
    category_summary: categorySummary,
    setup_errors: setupErrors,
    transport_errors: transportErrors,
    cases: outcomes,
}, null, 2));

console.log(JSON.stringify({
    total_cases: cases.length,
    retry_count: retryCount,
    category_summary: categorySummary,
    setup_errors: setupErrors.length,
    transport_errors: transportErrors.length,
    case_failures: outcomes.filter((result) => result.failures.length > 0).length,
}, null, 2));
