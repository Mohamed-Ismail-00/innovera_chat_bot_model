<?php
/**
 * LlmService — Groq Cloud API Client
 * 
 * Handles SSE streaming from the Groq API using cURL.
 * Features:
 *  - Automatic model fallback chain (3 models)
 *  - Granular timeouts (connect / read)
 *  - Proper SSE frame parsing from Groq response
 *  - 401 (bad API key) short-circuit — stops retrying immediately
 */

class LlmService
{
    private string $apiKey;
    private string $apiUrl;
    private string $primaryModel;
    private array  $fallbackModels;
    private int    $connectTimeout;
    private int    $readTimeout;
    private float  $temperature;
    private int    $maxTokens;

    public function __construct(array $config)
    {
        $this->apiKey         = $config['groq_api_key'];
        $this->apiUrl         = $config['groq_api_url'];
        $this->primaryModel   = $config['groq_model'];
        $this->fallbackModels = $config['groq_fallback_models'];
        $this->connectTimeout = $config['connect_timeout'];
        $this->readTimeout    = $config['read_timeout'];
        $this->temperature    = $config['temperature'];
        $this->maxTokens      = $config['max_tokens'];
    }

    /**
     * Stream a response from the Groq API with automatic model fallback.
     * Calls $onChunk($text) for each content fragment received.
     * 
     * @param string   $prompt        User's message
     * @param string   $systemMessage System prompt with context
     * @param array    $history       Conversation history
     * @param callable $onChunk       Callback: function(string $text): void
     * @return bool    True if any chunk was delivered, false if all models failed
     * @throws RuntimeException If ALL models fail (triggers Direct Knowledge Fallback)
     */
    public function streamResponse(
        string   $prompt,
        string   $systemMessage,
        array    $history,
        callable $onChunk
    ): bool {
        // Build deduplicated model list: primary first, then fallbacks
        $modelsToTry = [];
        foreach (array_merge([$this->primaryModel], $this->fallbackModels) as $m) {
            if (!in_array($m, $modelsToTry)) {
                $modelsToTry[] = $m;
            }
        }

        foreach ($modelsToTry as $modelName) {
            error_log("[LlmService] Attempting Groq model: {$modelName}");

            try {
                $received = $this->streamSingleModel(
                    $prompt, $systemMessage, $history, $modelName, $onChunk
                );
                if ($received) {
                    return true;
                }
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                error_log("[LlmService] Model '{$modelName}' failed: {$msg}");

                // Don't retry on 401 — API key is invalid for ALL models
                if (str_contains($msg, 'HTTP 401')) {
                    error_log("[LlmService] Invalid API key. Stopping all retries.");
                    break;
                }
                continue;
            }
        }

        error_log("[LlmService] ALL Groq models failed. Triggering Direct Knowledge Fallback...");
        throw new \RuntimeException('ALL_LLM_MODELS_FAILED');
    }

    /**
     * Stream response from a single Groq model using cURL.
     */
    private function streamSingleModel(
        string   $prompt,
        string   $systemMessage,
        array    $history,
        string   $modelName,
        callable $onChunk
    ): bool {
        // Build messages array
        $messages = [['role' => 'system', 'content' => $systemMessage]];
        foreach ($history as $msg) {
            $messages[] = $msg;
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = json_encode([
            'model'       => $modelName,
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'User-Agent: InnoveraChatBot/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->readTimeout + $this->connectTimeout,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);

        // ─── Streaming SSE Parser ───
        $buffer = '';
        $receivedAnyChunk = false;
        $httpCode = 0;
        $errorBody = '';

        curl_setopt($ch, CURLOPT_WRITEFUNCTION,
            function ($ch, $data) use (&$buffer, &$receivedAnyChunk, &$httpCode, &$errorBody, $onChunk) {
                // Get HTTP status code (available after headers received)
                if ($httpCode === 0) {
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }

                // If non-200 response, collect error body for logging
                if ($httpCode !== 0 && $httpCode !== 200) {
                    $errorBody .= $data;
                    return strlen($data);
                }

                $buffer .= $data;

                // Process complete lines from buffer
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if (empty($line)) continue;
                    if (!str_starts_with($line, 'data: ')) continue;

                    $dataStr = substr($line, 6);

                    if (trim($dataStr) === '[DONE]') {
                        return strlen($data);
                    }

                    $json = json_decode($dataStr, true);
                    if ($json && isset($json['choices'][0]['delta']['content'])) {
                        $content = $json['choices'][0]['delta']['content'];
                        if ($content !== '') {
                            $receivedAnyChunk = true;
                            $onChunk($content);
                        }
                    }
                }

                return strlen($data);
            }
        );

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $finalHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Handle cURL errors (connection, DNS, timeout)
        if ($result === false) {
            $errorType = match (true) {
                in_array($curlErrno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT])
                    => 'Connection failed',
                $curlErrno === CURLE_OPERATION_TIMEDOUT
                    => 'Request timeout',
                default => 'cURL error',
            };
            throw new \RuntimeException("{$errorType} for '{$modelName}': {$curlError}");
        }

        // Handle HTTP error responses
        if ($finalHttpCode !== 200 && $finalHttpCode !== 0) {
            throw new \RuntimeException("HTTP {$finalHttpCode} from Groq model '{$modelName}'");
        }

        return $receivedAnyChunk;
    }
}
