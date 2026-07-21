<?php

namespace Clarte;

/**
 * Client HTTP pour un modèle compatible "chat completions" (GitHub Models
 * par défaut, mais compatible avec tout endpoint respectant ce format,
 * y compris l'API Anthropic/OpenAI via un endpoint adapté).
 *
 * Gère : le délai configurable entre appels, les tentatives avec backoff
 * exponentiel, le timeout, et les erreurs de quota (HTTP 429).
 */
class GithubModel
{
    private array $config;
    private Logger $logger;
    private ?string $token;
    private float $lastCallTime = 0;

    public function __construct(array $aiConfig, Logger $logger)
    {
        $this->config = $aiConfig;
        $this->logger = $logger;
        $this->token = getenv($aiConfig['token_env_var']) ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled']) && !empty($this->token);
    }

    /**
     * @return array{success:bool, data?:array, error?:string}
     */
    public function analyze(string $prompt): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => "IA non configurée (token absent ou 'enabled' = false)"];
        }

        $this->respectRateLimit();

        $attempt = 0;
        $maxRetries = $this->config['max_retries'] ?? 3;

        while ($attempt < $maxRetries) {
            $attempt++;
            $response = $this->callApi($prompt);

            if ($response['success']) {
                return $response;
            }

            if ($response['http_code'] === 429) {
                $backoff = min(30, (int) pow(2, $attempt));
                $this->logger->warning("Quota IA atteint (429), attente {$backoff}s avant nouvelle tentative ({$attempt}/{$maxRetries})");
                sleep($backoff);
                continue;
            }

            if ($response['http_code'] >= 500) {
                $this->logger->warning("Erreur serveur IA ({$response['http_code']}), tentative {$attempt}/{$maxRetries}");
                usleep(500_000 * $attempt);
                continue;
            }

            // erreur non récupérable (401, 400...)
            return ['success' => false, 'error' => $response['error'] ?? 'Erreur inconnue'];
        }

        return ['success' => false, 'error' => "Échec après {$maxRetries} tentatives"];
    }

    private function respectRateLimit(): void
    {
        $delayMs = $this->config['delay_ms'] ?? 1000;
        $elapsedMs = (microtime(true) - $this->lastCallTime) * 1000;
        if ($this->lastCallTime > 0 && $elapsedMs < $delayMs) {
            usleep((int) (($delayMs - $elapsedMs) * 1000));
        }
        $this->lastCallTime = microtime(true);
    }

    private function callApi(string $prompt): array
    {
        $payload = json_encode([
            'model'       => $this->config['model'],
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => $this->config['max_tokens'] ?? 800,
            'temperature' => $this->config['temperature'] ?? 0.2,
        ]);

        $ch = curl_init($this->config['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_TIMEOUT => $this->config['timeout_sec'] ?? 30,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => "Erreur réseau : {$curlError}", 'http_code' => 0];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => "HTTP {$httpCode} : " . substr((string) $raw, 0, 300), 'http_code' => $httpCode];
        }

        $decoded = json_decode($raw, true);
        $text = $decoded['choices'][0]['message']['content'] ?? null;

        if (!$text) {
            return ['success' => false, 'error' => 'Reponse IA vide ou format inattendu', 'http_code' => $httpCode];
        }

        // l'IA doit renvoyer du JSON pur ; on nettoie les éventuels ```json
        $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $parsed = json_decode($clean, true);

        if (!is_array($parsed)) {
            return ['success' => false, 'error' => 'Reponse IA non parseable en JSON', 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $parsed, 'http_code' => $httpCode];
    }
}
