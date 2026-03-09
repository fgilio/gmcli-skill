<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Gmail API client with automatic token refresh.
 *
 * Handles authenticated requests to Gmail API with:
 * - Automatic access token refresh on 401
 * - Secret redaction in error messages
 * - Retry logic for transient failures
 */
class GmailClient
{
    private const API_BASE = 'https://gmail.googleapis.com/gmail/v1';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private string $clientId;

    private string $clientSecret;

    private string $refreshToken;

    private ?string $accessToken = null;

    private int $tokenExpiry = 0;

    private ?GmailLogger $logger = null;

    private HttpFactory $http;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        ?HttpFactory $http = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->http = $http ?? app(HttpFactory::class);
    }

    public function setLogger(GmailLogger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Makes authenticated GET request to Gmail API.
     *
     * @throws RuntimeException on failure
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Makes authenticated POST request to Gmail API.
     *
     * @throws RuntimeException on failure
     */
    public function post(string $endpoint, array $data = [], array $params = []): array
    {
        return $this->request('POST', $endpoint, $params, $data);
    }

    /**
     * Makes authenticated DELETE request to Gmail API.
     *
     * @throws RuntimeException on failure
     */
    public function delete(string $endpoint, array $params = []): array
    {
        return $this->request('DELETE', $endpoint, $params);
    }

    /**
     * Makes authenticated request with automatic retry on 401.
     *
     * @throws RuntimeException on failure
     */
    protected function request(string $method, string $endpoint, array $params = [], ?array $data = null): array
    {
        $this->ensureAccessToken();

        $url = self::API_BASE.$endpoint;
        if (! empty($params)) {
            $url .= '?'.http_build_query($params);
        }

        $this->log('debug', "{$method} {$url}");

        $response = $this->httpRequest($method, $url, $data);

        // Retry once on 401
        if ($response['status'] === 401) {
            $this->log('verbose', 'Access token expired, refreshing...');
            $this->accessToken = null;
            $this->ensureAccessToken();

            $response = $this->httpRequest($method, $url, $data);
        }

        if ($response['status'] >= 400) {
            $error = $this->extractError($response);
            throw new RuntimeException($this->redactSecrets($error));
        }

        return $response['body'];
    }

    /**
     * Ensures we have a valid access token.
     *
     * @throws RuntimeException on failure
     */
    private function ensureAccessToken(): void
    {
        if ($this->accessToken && time() < $this->tokenExpiry - 60) {
            return;
        }

        $this->log('debug', 'Refreshing access token...');

        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ];

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout(30)
                ->post(self::TOKEN_URL, $data);
        } catch (ConnectionException $e) {
            throw new RuntimeException("HTTP request failed: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            $decoded = $response->json() ?? [];
            $error = $decoded['error_description'] ?? $decoded['error'] ?? 'Token refresh failed';
            throw new RuntimeException($this->redactSecrets($error));
        }

        $decoded = $response->json();
        $this->accessToken = $decoded['access_token'];
        $this->tokenExpiry = time() + ($decoded['expires_in'] ?? 3600);

        $this->log('debug', 'Access token refreshed');
    }

    /**
     * Makes HTTP request with authentication.
     */
    protected function httpRequest(string $method, string $url, ?array $data = null): array
    {
        try {
            $request = $this->http
                ->withToken($this->accessToken)
                ->acceptJson()
                ->timeout(60);

            $options = [];

            if ($data !== null) {
                $options['json'] = $data;
            }

            $response = $request->send($method, $url, $options);
        } catch (ConnectionException $e) {
            throw new RuntimeException("HTTP request failed: {$e->getMessage()}");
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? [],
            'raw' => $response->body(),
        ];
    }

    /**
     * Extracts error message from API response.
     */
    private function extractError(array $response): string
    {
        $body = $response['body'];

        if (isset($body['error']['message'])) {
            return "Gmail API error: {$body['error']['message']}";
        }

        if (isset($body['error'])) {
            return 'Gmail API error: '.(is_string($body['error']) ? $body['error'] : json_encode($body['error']));
        }

        return "Gmail API error: HTTP {$response['status']}";
    }

    /**
     * Redacts sensitive information from error messages.
     */
    public function redactSecrets(string $message): string
    {
        $secrets = [
            $this->clientId,
            $this->clientSecret,
            $this->refreshToken,
        ];

        if ($this->accessToken) {
            $secrets[] = $this->accessToken;
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) > 8) {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return $message;
    }

    private function log(string $level, string $message): void
    {
        $this->logger?->log($level, $message);
    }
}
