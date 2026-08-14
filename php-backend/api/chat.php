<?php
/**
 * Chat API Endpoint — POST /api/chat.php
 * 
 * Receives: { "message": "...", "session_id": "..." }
 * Returns:  Server-Sent Events (SSE) stream
 * 
 * Features:
 *  - Input validation (empty, too long)
 *  - Rate limiting (30 req/min per session)
 *  - SSE streaming with proper headers
 *  - CORS support for cross-origin widget embedding
 *  - Complete error handling
 */

// ─── Disable Output Buffering (critical for SSE) ───
while (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}

// ─── No PHP Time Limit (streaming can take time) ───
set_time_limit(0);

// ─── Load Dependencies ───
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/RagService.php';

// ─── CORS Headers ───
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: {$origin}");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');

// ─── Handle Preflight (OPTIONS) ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Only Accept POST ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Parse JSON Body ───
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!$body || !isset($body['message'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Missing "message" field in request body.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message   = trim($body['message']);
$sessionId = $body['session_id'] ?? bin2hex(random_bytes(16));

// ─── Input Validation ───
if ($message === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Message cannot be empty.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxLen = $config['max_message_length'];
if (mb_strlen($message) > $maxLen) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => "Message too long. Maximum {$maxLen} characters."], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Rate Limiting ───
require_once __DIR__ . '/../includes/SessionStore.php';
$sessionStore = new SessionStore($config);

if (!$sessionStore->checkRateLimit($sessionId)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'detail' => 'عذراً، أنت ترسل رسائل كثيرة. انتظر قليلاً وحاول مرة أخرى.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Set SSE Headers ───
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');  // Disable Nginx buffering if behind proxy

error_log("[chat.php] Chat request from session " . substr($sessionId, 0, 8) . "...");

// ─── Stream Response ───
try {
    $rag = new RagService($config);
    $rag->chatStream($message, $sessionId);
} catch (\Throwable $e) {
    error_log("[chat.php] Critical error: " . $e->getMessage());
    $safeError = str_replace("\n", ' ', $e->getMessage());
    echo "data: [ERROR] {$safeError}\n\n";
    flush();
}
