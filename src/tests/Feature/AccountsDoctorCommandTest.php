<?php

use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/gmcli-doctor-test-'.uniqid();
    mkdir($this->tempDir, 0700, true);

    $this->bindEnv = function (callable $configure): GmcliEnv {
        $home = $this->tempDir.'/'.uniqid('home-');
        mkdir($home, 0700, true);

        $paths = new GmcliPaths($home);
        $this->envPath = $paths->envFile();
        $env = new GmcliEnv($paths);
        $configure($env);
        $env->save();

        app()->instance(GmcliPaths::class, $paths);
        app()->instance(GmcliEnv::class, $env);

        return $env;
    };

    /** Access tokens carry the refresh token, so a faked profile can tell the accounts apart. */
    $this->fakeGmail = function (array $profiles, array $tokenFailures = []): void {
        Http::fake([
            'https://oauth2.googleapis.com/token' => function (Request $request) use ($tokenFailures) {
                $refreshToken = $request->data()['refresh_token'];

                if (isset($tokenFailures[$refreshToken])) {
                    return Http::response($tokenFailures[$refreshToken], 400);
                }

                return Http::response(['access_token' => 'access-'.$refreshToken, 'expires_in' => 3600], 200);
            },
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => function (Request $request) use ($profiles) {
                $refreshToken = substr($request->header('Authorization')[0], strlen('Bearer access-'));

                return Http::response(['emailAddress' => $profiles[$refreshToken], 'messagesTotal' => 42], 200);
            },
        ]);
    };

    $this->env = ($this->bindEnv)(function (GmcliEnv $env) {
        $env->set('GOOGLE_CLIENT_ID', 'client-id');
        $env->set('GOOGLE_CLIENT_SECRET', 'secret-key');
        $env->addAccount('first@gmail.com', 'token-1', ['alias@gmail.com']);
        $env->addAccount('second@work.com', 'token-2');
    });

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

it('reports every account as healthy when its token still works', function () {
    ($this->fakeGmail)(['token-1' => 'first@gmail.com', 'token-2' => 'second@work.com']);

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('Default account: first@gmail.com')
        ->expectsOutputToContain('OK    first@gmail.com (default): authenticated as first@gmail.com')
        ->expectsOutputToContain('OK    second@work.com: authenticated as second@work.com')
        ->expectsOutputToContain('2 of 2 accounts healthy.')
        ->assertSuccessful();
});

it('fails the account whose refresh token google rejects and prints the fix', function () {
    ($this->fakeGmail)(
        ['token-1' => 'first@gmail.com'],
        ['token-2' => ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']],
    );

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('OK    first@gmail.com (default): authenticated as first@gmail.com')
        ->expectsOutputToContain('FAIL  second@work.com: Token has been expired or revoked.')
        ->expectsOutputToContain('Run: gmcli accounts:add second@work.com')
        ->expectsOutputToContain('1 of 2 accounts need attention.')
        ->assertFailed();
});

it('fails an account whose token authenticates another mailbox', function () {
    ($this->fakeGmail)(['token-1' => 'first@gmail.com', 'token-2' => 'somebody@else.com']);

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('FAIL  second@work.com: The stored token authenticates somebody@else.com, not second@work.com.')
        ->expectsOutputToContain('Run: gmcli accounts:add second@work.com')
        ->assertFailed();
});

it('accepts a profile that answers with one of the account aliases', function () {
    ($this->fakeGmail)(['token-1' => 'ALIAS@gmail.com', 'token-2' => 'second@work.com']);

    $this->artisan('accounts:doctor')->assertSuccessful();
});

it('reports a rate limited account as an error rather than a dead token', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
            'error' => [
                'message' => 'User-rate limit exceeded.',
                'errors' => [['message' => 'User-rate limit exceeded.', 'reason' => 'userRateLimitExceeded']],
            ],
        ], 403),
    ]);

    $this->artisan('accounts:doctor', ['--json' => true])
        ->expectsOutputToContain('"status":"error","profile_email":null,"error":"Gmail API error: User-rate limit exceeded.","fix":null')
        ->assertFailed();

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('FAIL  first@gmail.com (default): Gmail API error: User-rate limit exceeded.')
        ->doesntExpectOutputToContain('Run: gmcli accounts:add first@gmail.com')
        ->assertFailed();
});

it('reports a network failure without offering a re-auth fix', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('FAIL  first@gmail.com (default): HTTP request failed: Could not resolve host')
        ->doesntExpectOutputToContain('Run: gmcli accounts:add first@gmail.com')
        ->assertFailed();
});

it('reports health as json', function () {
    ($this->fakeGmail)(['token-1' => 'first@gmail.com', 'token-2' => 'second@work.com']);

    $this->artisan('accounts:doctor', ['--json' => true])
        ->expectsOutput('{"data":{"healthy":true,"env_file":"'.$this->envPath.'","permissions":"0600","secure_permissions":true,"credentials":true,"default_account":"first@gmail.com","accounts":['
            .'{"email":"first@gmail.com","aliases":["alias@gmail.com"],"default":true,"status":"ok","profile_email":"first@gmail.com","error":null,"fix":null},'
            .'{"email":"second@work.com","aliases":[],"default":false,"status":"ok","profile_email":"second@work.com","error":null,"fix":null}'
            .']}}')
        ->assertSuccessful();
});

it('reports a failing account as json and exits non zero', function () {
    ($this->fakeGmail)(
        ['token-1' => 'first@gmail.com'],
        ['token-2' => ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']],
    );

    $this->artisan('accounts:doctor', ['--json' => true])
        ->expectsOutputToContain('"healthy":false')
        ->assertFailed();

    $this->artisan('accounts:doctor', ['--json' => true])
        ->expectsOutputToContain('"status":"auth_failed","profile_email":null,"error":"Token has been expired or revoked.","fix":"gmcli accounts:add second@work.com"')
        ->assertFailed();
});

it('tells an empty install to add an account', function () {
    ($this->bindEnv)(function (GmcliEnv $env) {
        $env->set('GOOGLE_CLIENT_ID', 'client-id');
        $env->set('GOOGLE_CLIENT_SECRET', 'secret-key');
    });

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('No account configured.')
        ->expectsOutputToContain('Run: gmcli accounts:add <email>')
        ->assertSuccessful();
});

it('fails when accounts are configured without oauth credentials', function () {
    ($this->bindEnv)(function (GmcliEnv $env) {
        $env->addAccount('first@gmail.com', 'token-1');
    });

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('Credentials: missing')
        ->expectsOutputToContain('Run: gmcli accounts:credentials <file.json>')
        ->assertFailed();
});

it('warns about an env file readable beyond the owner', function () {
    ($this->fakeGmail)(['token-1' => 'first@gmail.com', 'token-2' => 'second@work.com']);
    chmod($this->envPath, 0644);

    $this->artisan('accounts:doctor')
        ->expectsOutputToContain('.env (0644)')
        ->expectsOutputToContain('has insecure permissions (0644)')
        ->assertSuccessful();
});
