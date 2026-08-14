<?php
/**
 * SessionStore — File-Based Session Storage
 * Handles rate limiting and chat history persistence using JSON files.
 * Each session_id maps to a JSON file in the storage directory.
 * 
 * Automatically cleans up sessions idle for more than 30 minutes.
 */

class SessionStore
{
    private string $storageDir;
    private int $rateLimitPerMinute;
    private int $maxHistoryMessages;
    private const SESSION_TTL = 1800; // 30 minutes

    public function __construct(array $config)
    {
        $this->storageDir = $config['storage_dir'];
        $this->rateLimitPerMinute = $config['rate_limit_per_minute'];
        $this->maxHistoryMessages = $config['max_history_messages'];

        // Ensure storage directory exists
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Get the file path for a session.
     */
    private function getSessionPath(string $sessionId): string
    {
        // Sanitize session_id to prevent directory traversal
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '', $sessionId);
        return $this->storageDir . '/' . $safe . '.json';
    }

    /**
     * Load session data from file.
     */
    private function loadSession(string $sessionId): array
    {
        $path = $this->getSessionPath($sessionId);
        if (!file_exists($path)) {
            return [
                'rate_limit' => [],
                'history'    => [],
                'last_active' => time(),
            ];
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [
            'rate_limit' => [],
            'history'    => [],
            'last_active' => time(),
        ];
    }

    /**
     * Save session data to file.
     */
    private function saveSession(string $sessionId, array $data): void
    {
        $data['last_active'] = time();
        $path = $this->getSessionPath($sessionId);
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Check if a session has exceeded the rate limit.
     * Returns true if the request is allowed, false if rate-limited.
     */
    public function checkRateLimit(string $sessionId): bool
    {
        $session = $this->loadSession($sessionId);
        $now = time();
        $window = 60; // 1 minute window

        // Filter timestamps to current window only
        $session['rate_limit'] = array_values(array_filter(
            $session['rate_limit'],
            fn($t) => ($now - $t) < $window
        ));

        if (count($session['rate_limit']) >= $this->rateLimitPerMinute) {
            $this->saveSession($sessionId, $session);
            return false;
        }

        $session['rate_limit'][] = $now;
        $this->saveSession($sessionId, $session);
        return true;
    }

    /**
     * Get conversation history for a session.
     */
    public function getHistory(string $sessionId): array
    {
        $session = $this->loadSession($sessionId);
        return $session['history'] ?? [];
    }

    /**
     * Add a message to conversation history.
     */
    public function addToHistory(string $sessionId, string $role, string $content): void
    {
        $session = $this->loadSession($sessionId);
        $session['history'][] = ['role' => $role, 'content' => $content];

        // Keep only the last N message pairs
        $maxItems = $this->maxHistoryMessages * 2;
        if (count($session['history']) > $maxItems) {
            $session['history'] = array_slice($session['history'], -$maxItems);
        }

        $this->saveSession($sessionId, $session);
    }

    /**
     * Cleanup stale sessions (idle > 30 min).
     * Called periodically — safe to call on every request.
     */
    public function cleanupStaleSessions(): void
    {
        $now = time();
        $files = glob($this->storageDir . '/*.json');

        if (!$files) return;

        $cleaned = 0;
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data || !isset($data['last_active'])) {
                unlink($file);
                $cleaned++;
                continue;
            }
            if (($now - $data['last_active']) > self::SESSION_TTL) {
                unlink($file);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            error_log("[SessionStore] Cleaned up {$cleaned} stale sessions.");
        }
    }
}
