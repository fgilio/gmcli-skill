<?php

namespace App\Commands\Accounts;

use App\Services\GmcliEnv;
use App\Services\OAuthService;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

/**
 * Adds a Gmail account via OAuth flow.
 *
 * Supports two modes:
 * - Auto (default): Opens browser, starts local callback server
 * - Manual (--manual): User copies/pastes authorization URL
 */
class AddCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:add
        {email? : Email address to add}
        {--manual : Use browserless OAuth flow (manual paste)}';

    protected $description = 'Add a Gmail account via OAuth';

    public function handle(GmcliEnv $env): int
    {
        if ($this->wantsJson()) {
            return $this->failWith('accounts:add is interactive and does not support --json; run without --json');
        }

        $email = $this->argument('email');

        if (empty($email)) {
            return $this->failWith('Missing email address. Usage: gmcli accounts:add <email> [--manual]');
        }

        if (! $env->hasCredentials()) {
            return $this->failWith('No credentials configured. Run: gmcli accounts:credentials <file.json> first.');
        }

        if ($env->hasAccount()) {
            $existingEmail = $env->getEmail();

            return $this->failWith("Account already configured: {$existingEmail}. Remove it first: gmcli accounts:remove {$existingEmail}");
        }

        $oauth = new OAuthService(
            $env->get('GOOGLE_CLIENT_ID'),
            $env->get('GOOGLE_CLIENT_SECRET'),
            120
        );

        $tokens = $this->option('manual')
            ? $this->runManualFlow($oauth)
            : $this->runAutoFlow($oauth);

        $env->set('GMAIL_ADDRESS', $email);
        $env->set('GMAIL_REFRESH_TOKEN', $tokens['refresh_token']);
        $env->save();

        $this->info("Account added: {$email}");

        return self::SUCCESS;
    }

    private function runAutoFlow(OAuthService $oauth): array
    {
        $this->line('Opening browser for authentication...');
        $this->line('');

        return $oauth->runAutoFlow(function (string $url) {
            $this->line('If the browser does not open, visit:');
            $this->line($url);
            $this->line('');

            $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
            exec("{$command} ".escapeshellarg($url).' 2>/dev/null &');
        });
    }

    private function runManualFlow(OAuthService $oauth): array
    {
        return $oauth->runManualFlow(
            function (string $url) {
                $this->line('Open this URL in your browser:');
                $this->line('');
                $this->line($url);
                $this->line('');
            },
            function () {
                $this->line('After authorizing, your browser will show an error page.');
                $this->line('This is expected. Copy the URL from the address bar.');
                $this->line('');

                return $this->ask('Paste the redirect URL here');
            }
        );
    }
}
