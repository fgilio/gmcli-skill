<?php

use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/gmcli-accounts-test-'.uniqid();
    mkdir($this->tempDir, 0700, true);

    $paths = new GmcliPaths($this->tempDir);
    $this->env = new GmcliEnv($paths);
    $this->env->set('GOOGLE_CLIENT_ID', 'client-id');
    $this->env->set('GOOGLE_CLIENT_SECRET', 'secret-key');
    $this->env->addAccount('first@gmail.com', 'token-1', ['alias@gmail.com']);
    $this->env->addAccount('second@work.com', 'token-2');
    $this->env->save();

    app()->instance(GmcliPaths::class, $paths);
    app()->instance(GmcliEnv::class, $this->env);

    Http::preventStrayRequests();
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($this->tempDir);
    }
});

it('lists every account and marks the default', function () {
    $this->artisan('accounts:list')
        ->expectsOutput('first@gmail.com (default)')
        ->expectsOutput('  Aliases: alias@gmail.com')
        ->expectsOutput('second@work.com')
        ->assertSuccessful();
});

it('lists accounts as a json array with a default field', function () {
    $this->artisan('accounts:list', ['--json' => true])
        ->expectsOutput('{"data":[{"email":"first@gmail.com","aliases":["alias@gmail.com"],"default":true},{"email":"second@work.com","aliases":[],"default":false}]}')
        ->assertSuccessful();
});

it('switches the default account', function () {
    $this->artisan('accounts:default', ['email' => 'second@work.com'])
        ->expectsOutputToContain('Default account: second@work.com')
        ->assertSuccessful();

    expect($this->env->reload()->getEmail())->toBe('second@work.com');
});

it('switches the default account by alias', function () {
    $this->artisan('accounts:default', ['email' => 'second@work.com'])->assertSuccessful();
    $this->artisan('accounts:default', ['email' => 'ALIAS@GMAIL.COM'])->assertSuccessful();

    expect($this->env->reload()->getEmail())->toBe('first@gmail.com');
});

it('rejects an unknown default account', function () {
    $this->artisan('accounts:default', ['email' => 'nobody@work.com'])
        ->expectsOutputToContain('Account not found: nobody@work.com. Configured accounts: first@gmail.com, second@work.com')
        ->assertFailed();
});

it('removes one account and keeps the other', function () {
    $this->artisan('accounts:remove', ['email' => 'first@gmail.com'])
        ->expectsOutputToContain('Account removed: first@gmail.com')
        ->expectsOutputToContain('Default account is now: second@work.com')
        ->assertSuccessful();

    expect($this->env->reload()->accountEmails())->toBe(['second@work.com']);
});

it('rejects removing an account that is not configured', function () {
    $this->artisan('accounts:remove', ['email' => 'nobody@work.com'])
        ->expectsOutputToContain('Account not found: nobody@work.com. Configured accounts: first@gmail.com, second@work.com')
        ->assertFailed();

    expect($this->env->reload()->accounts())->toHaveCount(2);
});

it('authenticates gmail commands with the refresh token of the selected account', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response(['filter' => []], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response(['labels' => []], 200),
    ]);

    $this->artisan('gmail:filters:list', ['--account' => 'second@work.com'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request->data()['refresh_token'] === 'token-2');
});

it('selects an account by alias', function () {
    $this->artisan('accounts:default', ['email' => 'second@work.com'])->assertSuccessful();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response(['filter' => []], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response(['labels' => []], 200),
    ]);

    $this->artisan('gmail:filters:list', ['--account' => 'alias@gmail.com'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request->data()['refresh_token'] === 'token-1');
});

it('names the configured accounts when the requested one is unknown', function () {
    $this->artisan('gmail:filters:list', ['--account' => 'nobody@work.com'])
        ->expectsOutputToContain(
            'Account not configured: nobody@work.com. Configured accounts: first@gmail.com, second@work.com. '
            .'Add it with: gmcli accounts:add nobody@work.com'
        )
        ->assertFailed();
});
