<?php

namespace App\Commands\Accounts;

use App\Services\Analytics;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use LaravelZero\Framework\Commands\Command;

/**
 * Sets OAuth credentials from a Google Cloud JSON file.
 *
 * Supports both 'installed' (desktop) and 'web' credential types.
 */
class CredentialsCommand extends Command
{
    protected $signature = 'accounts:credentials {file? : Path to credentials JSON file}';

    protected $description = 'Set OAuth credentials from Google Cloud JSON';

    public function handle(GmcliPaths $paths, GmcliEnv $env, Analytics $analytics): int
    {
        $startTime = microtime(true);
        $file = $this->argument('file');

        if (empty($file)) {
            $this->error('Missing credentials file path.');
            $this->line('');
            $this->line('Usage: gmcli accounts:credentials <file.json>');

            $analytics->track('accounts:credentials', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            $analytics->track('accounts:credentials', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        $content = file_get_contents($file);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON file.');

            $analytics->track('accounts:credentials', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        // Support both 'installed' and 'web' credential types
        $creds = $json['installed'] ?? $json['web'] ?? null;

        if (! $creds) {
            $this->error('Invalid credentials file format.');
            $this->line('Expected "installed" or "web" OAuth client credentials from Google Cloud Console.');

            $analytics->track('accounts:credentials', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        $clientId = $creds['client_id'] ?? null;
        $clientSecret = $creds['client_secret'] ?? null;

        if (! $clientId || ! $clientSecret) {
            $this->error('Missing client_id or client_secret in credentials file.');

            $analytics->track('accounts:credentials', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        $env->set('GOOGLE_CLIENT_ID', $clientId);
        $env->set('GOOGLE_CLIENT_SECRET', $clientSecret);
        $env->save();

        $this->info('Credentials saved.');
        $this->line("Client ID: {$clientId}");

        $analytics->track('accounts:credentials', self::SUCCESS, ['success' => true], $startTime);

        return self::SUCCESS;
    }
}
