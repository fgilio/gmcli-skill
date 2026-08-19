<?php

use App\Services\GmcliEnv;
use App\Services\GmcliPaths;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/gmcli-test-'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->paths = new GmcliPaths($this->tempDir);
    $this->env = new GmcliEnv($this->paths);
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

describe('parsing', function () {
    it('parses simple key=value pairs', function () {
        file_put_contents($this->paths->envFile(), "FOO=bar\nBAZ=qux\n");

        $env = new GmcliEnv($this->paths);

        expect($env->get('FOO'))->toBe('bar');
        expect($env->get('BAZ'))->toBe('qux');
    });

    it('parses quoted values', function () {
        file_put_contents($this->paths->envFile(), "FOO=\"bar baz\"\nSINGLE='quoted'\n");

        $env = new GmcliEnv($this->paths);

        expect($env->get('FOO'))->toBe('bar baz');
        expect($env->get('SINGLE'))->toBe('quoted');
    });

    it('skips comments and empty lines', function () {
        file_put_contents($this->paths->envFile(), "# comment\n\nFOO=bar\n# another\nBAZ=qux\n");

        $env = new GmcliEnv($this->paths);

        expect($env->get('FOO'))->toBe('bar');
        expect($env->get('BAZ'))->toBe('qux');
        expect($env->all())->toHaveCount(2);
    });

    it('handles values with equals signs', function () {
        file_put_contents($this->paths->envFile(), "URL=https://example.com?foo=bar&baz=qux\n");

        $env = new GmcliEnv($this->paths);

        expect($env->get('URL'))->toBe('https://example.com?foo=bar&baz=qux');
    });
});

describe('writing', function () {
    it('saves values with atomic write', function () {
        $this->env->set('FOO', 'bar');
        $this->env->set('BAZ', 'qux');
        $this->env->save();

        $content = file_get_contents($this->paths->envFile());

        expect($content)->toContain('FOO=bar');
        expect($content)->toContain('BAZ=qux');
    });

    it('sets secure permissions on file', function () {
        $this->env->set('FOO', 'bar');
        $this->env->save();

        $perms = fileperms($this->paths->envFile()) & 0777;

        expect($perms)->toBe(0600);
    });

    it('quotes values with special characters', function () {
        $this->env->set('SPACES', 'foo bar');
        $this->env->set('HASH', 'foo#bar');
        $this->env->save();

        $content = file_get_contents($this->paths->envFile());

        expect($content)->toContain('SPACES="foo bar"');
        expect($content)->toContain('HASH="foo#bar"');
    });

    it('writes credentials first, then one group per account', function () {
        $this->env->addAccount('test@gmail.com', 'token');
        $this->env->set('GOOGLE_CLIENT_ID', 'client123');
        $this->env->set('GOOGLE_CLIENT_SECRET', 'secret');
        $this->env->save();

        $content = file_get_contents($this->paths->envFile());
        $lines = array_values(array_filter(explode("\n", trim($content))));

        expect($lines[0])->toStartWith('GOOGLE_CLIENT_ID=');
        expect($lines[1])->toStartWith('GOOGLE_CLIENT_SECRET=');
        expect($lines[2])->toBe('GMAIL_DEFAULT_ACCOUNT=test@gmail.com');
        expect($lines[3])->toBe('GMAIL_ACCOUNT_TEST_GMAIL_COM_ADDRESS=test@gmail.com');
        expect($lines[4])->toBe('GMAIL_ACCOUNT_TEST_GMAIL_COM_REFRESH_TOKEN=token');
    });

    it('keeps keys it does not know about', function () {
        $this->env->set('GOOGLE_CLIENT_ID', 'client123');
        $this->env->set('SOMETHING_NEW', 'value');
        $this->env->save();
        $this->env->reload();

        expect($this->env->get('SOMETHING_NEW'))->toBe('value');
    });
});

describe('permissions', function () {
    it('detects insecure file permissions', function () {
        file_put_contents($this->paths->envFile(), "FOO=bar\n");
        chmod($this->paths->envFile(), 0644);

        $env = new GmcliEnv($this->paths);

        expect($env->hasSecurePermissions())->toBeFalse();
        expect($env->getPermissionWarning())->toContain('insecure permissions');
    });

    it('accepts secure permissions', function () {
        file_put_contents($this->paths->envFile(), "FOO=bar\n");
        chmod($this->paths->envFile(), 0600);

        $env = new GmcliEnv($this->paths);

        expect($env->hasSecurePermissions())->toBeTrue();
        expect($env->getPermissionWarning())->toBeNull();
    });
});

