const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('userInput');
const sendBtn = document.getElementById('sendBtn');
const typingEl = document.getElementById('typingIndicator');
const resetBtn = document.getElementById('resetChat');
const languageToggle = document.getElementById('languageToggle');
const quickReplyButtons = document.querySelectorAll('.quick-reply');
const welcomeEl = messagesEl.querySelector('[data-welcome-en]');
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function updateLanguage() {
    const language = languageToggle.value === 'sw' ? 'sw' : 'en';
    const welcome = language === 'sw' ? welcomeEl.dataset.welcomeSw : welcomeEl.dataset.welcomeEn;

    if (messagesEl.children.length === 1) {
        welcomeEl.textContent = welcome;
    }

    inputEl.placeholder = language === 'sw'
        ? inputEl.dataset.placeholderSw
        : inputEl.dataset.placeholderEn;
    sendBtn.textContent = language === 'sw' ? 'Tuma' : 'Send';
    resetBtn.textContent = language === 'sw' ? 'Anza upya' : 'New Chat';
    typingEl.querySelector('span').textContent = language === 'sw' ? 'Pearlie anafikiria' : 'Pearlie is thinking';

    quickReplyButtons.forEach((button) => {
        button.textContent = language === 'sw' ? button.dataset.labelSw : button.dataset.labelEn;
    });
}

function appendMessage(text, type) {
    const div = document.createElement('div');
    div.className = `message ${type}`;
    div.innerText = text;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function showTyping() {
    typingEl.style.display = 'flex';
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function hideTyping() {
    typingEl.style.display = 'none';
}

function resetChat() {
    messagesEl.replaceChildren(welcomeEl);
    updateLanguage();
    inputEl.value = '';
    inputEl.focus();
}

async function sendMessage(suggestedMessage = null) {
    const message = (suggestedMessage ?? inputEl.value).trim();

    if (!message) {
        return;
    }

    inputEl.disabled = true;
    sendBtn.disabled = true;
    appendMessage(message, 'user');
    inputEl.value = '';
    showTyping();

    try {
        const response = await fetch(messages.dataset.chatUrl || '/pearlie/chat', {
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

sendBtn.addEventListener('click', sendMessage);
languageToggle.addEventListener('change', updateLanguage);
quickReplyButtons.forEach((button) => {
    button.addEventListener('click', () => {
        sendMessage(languageToggle.value === 'sw' ? button.dataset.messageSw : button.dataset.messageEn);
    });
});
resetBtn.addEventListener('click', (event) => {
    event.preventDefault();
    resetChat();
});
inputEl.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
});
updateLanguage();
inputEl.focus();
