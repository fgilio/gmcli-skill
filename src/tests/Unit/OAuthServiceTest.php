<?php

use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use App\Services\OAuthService;

describe('code extraction from HTTP request', function () {
    it('extracts code from valid GET request', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $request = "GET /?code=4/0test_auth_code&scope=email HTTP/1.1\r\nHost: 127.0.0.1:12345\r\n\r\n";

        $code = $oauth->extractCodeFromHttpRequest($request);

        expect($code)->toBe('4/0test_auth_code');
    });

    it('throws on missing code in request', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $request = "GET /?foo=bar HTTP/1.1\r\nHost: 127.0.0.1:12345\r\n\r\n";

        expect(fn () => $oauth->extractCodeFromHttpRequest($request))
            ->toThrow(RuntimeException::class, 'No authorization code');
    });

    it('throws on OAuth error in request', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $request = "GET /?error=access_denied&error_description=User%20denied%20access HTTP/1.1\r\n\r\n";

        expect(fn () => $oauth->extractCodeFromHttpRequest($request))
            ->toThrow(RuntimeException::class, 'User denied access');
    });

    it('throws on invalid HTTP format', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        expect(fn () => $oauth->extractCodeFromHttpRequest('invalid data'))
            ->toThrow(RuntimeException::class, 'Invalid HTTP request');
    });
});

describe('code extraction from URL', function () {
    it('extracts code from redirect URL', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $url = 'http://localhost:1/?code=test_code_123&scope=email';

        $code = $oauth->extractCodeFromUrl($url);

        expect($code)->toBe('test_code_123');
    });

    it('handles URL-encoded code', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $url = 'http://localhost:1/?code=4%2F0test_code';

        $code = $oauth->extractCodeFromUrl($url);

        expect($code)->toBe('4/0test_code');
    });

    it('throws on OAuth error', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        $url = 'http://localhost:1/?error=access_denied&error_description=Permission+denied';

        expect(fn () => $oauth->extractCodeFromUrl($url))
            ->toThrow(RuntimeException::class, 'Permission denied');
    });

    it('throws on missing query string', function () {
        $oauth = new OAuthService('client_id', 'client_secret');

        expect(fn () => $oauth->extractCodeFromUrl('http://localhost:1/'))
            ->toThrow(RuntimeException::class, 'No query parameters');
    });
});

describe('auth URL building', function () {
    it('builds correct auth URL', function () {
        $oauth = new OAuthService('my_client_id', 'my_secret');

        $url = $oauth->buildAuthUrl('http://127.0.0.1:8080');

        expect($url)->toContain('accounts.google.com/o/oauth2');
        expect($url)->toContain('client_id=my_client_id');
        expect($url)->toContain('redirect_uri='.urlencode('http://127.0.0.1:8080'));
        expect($url)->toContain(urlencode('https://www.googleapis.com/auth/gmail.modify'));
        expect($url)->toContain(urlencode('https://www.googleapis.com/auth/gmail.settings.basic'));
        expect($url)->toContain('access_type=offline');
        expect($url)->toContain('prompt=consent');
    });
});

describe('accounts remove semantics', function () {
    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/gmcli-test-'.uniqid();
        mkdir($this->tempDir, 0700, true);
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

    it('removes account credentials on remove command', function () {
        $paths = new GmcliPaths($this->tempDir);
        $env = new GmcliEnv($paths);

        $env->set('GOOGLE_CLIENT_ID', 'test_client');
        $env->set('GOOGLE_CLIENT_SECRET', 'test_secret');
        $env->addAccount('test@gmail.com', 'test_token', ['alias@gmail.com']);
        $env->save();

        $env->removeAccount('test@gmail.com');
        $env->save();
        $env->reload();

        expect($env->hasCredentials())->toBeTrue();
        expect($env->hasAccount())->toBeFalse();
        expect($env->accounts())->toBe([]);
        expect(file_get_contents($paths->envFile()))->not->toContain('TEST_GMAIL_COM');
    });

    it('keeps credentials after removing account', function () {
        $paths = new GmcliPaths($this->tempDir);
        $env = new GmcliEnv($paths);

        $env->set('GOOGLE_CLIENT_ID', 'keep_this');
        $env->set('GOOGLE_CLIENT_SECRET', 'keep_secret');
        $env->addAccount('test@gmail.com', 'test_token');
        $env->save();

        $env->removeAccount('test@gmail.com');
        $env->save();
        $env->reload();

        expect($env->get('GOOGLE_CLIENT_ID'))->toBe('keep_this');
        expect($env->get('GOOGLE_CLIENT_SECRET'))->toBe('keep_secret');
    });

    it('keeps the other account when one of two is removed', function () {
        $paths = new GmcliPaths($this->tempDir);
        $env = new GmcliEnv($paths);

        $env->set('GOOGLE_CLIENT_ID', 'test_client');
        $env->set('GOOGLE_CLIENT_SECRET', 'test_secret');
        $env->addAccount('first@gmail.com', 'token_1');
        $env->addAccount('second@work.com', 'token_2');
        $env->save();

        $env->removeAccount('first@gmail.com');
        $env->save();
        $env->reload();

        expect($env->accountEmails())->toBe(['second@work.com']);
        expect($env->accountFor('second@work.com')['refresh_token'])->toBe('token_2');
        expect($env->getEmail())->toBe('second@work.com');
    });
});
