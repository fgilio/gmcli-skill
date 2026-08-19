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

    protected $signature = 'accounts:remove {email? : Email address or alias of the account to remove}';

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

        $configured = implode(', ', $env->accountEmails());
        $removed = $env->removeAccount($email);

        if (! $removed) {
            return $this->failWith("Account not found: {$email}. Configured accounts: {$configured}");
        }

        $env->save();

        $newDefault = $env->getEmail();

        if ($this->wantsJson()) {
            return $this->outputJson([
                'email' => $removed['email'],
                'default' => $newDefault,
            ]);
        }

        $this->info("Account removed: {$removed['email']}");

        if ($removed['default'] && $newDefault) {
            $this->line("Default account is now: {$newDefault}");
        }

        return self::SUCCESS;
    }
}
