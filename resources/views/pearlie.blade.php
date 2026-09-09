<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Pearlie AI Assistant</title>

    <style>
        /* ── Reset & Base ── */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f4f7fc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 16px;
        }

        /* ── Chat Container ── */
        .chat-container {
            width: 100%;
            max-width: 720px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 85vh;
            max-height: 750px;
            transition: all 0.2s ease;
        }

        /* ── Header ── */
        .chat-header {
            background: linear-gradient(135deg, #0a2f44, #1a5276);
            color: #fff;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .chat-header .logo {
            font-size: 28px;
            line-height: 1;
        }

        .chat-header .title {
            font-weight: 700;
            font-size: 1.3rem;
            letter-spacing: -0.3px;
        }

        .chat-header .subtitle {
            font-weight: 400;
            font-size: 0.8rem;
            opacity: 0.8;
            margin-top: 2px;
        }

        .chat-header .badge {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.15);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        /* ── Messages Area ── */
        .chat-messages {
            flex: 1;
            padding: 20px 24px;
            overflow-y: auto;
            background: #f9fbfd;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .message {
            max-width: 80%;
            padding: 12px 18px;
            border-radius: 18px;
            line-height: 1.6;
            font-size: 0.95rem;
            word-wrap: break-word;
            animation: fadeIn 0.25s ease;
        }

        .message.ai {
            background: #ffffff;
            color: #1a1a2e;
            align-self: flex-start;
            border: 1px solid #e9edf4;
            border-bottom-left-radius: 4px;
        }

        .message.user {
            background: #0a2f44;
            color: #ffffff;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .message .meta {
            font-size: 0.7rem;
            opacity: 0.6;
            margin-top: 6px;
            display: block;
        }

        /* ── Typing Indicator ── */
        .typing-indicator {
            display: none;
            align-self: flex-start;
            background: #e9edf4;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            color: #555;
            gap: 6px;
            align-items: center;
        }

        .typing-indicator .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #888;
            border-radius: 50%;
            animation: pulse 1.2s infinite ease-in-out;
        }

        .typing-indicator .dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        .typing-indicator .dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes pulse {
            0%,
            60%,
            100% {
                opacity: 0.3;
                transform: scale(0.9);
            }
            30% {
                opacity: 1;
                transform: scale(1.1);
            }
        }

        /* ── Input Area ── */
        .chat-input {
            display: flex;
            gap: 10px;
            padding: 14px 20px 20px;
            background: #ffffff;
            border-top: 1px solid #edf1f7;
            flex-shrink: 0;
        }

        .chat-input input {
            flex: 1;
            padding: 12px 18px;
            border: 1px solid #dce2ec;
            border-radius: 40px;
            font-size: 1rem;
            outline: none;
            transition: border 0.2s ease;
            background: #f7f9fc;
        }

        .chat-input input:focus {
            border-color: #1a5276;
            background: #ffffff;
        }

        .chat-input input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .chat-input button {
            padding: 12px 28px;
            background: #0a2f44;
            color: #fff;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
            white-space: nowrap;
        }

        .chat-input button:hover {
            background: #1a5276;
        }

        .chat-input button:active {
            transform: scale(0.96);
        }

        .chat-input button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* ── Footer ── */
        .chat-footer {
            text-align: center;
            padding: 10px 16px;
            font-size: 0.7rem;
            color: #aab;
            background: #ffffff;
            border-top: 1px solid #f0f3f8;
            flex-shrink: 0;
        }

        .chat-footer a {
            color: #1a5276;
            text-decoration: none;
        }

        /* ── Animations ── */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Scrollbar ── */
        .chat-messages::-webkit-scrollbar {
            width: 5px;
        }
        .chat-messages::-webkit-scrollbar-track {
            background: transparent;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: #d0d8e5;
            border-radius: 10px;
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .chat-container {
                height: 95vh;
                max-height: 95vh;
                border-radius: 16px;
                margin: 0;
            }
            .chat-header .title {
                font-size: 1.1rem;
            }
            .message {
                max-width: 90%;
                font-size: 0.9rem;
                padding: 10px 14px;
            }
            .chat-input {
                padding: 10px 16px 16px;
                gap: 8px;
            }
            .chat-input input {
                padding: 10px 14px;
                font-size: 0.95rem;
            }
            .chat-input button {
                padding: 10px 18px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>

    <div class="chat-container">

        <!-- ─── Header ─── -->
        <div class="chat-header">
            <span class="logo">🏥</span>
            <div>
                <div class="title">Pearlie AI Assistant</div>
                <div class="subtitle">Pearl Hospital &bull; Nyahururu</div>
            </div>
            <span class="badge">AI</span>
        </div>

        <!-- ─── Messages ─── -->
        <div class="chat-messages" id="messages">
            <div class="message ai">
                👋 Hello! I'm Pearlie, your healthcare assistant at Pearl Hospital. How can I help you today?
            </div>
        </div>

        <!-- ─── Typing Indicator ─── -->
        <div class="typing-indicator" id="typingIndicator">
            <span>Pearlie is thinking</span>
            <span class="dot"></span>
            <span class="dot"></span>
            <span class="dot"></span>
        </div>

        <!-- ─── Input ─── -->
        <div class="chat-input">
            <input
                type="text"
                id="userInput"
                placeholder="Ask me anything about Pearl Hospital..."
                autocomplete="off"
                autofocus
            />
            <button id="sendBtn">Send</button>
        </div>

        <!-- ─── Footer ─── -->
        <div class="chat-footer">
            ⚕️ Always consult a doctor for medical decisions &bull;
            <a href="#" id="resetChat">New Chat</a>
        </div>

    </div>

    @vite('resources/js/pearlie.js')

</body>
</html>