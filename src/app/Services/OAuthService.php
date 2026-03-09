<?php

namespace App\Services;

use RuntimeException;

/**
 * Handles Google OAuth 2.0 authentication flow.
 *
 * Supports two modes:
 * - Auto: Starts local callback server on random port
 * - Manual: Uses localhost:1 redirect, user pastes URL
 */
class OAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.settings.basic',
    ];

    private string $clientId;

    private string $clientSecret;

    private int $timeout;

    public function __construct(string $clientId, string $clientSecret, int $timeout = 120)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->timeout = $timeout;
    }

    /**
     * Runs OAuth flow with automatic callback server.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *
     * @throws RuntimeException on failure
     */
    public function runAutoFlow(callable $onBrowserOpen): array
    {
        $server = $this->startCallbackServer();
        $port = $this->getServerPort($server);
        $redirectUri = "http://127.0.0.1:{$port}";

        $authUrl = $this->buildAuthUrl($redirectUri);
        $onBrowserOpen($authUrl);

        $code = $this->waitForCallback($server);
        fclose($server);

        return $this->exchangeCode($code, $redirectUri);
    }

    /**
     * Runs OAuth flow with manual URL paste.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *
     * @throws RuntimeException on failure
     */
    public function runManualFlow(callable $onAuthUrl, callable $onPromptRedirectUrl): array
    {
        $redirectUri = 'http://localhost:1';
        $authUrl = $this->buildAuthUrl($redirectUri);

        $onAuthUrl($authUrl);
        $redirectedUrl = $onPromptRedirectUrl();

        $code = $this->extractCodeFromUrl($redirectedUrl);

        return $this->exchangeCode($code, $redirectUri);
    }

    /**
     * Builds the OAuth authorization URL.
     */
    public function buildAuthUrl(string $redirectUri): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /**
     * Exchanges authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *
     * @throws RuntimeException on failure
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ];

        $response = $this->httpPost(self::TOKEN_URL, $data);

        if (! isset($response['refresh_token'])) {
            throw new RuntimeException(
                'No refresh token received. Ensure prompt=consent was used.'
            );
        }

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'] ?? 3600,
        ];
    }

    /**
     * Extracts authorization code from redirect URL.
     *
     * @throws RuntimeException if code not found or error present
     */
    public function extractCodeFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (! isset($parsed['query'])) {
            throw new RuntimeException('No query parameters in redirect URL');
        }

        parse_str($parsed['query'], $params);

        if (isset($params['error'])) {
            $errorDesc = $params['error_description'] ?? $params['error'];
            throw new RuntimeException("OAuth error: {$errorDesc}");
        }

        if (! isset($params['code'])) {
            throw new RuntimeException('No authorization code in redirect URL');
        }

        return $params['code'];
    }

    /**
     * Starts a TCP server socket on a random port.
     *
     * @return resource
     *
     * @throws RuntimeException on failure
     */
    private function startCallbackServer()
    {
        $errorCode = 0;
        $errorMessage = '';
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => true,
                'backlog' => 1,
            ],
        ]);

        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($server === false) {
            throw new RuntimeException("Failed to start callback server: {$errorMessage}");
        }

        return $server;
    }

    /**
     * Gets the port number assigned to a bound socket.
     *
     * @param  resource  $server
     */
    private function getServerPort($server): int
    {
        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            throw new RuntimeException('Failed to determine callback server port');
        }

        $separator = strrpos($name, ':');
        if ($separator === false) {
            throw new RuntimeException('Failed to parse callback server port');
        }

        $port = (int) substr($name, $separator + 1);

        return $port;
    }

    /**
     * Waits for OAuth callback and extracts the code.
     *
     * @param  resource  $server
     *
     * @throws RuntimeException on timeout or error
     */
    private function waitForCallback($server): string
    {
        stream_set_blocking($server, false);

        $start = time();
        $client = false;

        while (time() - $start < $this->timeout) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                break;
            }
            usleep(100000); // 100ms
        }

        if ($client === false) {
            throw new RuntimeException("OAuth callback timeout after {$this->timeout} seconds");
        }

        $request = stream_get_contents($client, 4096);

        // Extract code from GET request
        $code = $this->extractCodeFromHttpRequest($request);

        // Send success response
        $response = $this->buildSuccessResponse();
        fwrite($client, $response);
        fclose($client);

        return $code;
    }

    /**
     * Extracts authorization code from HTTP request.
     *
     * @throws RuntimeException if code not found or error present
     */
    public function extractCodeFromHttpRequest(string $request): string
    {
        if (! preg_match('/GET ([^\s]+)/', $request, $matches)) {
            throw new RuntimeException('Invalid HTTP request format');
        }

        $uri = $matches[1];
        $parsed = parse_url($uri);

        if (! isset($parsed['query'])) {
            throw new RuntimeException('No query parameters in callback');
        }

        parse_str($parsed['query'], $params);

        if (isset($params['error'])) {
            $errorDesc = $params['error_description'] ?? $params['error'];
            throw new RuntimeException("OAuth error: {$errorDesc}");
        }

        if (! isset($params['code'])) {
            throw new RuntimeException('No authorization code in callback');
        }

        return $params['code'];
    }

    /**
     * Builds HTML response for successful OAuth callback.
     */
    private function buildSuccessResponse(): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>gmcli - Authentication Complete</title></head>
<body style="font-family: system-ui; text-align: center; padding: 50px;">
<h1>✓ Authentication Complete</h1>
<p>You can close this window and return to the terminal.</p>
</body>
</html>
HTML;

        $length = strlen($html);

        return "HTTP/1.1 200 OK\r\n"
            ."Content-Type: text/html\r\n"
            ."Content-Length: {$length}\r\n"
            ."Connection: close\r\n"
            ."\r\n"
            .$html;
    }

    /**
     * Makes HTTP POST request with form data.
     *
     * @throws RuntimeException on failure
     */
    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['error'] ?? 'Unknown error')
                : $response;
            throw new RuntimeException("Token exchange failed: {$errorMsg}");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from token endpoint');
        }

        return $decoded;
    }
}