describe('alias matching', function () {
    it('matches primary email case-insensitively', function () {
        $this->env->addAccount('Test@Gmail.com', 'token');
        $this->env->save();
        $this->env->reload();

        expect($this->env->matchesEmail('test@gmail.com'))->toBeTrue();
        expect($this->env->matchesEmail('TEST@GMAIL.COM'))->toBeTrue();
        expect($this->env->matchesEmail('Test@Gmail.com'))->toBeTrue();
    });

    it('matches aliases case-insensitively', function () {
        $this->env->addAccount('primary@gmail.com', 'token', ['alias1@gmail.com', 'Alias2@Gmail.com']);
        $this->env->save();
        $this->env->reload();

        expect($this->env->matchesEmail('alias1@gmail.com'))->toBeTrue();
        expect($this->env->matchesEmail('ALIAS2@GMAIL.COM'))->toBeTrue();
    });

    it('returns false for non-matching emails', function () {
        $this->env->addAccount('test@gmail.com', 'token');
        $this->env->save();
        $this->env->reload();

        expect($this->env->matchesEmail('other@gmail.com'))->toBeFalse();
    });

    it('returns false for an address on another account', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@gmail.com', 'token-2');
        $this->env->save();
        $this->env->reload();

        expect($this->env->matchesEmail('second@gmail.com'))->toBeFalse();
        expect($this->env->accountFor('second@gmail.com'))->not->toBeNull();
    });

    it('parses aliases from CSV', function () {
        $this->env->addAccount('primary@x.com', 'token', ['a@x.com', 'b@x.com', 'c@x.com']);
        $this->env->save();
        $this->env->reload();

        expect($this->env->getAliases())->toBe(['a@x.com', 'b@x.com', 'c@x.com']);
    });
});

describe('credentials and account checks', function () {
    it('detects when credentials are configured', function () {
        expect($this->env->hasCredentials())->toBeFalse();

        $this->env->set('GOOGLE_CLIENT_ID', 'id');
        $this->env->set('GOOGLE_CLIENT_SECRET', 'secret');

        expect($this->env->hasCredentials())->toBeTrue();
    });

    it('detects when an account is configured', function () {
        expect($this->env->hasAccount())->toBeFalse();

        $this->env->addAccount('test@gmail.com', 'token');

        expect($this->env->hasAccount())->toBeTrue();
    });

    it('ignores an account that has no refresh token', function () {
        file_put_contents(
            $this->paths->envFile(),
            "GMAIL_ACCOUNT_TEST_GMAIL_COM_ADDRESS=test@gmail.com\n"
        );

        $env = new GmcliEnv($this->paths);

        expect($env->hasAccount())->toBeFalse();
        expect($env->accounts())->toBe([]);
    });
});

describe('accounts', function () {
    it('stores each account under its own keys', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2', ['alias@work.com']);
        $this->env->save();

        $content = file_get_contents($this->paths->envFile());

        expect($content)->toContain('GMAIL_ACCOUNT_FIRST_GMAIL_COM_ADDRESS=first@gmail.com');
        expect($content)->toContain('GMAIL_ACCOUNT_FIRST_GMAIL_COM_REFRESH_TOKEN=token-1');
        expect($content)->toContain('GMAIL_ACCOUNT_SECOND_WORK_COM_ADDRESS=second@work.com');
        expect($content)->toContain('GMAIL_ACCOUNT_SECOND_WORK_COM_ALIASES=alias@work.com');
    });

    it('reads every account back after a reload', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2', ['alias@work.com']);
        $this->env->save();
        $this->env->reload();

        expect($this->env->accountEmails())->toBe(['first@gmail.com', 'second@work.com']);
        expect($this->env->accountFor('second@work.com')['refresh_token'])->toBe('token-2');
        expect($this->env->accountFor('alias@work.com')['email'])->toBe('second@work.com');
        expect($this->env->accountFor('ALIAS@WORK.COM')['email'])->toBe('second@work.com');
        expect($this->env->accountFor('nobody@work.com'))->toBeNull();
    });

    it('makes the first account the default', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2');

        expect($this->env->getEmail())->toBe('first@gmail.com');
        expect($this->env->defaultAccount()['email'])->toBe('first@gmail.com');
    });

    it('makes an account default on request', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2', makeDefault: true);
        $this->env->save();
        $this->env->reload();

        expect($this->env->getEmail())->toBe('second@work.com');
    });

    it('switches the default account', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2', ['alias@work.com']);

        expect($this->env->setDefaultAccount('alias@work.com')['email'])->toBe('second@work.com');
        expect($this->env->setDefaultAccount('nobody@work.com'))->toBeNull();

        $this->env->save();
        $this->env->reload();

        expect($this->env->getEmail())->toBe('second@work.com');
    });

    it('replaces the refresh token when the same address is added again', function () {
        $this->env->addAccount('first@gmail.com', 'token-1', ['alias@gmail.com']);
        $this->env->addAccount('first@gmail.com', 'token-2');
        $this->env->save();
        $this->env->reload();

        expect($this->env->accounts())->toHaveCount(1);
        expect($this->env->accountFor('first@gmail.com')['refresh_token'])->toBe('token-2');
        expect($this->env->getAliases())->toBe(['alias@gmail.com']);
    });

    it('removes only the keys of the requested account', function () {
        $this->env->set('GOOGLE_CLIENT_ID', 'id');
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2');

        expect($this->env->removeAccount('second@work.com')['email'])->toBe('second@work.com');

        $this->env->save();
        $this->env->reload();

        expect($this->env->accountEmails())->toBe(['first@gmail.com']);
        expect($this->env->get('GOOGLE_CLIENT_ID'))->toBe('id');
        expect(file_get_contents($this->paths->envFile()))->not->toContain('SECOND_WORK_COM');
    });

    it('reassigns the default when the default account is removed', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->addAccount('second@work.com', 'token-2');
        $this->env->removeAccount('first@gmail.com');
        $this->env->save();
        $this->env->reload();

        expect($this->env->getEmail())->toBe('second@work.com');
    });

    it('clears the default when the last account is removed', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->removeAccount('first@gmail.com');
        $this->env->save();
        $this->env->reload();

        expect($this->env->hasAccount())->toBeFalse();
        expect($this->env->getEmail())->toBeNull();
        expect($this->env->get('GMAIL_DEFAULT_ACCOUNT'))->toBeNull();
    });

    it('returns null when removing an unknown account', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');

        expect($this->env->removeAccount('other@gmail.com'))->toBeNull();
        expect($this->env->accounts())->toHaveCount(1);
    });

    it('gives distinct addresses that sanitize alike distinct slugs', function () {
        $this->env->addAccount('a.b@gmail.com', 'token-1');
        $this->env->addAccount('a-b@gmail.com', 'token-2');

        expect($this->env->accountFor('a.b@gmail.com')['refresh_token'])->toBe('token-1');
        expect($this->env->accountFor('a-b@gmail.com')['refresh_token'])->toBe('token-2');
    });

    it('falls back to the first account when the default names nobody', function () {
        $this->env->addAccount('first@gmail.com', 'token-1');
        $this->env->set('GMAIL_DEFAULT_ACCOUNT', 'gone@gmail.com');

        expect($this->env->getEmail())->toBe('first@gmail.com');
    });
});

