<?php

namespace App\Commands\Accounts;

use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

/**
 * Removes a configured Gmail account.
 */
class RemoveCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:remove {email? : Email address to remove}';

    protected $description = 'Remove a Gmail account';

    public function handle(GmcliEnv $env): int
    {
        $email = $this->argument('email');

        if (empty($email)) {
            return $this->failWith('Missing email address. Usage: gmcli accounts:remove <email>');
        }

        if (! $env->hasAccount()) {
            return $this->failWith('No account configured.');
        }

        $existingEmail = $env->getEmail();

        if (! $env->matchesEmail($email)) {
            return $this->failWith("Account not found: {$email}. Configured account: {$existingEmail}");
        }

        $env->remove('GMAIL_ADDRESS');
        $env->remove('GMAIL_REFRESH_TOKEN');
        $env->remove('GMAIL_ADDRESS_ALIASES');
        $env->save();

        if ($this->wantsJson()) {
            return $this->outputJson(['email' => $existingEmail]);
        }

        $this->info("Account removed: {$existingEmail}");

        return self::SUCCESS;
    }
}
