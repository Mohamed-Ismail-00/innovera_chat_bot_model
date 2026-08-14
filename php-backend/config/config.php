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
    'groq_model'          => getenv('GROQ_MODEL') ?: 'llama-3.1-8b-instant',
    'groq_fallback_models' => [
        'llama-3.1-8b-instant',
        'llama3-8b-8192',
        'llama-3.3-70b-versatile',
    ],
    'groq_api_url'        => 'https://api.groq.com/openai/v1/chat/completions',

    // --- Timeouts (seconds) ---
    'connect_timeout'     => 15,
    'read_timeout'        => 30,

    // --- LLM Parameters ---
    'temperature'         => 0.3,
    'max_tokens'          => 1200,

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
