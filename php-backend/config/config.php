<?php
/**
 * Innovera AI Chatbot — Application Configuration
 * Centralized settings for the PHP backend.
 * Loads environment variables from .env file.
 */

// ─── Load .env file ───
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            putenv($line);
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// ─── Application Configuration ───
return [
    // --- Groq Cloud API ---
    'groq_api_key'        => getenv('GROQ_API_KEY') ?: '',
    'groq_model'          => getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b',
    'groq_fallback_models' => [
        'openai/gpt-oss-120b',
        'qwen/qwen3.6-27b',
        'openai/gpt-oss-20b',
    ],
    'groq_api_url'        => 'https://api.groq.com/openai/v1/chat/completions',

    // --- Timeouts (seconds) ---
    'connect_timeout'     => 30,
    'read_timeout'        => 60,

    // --- LLM Parameters ---
    'temperature'         => 0.55,
    'max_tokens'          => 1500,

    // --- Session & Rate Limiting ---
    'max_history_messages' => 6,
    'rate_limit_per_minute' => 30,
    'max_message_length'  => 500,

    // --- Paths ---
    'data_dir'            => __DIR__ . '/../data',
    'storage_dir'         => __DIR__ . '/../storage/sessions',

    // --- CORS ---
    'cors_origins'        => ['*'],
];
