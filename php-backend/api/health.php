<?php
/**
 * Health Check Endpoint — GET /api/health.php
 * Quick status check to verify the server is running.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$config = require __DIR__ . '/../config/config.php';

$status = [
    'status'  => 'ok',
    'service' => 'Innovera AI Chatbot (PHP)',
    'php'     => PHP_VERSION,
    'curl'    => function_exists('curl_init') ? 'available' : 'missing',
    'data'    => file_exists($config['data_dir'] . '/courses_data.json') ? 'loaded' : 'missing',
    'api_key' => !empty($config['groq_api_key']) ? 'configured' : 'MISSING — check .env file',
];

echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
