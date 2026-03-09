<?php

use App\Services\GmailClient;

describe('secret redaction', function () {
    it('redacts client ID from error messages', function () {
        $client = new GmailClient(
            'my-client-id-12345',
            'my-secret-key',
            'my-refresh-token'
        );

        $message = 'Error: Invalid client my-client-id-12345';
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toBe('Error: Invalid client [REDACTED]');
    });

    it('redacts client secret from error messages', function () {
        $client = new GmailClient(
            'client-id',
            'super-secret-key-12345',
            'refresh-token'
        );

        $message = 'Failed with secret: super-secret-key-12345';
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toBe('Failed with secret: [REDACTED]');
    });

    it('redacts refresh token from error messages', function () {
        $client = new GmailClient(
            'client-id',
            'secret-key',
            'refresh-token-abc123456'
        );

        $message = 'Token refresh-token-abc123456 is invalid';
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toBe('Token [REDACTED] is invalid');
    });

    it('redacts multiple secrets in same message', function () {
        $client = new GmailClient(
            'client-id-12345',
            'secret-key-67890',
            'refresh-token-abc'
        );

        $message = 'client-id-12345 used secret-key-67890 with refresh-token-abc';
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toBe('[REDACTED] used [REDACTED] with [REDACTED]');
    });

    it('does not redact short secrets', function () {
        $client = new GmailClient(
            'short', // 5 chars - won't be redacted
            'verylongsecret',
            'alsolongtoken'
        );

        $message = 'The word short should not be redacted but verylongsecret should';
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toBe('The word short should not be redacted but [REDACTED] should');
    });
});

describe('error formatting', function () {
    it('preserves error message structure while redacting', function () {
        $client = new GmailClient(
            'client-id-testing-123',
            'my-secret-key-456',
            'refresh-token-789'
        );

        $message = "Gmail API error: Invalid credentials\nClient: client-id-testing-123";
        $redacted = $client->redactSecrets($message);

        expect($redacted)->toContain('Gmail API error: Invalid credentials');
        expect($redacted)->toContain('[REDACTED]');
        expect($redacted)->not->toContain('client-id-testing-123');
    });
});

describe('request methods', function () {
    it('uses DELETE for delete requests', function () {
        $client = new class('client-id', 'secret-key', 'refresh-token') extends GmailClient
        {
            public array $requests = [];

            protected function request(string $method, string $endpoint, array $params = [], ?array $data = null): array
            {
                $this->requests[] = compact('method', 'endpoint', 'params', 'data');

                return [];
            }
        };

        $client->delete('/users/me/settings/filters/filter-1');

        expect($client->requests)->toHaveCount(1);
        expect($client->requests[0]['method'])->toBe('DELETE');
        expect($client->requests[0]['endpoint'])->toBe('/users/me/settings/filters/filter-1');
    });
});
