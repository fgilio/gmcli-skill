<?php

use App\Commands\Gmail\BaseGmailCommand;
use App\Exceptions\GmailAuthException;
use App\Exceptions\GmailConnectionException;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reaches the protected hook that turns a thrown exception into
 * rendered output, with the JSON mode switchable.
 */
final class AuthFailureProbeCommand extends BaseGmailCommand
{
    protected $signature = 'probe:auth-failure';

    public bool $json = false;

    public function renderFor(Throwable $e, string $account): ?array
    {
        $this->account = $account;

        return $this->extractExceptionDetails($e);
    }

    protected function wantsJson(): bool
    {
        return $this->json;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/gmcli-auth-failure-test-'.uniqid();
    mkdir($this->tempDir, 0700, true);

    $paths = new GmcliPaths($this->tempDir);
    $env = new GmcliEnv($paths);
    $env->set('GOOGLE_CLIENT_ID', 'client-id');
    $env->set('GOOGLE_CLIENT_SECRET', 'secret-key');
    $env->addAccount('first@gmail.com', 'token-1');
    $env->save();

    app()->instance(GmcliPaths::class, $paths);
    app()->instance(GmcliEnv::class, $env);

    Http::preventStrayRequests();
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->tempDir);
});

it('tells the user which account to re-authenticate when a command hits a dead token', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    $this->artisan('gmail:search', ['query' => 'from:someone'])
        ->expectsOutputToContain(implode("\n", [
            'Gmail rejected the credentials for first@gmail.com.',
            'Reason: Token has been expired or revoked.',
            'Re-authenticate: gmcli accounts:add first@gmail.com',
            'Check every account: gmcli accounts:doctor',
        ]))
        ->assertExitCode(1);
});

it('leaves a connection failure with its own message', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => fn () => throw new ConnectionException('Could not resolve host'),
    ]);

    $this->artisan('gmail:search', ['query' => 'from:someone'])
        ->expectsOutputToContain('HTTP request failed: Could not resolve host')
        ->doesntExpectOutputToContain('Re-authenticate')
        ->assertExitCode(1);
});

it('keeps the whole auth failure on one line for json consumers', function () {
    $command = new AuthFailureProbeCommand;
    $command->json = true;

    $details = $command->renderFor(
        new GmailAuthException("Token has been expired\nor revoked."),
        'first@gmail.com'
    );

    expect($details['message'])
        ->toBe('Gmail rejected the credentials for first@gmail.com. Reason: Token has been expired or revoked. Re-authenticate: gmcli accounts:add first@gmail.com Check every account: gmcli accounts:doctor')
        ->and($details['message'])->not->toContain("\n");
});

it('leaves non auth failures to the default rendering', function () {
    $command = new AuthFailureProbeCommand;

    expect($command->renderFor(new GmailConnectionException('Could not resolve host'), 'first@gmail.com'))->toBeNull()
        ->and($command->renderFor(new RuntimeException('Gmail API error: HTTP 500'), 'first@gmail.com'))->toBeNull();
});

it('still points a scope failure at the re-consent flow', function () {
    $command = new AuthFailureProbeCommand;

    $details = $command->renderFor(
        new GmailAuthException('Gmail API error: Request had insufficient authentication scopes.'),
        'first@gmail.com'
    );

    expect($details['message'])->toContain('gmcli accounts:remove first@gmail.com');
});
