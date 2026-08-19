<?php

use App\Exceptions\GmailAuthException;
use App\Exceptions\GmailConnectionException;
use App\Services\GmailClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

// The client captures the HTTP factory when it is built, so every test builds it after faking.
function profileCall(): Closure
{
    return fn () => (new GmailClient('client-id', 'secret-key', 'refresh-token'))->get('/users/me/profile');
}

it('reports a rejected refresh token as an auth failure', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    expect(profileCall())
        ->toThrow(GmailAuthException::class, 'Token has been expired or revoked.');
});

it('reports a token endpoint outage as a plain failure', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'backend error'], 503),
    ]);

    expect(profileCall())
        ->toThrow(RuntimeException::class, 'backend error')
        ->not->toThrow(GmailAuthException::class);
});

it('reports a rejected api call as an auth failure', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
            'error' => [
                'message' => 'Request had insufficient authentication scopes.',
                'errors' => [['message' => 'Insufficient Permission', 'reason' => 'insufficientPermissions']],
            ],
        ], 403),
    ]);

    expect(profileCall())
        ->toThrow(GmailAuthException::class, 'Request had insufficient authentication scopes.');
});

it('reports invalid credentials on an api call as an auth failure', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
            'error' => ['message' => 'Request had invalid authentication credentials.'],
        ], 401),
    ]);

    expect(profileCall())
        ->toThrow(GmailAuthException::class, 'Request had invalid authentication credentials.');
});

it('does not blame the credentials for a rate limit', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
            'error' => [
                'message' => 'User-rate limit exceeded.',
                'errors' => [['message' => 'User-rate limit exceeded.', 'reason' => 'userRateLimitExceeded']],
            ],
        ], 403),
    ]);

    expect(profileCall())
        ->toThrow(RuntimeException::class, 'User-rate limit exceeded.')
        ->not->toThrow(GmailAuthException::class);
});

it('does not blame the credentials when the api is switched off', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
            'error' => [
                'message' => 'Gmail API has not been used in project 1 before or it is disabled.',
                'errors' => [['message' => 'Access Not Configured.', 'reason' => 'accessNotConfigured']],
            ],
        ], 403),
    ]);

    expect(profileCall())
        ->toThrow(RuntimeException::class, 'Gmail API has not been used in project 1 before or it is disabled.')
        ->not->toThrow(GmailAuthException::class);
});

it('reports an unreachable host as a connection failure', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    expect(profileCall())
        ->toThrow(GmailConnectionException::class, 'HTTP request failed: Could not resolve host');
});
