<?php

namespace App\Commands\Accounts;

use App\Services\Analytics;
use App\Services\GmcliEnv;
use LaravelZero\Framework\Commands\Command;

/**
 * Removes a configured Gmail account.
 */
class RemoveCommand extends Command
{
    protected $signature = 'accounts:remove {email? : Email address to remove}';

    protected $description = 'Remove a Gmail account';

    public function handle(GmcliEnv $env, Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = $this->argument('email');

        if (empty($email)) {
            $this->error('Missing email address.');
            $this->line('');
            $this->line('Usage: gmcli accounts:remove <email>');

            $analytics->track('accounts:remove', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $env->hasAccount()) {
            $this->error('No account configured.');

            $analytics->track('accounts:remove', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        $existingEmail = $env->getEmail();

        if (! $env->matchesEmail($email)) {
            $this->error("Account not found: {$email}");
            $this->line("Configured account: {$existingEmail}");

            $analytics->track('accounts:remove', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        $env->remove('GMAIL_ADDRESS');
        $env->remove('GMAIL_REFRESH_TOKEN');
        $env->remove('GMAIL_ADDRESS_ALIASES');
        $env->save();

        $this->info("Account removed: {$existingEmail}");

        $analytics->track('accounts:remove', self::SUCCESS, ['success' => true], $startTime);

        return self::SUCCESS;
    }
}
