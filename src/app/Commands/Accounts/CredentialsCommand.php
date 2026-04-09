<?php

namespace App\Commands\Accounts;

use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

/**
 * Sets OAuth credentials from a Google Cloud JSON file.
 *
 * Supports both 'installed' (desktop) and 'web' credential types.
 */
class CredentialsCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:credentials {file? : Path to credentials JSON file}';

    protected $description = 'Set OAuth credentials from Google Cloud JSON';

    public function handle(GmcliEnv $env): int
    {
        $file = $this->argument('file');

        if (empty($file)) {
            return $this->failWith('Missing credentials file path. Usage: gmcli accounts:credentials <file.json>');
        }

        if (! file_exists($file)) {
            return $this->failWith("File not found: {$file}");
        }

        $content = file_get_contents($file);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->failWith('Invalid JSON file.');
        }

        $creds = $json['installed'] ?? $json['web'] ?? null;

        if (! $creds) {
            return $this->failWith('Invalid credentials file format. Expected "installed" or "web" OAuth client credentials from Google Cloud Console.');
        }

        $clientId = $creds['client_id'] ?? null;
        $clientSecret = $creds['client_secret'] ?? null;

        if (! $clientId || ! $clientSecret) {
            return $this->failWith('Missing client_id or client_secret in credentials file.');
        }

        $env->set('GOOGLE_CLIENT_ID', $clientId);
        $env->set('GOOGLE_CLIENT_SECRET', $clientSecret);
        $env->save();

        if ($this->wantsJson()) {
            return $this->outputJson(['client_id' => $clientId]);
        }

        $this->info('Credentials saved.');
        $this->line("Client ID: {$clientId}");

        return self::SUCCESS;
    }
}
