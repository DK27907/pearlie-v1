@extends('layouts.master')

@section('title', 'Chat with Pearlie | '.pearlie_config('hospital.name'))

@push('styles')
<style>
        .chat-page {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100dvh - 5rem);
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
            padding: 24px;
            overflow-y: auto;
            background: #f9fbfd;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .message {
            max-width: 80%;
            padding: 12px 18px;
            border-radius: 18px;
            line-height: 1.6;
            font-size: 0.95rem;
            word-wrap: break-word;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
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
            margin: 0 24px 12px;
            padding: 12px 18px;
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

        .quick-replies {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 0 20px 14px;
            background: #f9fbfd;
        }

        .quick-reply {
            border: 1px solid #d5e1ea;
            border-radius: 999px;
            background: #fff;
            padding: 8px 12px;
            color: #0a2f44;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .quick-reply:hover {
            border-color: #1a5276;
            background: #eff8fc;
        }

        .language-select {
            margin-left: auto;
            max-width: 140px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            padding: 7px 10px;
            color: #fff;
            font-size: 0.8rem;
        }

        .language-select option {
            color: #0a2f44;
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
            .chat-page {
                min-height: calc(100dvh - 5rem);
                padding: 0;
            }
            .chat-container {
                height: calc(100dvh - 5rem);
                max-height: none;
                border-radius: 0;
                margin: 0;
            }
            .chat-messages {
                padding: 16px;
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
                position: sticky;
                bottom: 0;
            }
            .quick-replies {
                padding: 0 16px 12px;
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
@endpush

@section('page')
<section class="chat-page">

    <div class="chat-container">

        <!-- ─── Header ─── -->
        <div class="chat-header">
            <span class="logo">🏥</span>
            <div>
                <div class="title">Pearlie AI Assistant</div>
                <div class="subtitle">{{ pearlie_config('hospital.name') }} &bull; {{ pearlie_config('hospital.location') }}</div>
            </div>
            <label class="sr-only" for="languageToggle">Chat language</label>
            <select id="languageToggle" class="language-select" aria-label="Chat language">
                <option value="en" @selected(pearlie_config('ai.default_language', 'en') === 'en')>English</option>
                <option value="sw" @selected(pearlie_config('ai.default_language', 'en') === 'sw')>Kiswahili</option>
            </select>
            <span class="badge">AI</span>
        </div>

        <!-- ─── Messages ─── -->
        <div class="chat-messages" id="messages" data-chat-url="{{ request()->routeIs('tenant.chat.page') ? route('tenant.chat.short', request()->route('slug')) : (request()->routeIs('tenant.home') ? route('tenant.chat', request()->route('slug')) : route('pearlie.chat')) }}">
            <div class="message ai"
                data-welcome-en="👋 Hello! I'm Pearlie, your healthcare assistant at {{ pearlie_config('hospital.name') }}. How can I help you today?"
                data-welcome-sw="👋 Habari! Mimi ni Pearlie, msaidizi wako wa afya katika Hospitali ya {{ pearlie_config('hospital.name') }}. Naweza kukusaidia vipi leo?"></div>
        </div>

        <div class="quick-replies" aria-label="Suggested questions">
            <button type="button" class="quick-reply" data-message-en="I want to book an appointment" data-message-sw="Ninataka kuweka miadi" data-label-en="Book Appointment" data-label-sw="Weka Miadi"></button>
            <button type="button" class="quick-reply" data-message-en="What services do you offer?" data-message-sw="Huduma zenu ni zipi?" data-label-en="Our Services" data-label-sw="Huduma"></button>
            <button type="button" class="quick-reply" data-message-en="I need emergency help" data-message-sw="Nina dharura" data-label-en="Emergency" data-label-sw="Dharura"></button>
            <button type="button" class="quick-reply" data-message-en="Where are you located?" data-message-sw="Mko wapi?" data-label-en="Location" data-label-sw="Mahali"></button>
        </div>

        <!-- ─── Typing Indicator ─── -->
        <div class="typing-indicator" id="typingIndicator" role="status" aria-live="polite">
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
                placeholder="Ask me anything about {{ pearlie_config('hospital.name') }}..."
                data-placeholder-en="Ask me anything about {{ pearlie_config('hospital.name') }}..."
                data-placeholder-sw="Uliza chochote kuhusu {{ pearlie_config('hospital.name') }}..."
                autocomplete="off"
                autofocus
            />
            <button id="sendBtn" aria-label="Send message">Send</button>
        </div>

        <!-- ─── Footer ─── -->
        <div class="chat-footer">
            ⚕️ Always consult a doctor for medical decisions &bull;
            <a href="#" id="resetChat">New Chat</a>
            <span class="mx-1" aria-hidden="true">·</span>
            <span>Powered by AxiomForge</span>
        </div>

    </div>

    </section>
@endsection

@push('scripts')
    @vite('resources/js/pearlie.js')
@endpush