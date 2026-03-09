<?php

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
        $paths = new App\Services\GmcliPaths($this->tempDir);
        $env = new App\Services\GmcliEnv($paths);

        $env->set('GOOGLE_CLIENT_ID', 'test_client');
        $env->set('GOOGLE_CLIENT_SECRET', 'test_secret');
        $env->set('GMAIL_ADDRESS', 'test@gmail.com');
        $env->set('GMAIL_REFRESH_TOKEN', 'test_token');
        $env->set('GMAIL_ADDRESS_ALIASES', 'alias@gmail.com');
        $env->save();

        // Simulate remove
        $env->remove('GMAIL_ADDRESS');
        $env->remove('GMAIL_REFRESH_TOKEN');
        $env->remove('GMAIL_ADDRESS_ALIASES');
        $env->save();

        $env->reload();

        expect($env->hasCredentials())->toBeTrue();
        expect($env->hasAccount())->toBeFalse();
        expect($env->get('GMAIL_ADDRESS'))->toBeNull();
        expect($env->get('GMAIL_REFRESH_TOKEN'))->toBeNull();
        expect($env->get('GMAIL_ADDRESS_ALIASES'))->toBeNull();
    });

    it('keeps credentials after removing account', function () {
        $paths = new App\Services\GmcliPaths($this->tempDir);
        $env = new App\Services\GmcliEnv($paths);

        $env->set('GOOGLE_CLIENT_ID', 'keep_this');
        $env->set('GOOGLE_CLIENT_SECRET', 'keep_secret');
        $env->set('GMAIL_ADDRESS', 'test@gmail.com');
        $env->set('GMAIL_REFRESH_TOKEN', 'test_token');
        $env->save();

        $env->remove('GMAIL_ADDRESS');
        $env->remove('GMAIL_REFRESH_TOKEN');
        $env->save();
        $env->reload();

        expect($env->get('GOOGLE_CLIENT_ID'))->toBe('keep_this');
        expect($env->get('GOOGLE_CLIENT_SECRET'))->toBe('keep_secret');
    });
});
