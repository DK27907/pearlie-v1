const messagesEl = document.getElementById('messages');
const formEl = document.getElementById('chatForm');
const inputEl = document.getElementById('userInput');
const sendBtn = document.getElementById('sendBtn');
const typingEl = document.getElementById('typingIndicator');
const resetBtn = document.getElementById('resetChat');
const languageToggle = document.getElementById('languageToggle');
const quickReplyButtons = document.querySelectorAll('.quick-reply');
const welcomeEl = messagesEl ? messagesEl.querySelector('[data-welcome-en]') : null;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
let lastMessageId = Number(formEl?.dataset.lastMessageId ?? 0);
let paymentPollTimer = null;
let paymentPollInFlight = false;

function updateLanguage() {
    if (!languageToggle || !welcomeEl || !inputEl || !sendBtn || !resetBtn || !typingEl) {
        return;
    }

    const language = languageToggle.value === 'sw' ? 'sw' : 'en';
    const welcome = language === 'sw' ? welcomeEl.dataset.welcomeSw : welcomeEl.dataset.welcomeEn;

    if (messagesEl && messagesEl.children.length === 1) {
        welcomeEl.textContent = welcome;
    }

    inputEl.placeholder = language === 'sw'
        ? inputEl.dataset.placeholderSw
        : inputEl.dataset.placeholderEn;
    sendBtn.textContent = language === 'sw' ? 'Tuma' : 'Send';
    resetBtn.textContent = language === 'sw' ? 'Anza upya' : 'New Chat';
    const typingLabel = typingEl.querySelector('span');
    typingLabel.textContent = language === 'sw' ? typingLabel.dataset.labelSw : typingLabel.dataset.labelEn;

    quickReplyButtons.forEach((button) => {
        button.textContent = language === 'sw' ? button.dataset.labelSw : button.dataset.labelEn;
    });
}

function appendMessage(text, type) {
    if (!messagesEl) {
        return;
    }

    const div = document.createElement('div');
    div.className = `message ${type}`;
    div.innerText = text;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function showTyping() {
    if (!typingEl || !messagesEl) {
        return;
    }

    typingEl.style.display = 'flex';
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function hideTyping() {
    if (typingEl) {
        typingEl.style.display = 'none';
    }
}

async function resetChat() {
    if (!messagesEl || !welcomeEl || !inputEl || !formEl) {
        return;
    }

    try {
        const resetUrl = formEl.dataset.resetUrl;
        if (!resetUrl) {
            throw new Error('Chat reset endpoint is missing.');
        }

        const response = await fetch(resetUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('The chat session could not be reset.');
        }
    } catch (error) {
        appendMessage('Could not start a new chat. Please try again.', 'ai');
        console.error('Chat Reset Error:', error);

        return;
    }

    stopPaymentPolling();
    messagesEl.replaceChildren(welcomeEl);
    updateLanguage();
    inputEl.value = '';
    inputEl.focus();
}

function stopPaymentPolling() {
    if (paymentPollTimer !== null) {
        window.clearInterval(paymentPollTimer);
        paymentPollTimer = null;
    }
}

async function pollForPaymentReceipt() {
    if (paymentPollInFlight || !formEl?.dataset.messagesUrl) {
        return;
    }

    paymentPollInFlight = true;

    try {
        const url = new URL(formEl.dataset.messagesUrl, window.location.href);
        url.searchParams.set('since_id', String(lastMessageId));
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error('The chat could not check for a payment receipt.');
        }

        const data = await response.json();
        if (Array.isArray(data.messages)) {
            data.messages.forEach((message) => {
                appendMessage(message.text, message.role === 'user' ? 'user' : 'ai');
            });
        }
        if (Number.isSafeInteger(data.last_message_id)) {
            lastMessageId = Math.max(lastMessageId, data.last_message_id);
        }
        if (data.payment_terminal === true) {
            stopPaymentPolling();
        }
    } catch (error) {
        console.error('Payment Receipt Polling Error:', error);
    } finally {
        paymentPollInFlight = false;
    }
}

function startPaymentPolling() {
    if (paymentPollTimer !== null) {
        return;
    }

    paymentPollTimer = window.setInterval(pollForPaymentReceipt, 3000);
}

async function sendMessage(suggestedMessage = null) {
    if (!inputEl || !sendBtn || !messagesEl || !formEl) {
        return;
    }

    const message = (typeof suggestedMessage === 'string' ? suggestedMessage : inputEl.value).trim();

    if (!message) {
        return;
    }

    inputEl.disabled = true;
    sendBtn.disabled = true;
    appendMessage(message, 'user');
    inputEl.value = '';
    showTyping();

    try {
        const chatUrl = formEl.dataset.chatUrl || messagesEl.dataset.chatUrl;
        if (!chatUrl) {
            throw new Error('Chat endpoint is missing.');
        }

        const response = await fetch(chatUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({ message }),
        });
        const data = await response.json().catch(() => ({}));

        hideTyping();

        if (!response.ok) {
            appendMessage(data.message || data.error || 'The assistant could not process your request. Please try again.', 'ai');
        } else {
            appendMessage(data.response || 'I could not process that. Please try again.', 'ai');
            if (Number.isSafeInteger(data.last_message_id)) {
                lastMessageId = Math.max(lastMessageId, data.last_message_id);
            }
            if (data.state === 'PAYMENT_PENDING') {
                startPaymentPolling();
            } else {
                stopPaymentPolling();
            }
        }
    } catch (error) {
        hideTyping();
        appendMessage('Connection error. Please check your internet and try again.', 'ai');
        console.error('Chat Error:', error);
    } finally {
        inputEl.disabled = false;
        sendBtn.disabled = false;
        inputEl.focus();
    }
}

if (formEl) {
    formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage();
    });
}
if (inputEl) {
    inputEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            sendMessage();
        }
    });
}
if (languageToggle) {
    languageToggle.addEventListener('change', updateLanguage);
}
quickReplyButtons.forEach((button) => {
    button.addEventListener('click', () => {
        sendMessage(languageToggle && languageToggle.value === 'sw' ? button.dataset.messageSw : button.dataset.messageEn);
    });
});
if (resetBtn) {
    resetBtn.addEventListener('click', (event) => {
        event.preventDefault();
        resetChat();
    });
}
updateLanguage();
if (inputEl) {
    inputEl.focus();
}