describe('migration from single-account keys', function () {
    it('surfaces the legacy account and keeps credentials', function () {
        file_put_contents($this->paths->envFile(), implode("\n", [
            'GOOGLE_CLIENT_ID=id',
            'GOOGLE_CLIENT_SECRET=secret',
            'GMAIL_ADDRESS=legacy@gmail.com',
            'GMAIL_REFRESH_TOKEN=legacy-token',
            'GMAIL_ADDRESS_ALIASES=alias@gmail.com',
        ])."\n");

        $env = new GmcliEnv($this->paths);

        expect($env->accountEmails())->toBe(['legacy@gmail.com']);
        expect($env->getEmail())->toBe('legacy@gmail.com');
        expect($env->getAliases())->toBe(['alias@gmail.com']);
        expect($env->matchesEmail('ALIAS@GMAIL.COM'))->toBeTrue();
        expect($env->hasCredentials())->toBeTrue();
    });

    it('rewrites the legacy keys on the next save', function () {
        file_put_contents($this->paths->envFile(), implode("\n", [
            'GOOGLE_CLIENT_ID=id',
            'GMAIL_ADDRESS=legacy@gmail.com',
            'GMAIL_REFRESH_TOKEN=legacy-token',
        ])."\n");

        $env = new GmcliEnv($this->paths);
        $env->save();

        $content = file_get_contents($this->paths->envFile());

        expect($content)->toContain('GMAIL_ACCOUNT_LEGACY_GMAIL_COM_ADDRESS=legacy@gmail.com');
        expect($content)->toContain('GMAIL_ACCOUNT_LEGACY_GMAIL_COM_REFRESH_TOKEN=legacy-token');
        expect($content)->toContain('GMAIL_DEFAULT_ACCOUNT=legacy@gmail.com');
        expect($content)->toContain('GOOGLE_CLIENT_ID=id');
        expect($content)->not->toContain("\nGMAIL_ADDRESS=");
        expect($content)->not->toContain("\nGMAIL_REFRESH_TOKEN=");
    });

    it('adds a second account next to a migrated one', function () {
        file_put_contents($this->paths->envFile(), "GMAIL_ADDRESS=legacy@gmail.com\nGMAIL_REFRESH_TOKEN=legacy-token\n");

        $env = new GmcliEnv($this->paths);
        $env->addAccount('second@work.com', 'token-2');
        $env->save();
        $env->reload();

        expect($env->accountEmails())->toBe(['legacy@gmail.com', 'second@work.com']);
        expect($env->getEmail())->toBe('legacy@gmail.com');
    });

    it('drops legacy keys that duplicate a stored account', function () {
        file_put_contents($this->paths->envFile(), implode("\n", [
            'GMAIL_DEFAULT_ACCOUNT=dup@gmail.com',
            'GMAIL_ACCOUNT_DUP_GMAIL_COM_ADDRESS=dup@gmail.com',
            'GMAIL_ACCOUNT_DUP_GMAIL_COM_REFRESH_TOKEN=new-token',
            'GMAIL_ADDRESS=dup@gmail.com',
            'GMAIL_REFRESH_TOKEN=old-token',
        ])."\n");

        $env = new GmcliEnv($this->paths);

        expect($env->accounts())->toHaveCount(1);
        expect($env->accountFor('dup@gmail.com')['refresh_token'])->toBe('new-token');
    });
});
