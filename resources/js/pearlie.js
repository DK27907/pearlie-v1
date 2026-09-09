const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('userInput');
const sendBtn = document.getElementById('sendBtn');
const typingEl = document.getElementById('typingIndicator');
const resetBtn = document.getElementById('resetChat');
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

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
    messagesEl.innerHTML = `<div class="message ai">👋 Hello! I'm Pearlie, your healthcare assistant at Pearl Hospital. How can I help you today?</div>`;
    inputEl.value = '';
    inputEl.focus();
}

async function sendMessage() {
    const message = inputEl.value.trim();

    if (!message) {
        return;
    }

    inputEl.disabled = true;
    sendBtn.disabled = true;
    appendMessage(message, 'user');
    inputEl.value = '';
    showTyping();

    try {
        const response = await fetch('/pearlie/chat', {
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
inputEl.focus();
