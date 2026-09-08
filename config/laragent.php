<?php

use LarAgent\Context\Drivers\CacheStorage;
use LarAgent\Context\Drivers\FileStorage;
use LarAgent\Context\Truncation\SimpleTruncationStrategy;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\Groq\GroqDriver;
use LarAgent\Drivers\OpenAi\GeminiDriver;
use LarAgent\Drivers\OpenAi\OllamaDriver;
use LarAgent\Drivers\OpenAi\OpenAiCompatible;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
use LarAgent\Drivers\OpenAi\OpenRouter;
use LarAgent\History\InMemoryChatHistory;
use Redberry\MCPClient\Enums\Transporters;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The default LLM driver to use when no provider is specified.
    | We set it to OpenAiCompatible because Groq uses an OpenAI-compatible API.
    |
    */
    'default_driver' => OpenAiCompatible::class,

    /*
    |--------------------------------------------------------------------------
    | Default Chat History
    |--------------------------------------------------------------------------
    |
    | Where conversation history is stored. InMemory is fine for testing.
    | For production, you may want to use database storage.
    |
    */
    'default_chat_history' => InMemoryChatHistory::class,

    /*
    |--------------------------------------------------------------------------
    | Default History Storage Drivers
    |--------------------------------------------------------------------------
    */
    'default_history_storage' => [
        CacheStorage::class,
        FileStorage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Storage Drivers
    |--------------------------------------------------------------------------
    */
    'default_storage' => [
        CacheStorage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Namespaces
    |--------------------------------------------------------------------------
    |
    | Where LarAgent looks for Agent classes.
    |
    */
    'namespaces' => [
        'App\\AiAgents\\',
        'App\\Agents\\',
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each provider represents an LLM service you can use.
    | The 'default' provider is used if no provider is specified.
    |
    | For Groq, we use the OpenAiCompatible driver because Groq's API is
    | OpenAI-compatible. The base URL is set to Groq's endpoint.
    |
    */
    'providers' => [

        // ─── DEFAULT PROVIDER: Groq ──────────────────────────────────────────
        'default' => [
            'label' => 'groq',
            'api_key' => env('GROQ_API_KEY'),
            'driver' => OpenAiCompatible::class,
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => env('GROQ_MODEL', 'llama-3.1-70b-versatile'),
            'default_truncation_threshold' => 131072,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 0.7,
        ],

        // ─── Groq (Explicit) ─────────────────────────────────────────────────
        'groq' => [
            'label' => 'groq',
            'api_key' => env('GROQ_API_KEY'),
            'driver' => OpenAiCompatible::class,
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => env('GROQ_MODEL', 'llama-3.1-70b-versatile'),
            'default_truncation_threshold' => 131072,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 0.7,
        ],

        // ─── OpenAI ──────────────────────────────────────────────────────────
        'openai' => [
            'label' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'driver' => OpenAiDriver::class,
            'default_truncation_threshold' => 50000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
        ],

        // ─── Gemini ──────────────────────────────────────────────────────────
        'gemini' => [
            'label' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'driver' => GeminiDriver::class,
            'default_truncation_threshold' => 1000000,
            'default_max_completion_tokens' => 10000,
            'default_temperature' => 1,
            'model' => 'gemini-2.0-flash-latest',
        ],

        // ─── Claude ──────────────────────────────────────────────────────────
        'claude' => [
            'label' => 'claude',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-3-7-sonnet-latest',
            'driver' => ClaudeDriver::class,
            'default_truncation_threshold' => 200000,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 1,
        ],

        // ─── Ollama (Local) ──────────────────────────────────────────────────
        'ollama' => [
            'label' => 'ollama',
            'driver' => OllamaDriver::class,
            'default_truncation_threshold' => 131072,
            'default_max_completion_tokens' => 131072,
            'default_temperature' => 0.8,
        ],

        // ─── OpenRouter ──────────────────────────────────────────────────────
        'openrouter' => [
            'label' => 'openrouter',
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => 'openai/gpt-oss-20b:free',
            'driver' => OpenRouter::class,
            'default_truncation_threshold' => 200000,
            'default_max_completion_tokens' => 8192,
            'default_temperature' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Providers (Fallback Chain)
    |--------------------------------------------------------------------------
    |
    | If the primary provider fails, LarAgent will try the fallback providers
    | in the order listed. This ensures reliability.
    |
    */
    'default_providers' => [
        'default',   // Primary: Groq
        'groq',      // Fallback 1: Groq (explicit)
        'gemini',    // Fallback 2: Gemini (if you have a key)
        'ollama',    // Fallback 3: Ollama (local)
    ],

    /*
    |--------------------------------------------------------------------------
    | Truncation Settings
    |--------------------------------------------------------------------------
    */
    'enable_truncation' => false,
    'truncation_provider' => 'default',
    'default_truncation_strategy' => SimpleTruncationStrategy::class,
    'default_truncation_config' => [
        'keep_messages' => 10,
        'preserve_system' => true,
    ],
    'truncation_buffer' => 0.2,

    /*
    |--------------------------------------------------------------------------
    | MCP Servers
    |--------------------------------------------------------------------------
    */
    'mcp_tool_caching' => [
        'enabled' => env('MCP_TOOL_CACHE_ENABLED', false),
        'ttl' => env('MCP_TOOL_CACHE_TTL', 3600),
        'store' => env('MCP_TOOL_CACHE_STORE', null),
    ],

    'mcp_servers' => [
        'github' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'token' => env('GITHUB_API_TOKEN', null),
            'headers' => [],
            'id_type' => 'int',
        ],
        'mcp_server_memory' => [
            'type' => Transporters::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/server-memory',
            ],
            'timeout' => 30,
            'cwd' => base_path(),
            'startup_delay' => 100,
            'poll_interval' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    */
    'track_usage' => false,
    'default_usage_storage' => null,
];



