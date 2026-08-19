<?php

namespace App\Commands\Gmail;

use App\Exceptions\GmailAuthException;
use App\Services\GmailClient;
use App\Services\GmailClientFactory;
use App\Services\GmailLogger;
use App\Services\GmcliEnv;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Base class for Gmail commands.
 *
 * Provides common functionality:
 * - Account resolution (from --account option or default)
 * - Gmail client creation with logging
 * - Verbose/debug output support
 * - JSON output + exception handling via the AgentCommand trait
 */
abstract class BaseGmailCommand extends Command
{
    use AgentCommand;

    protected GmcliEnv $env;

    protected GmailClient $gmail;

    protected GmailLogger $logger;

    protected string $account;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('account', 'a', InputOption::VALUE_REQUIRED, 'Account email or alias (uses the default account if not specified)');
    }

    /**
     * Resolves account from --account option or default.
     */
    protected function resolveAccount(): ?string
    {
        $this->env = app(GmcliEnv::class);

        return $this->option('account') ?: $this->env->getEmail();
    }

    /**
     * Initializes Gmail client for the resolved account.
     *
     * Returns null on success, or a failure exit code (with a
     * user-facing message already emitted) when auth setup fails.
     */
    protected function initGmail(?string $email = null): ?int
    {
        $this->env = app(GmcliEnv::class);
        $this->logger = new GmailLogger(
            $this->output,
            $this->output->isVerbose(),
            $this->output->isVeryVerbose()
        );

        $email = $email ?? $this->resolveAccount();

        if (! $email) {
            return $this->failWith(
                'No account specified and no default configured. '
                .'Either pass --account you@gmail.com or run: gmcli accounts:add you@gmail.com'
            );
        }

        if (! $this->env->hasCredentials()) {
            return $this->failWith('No credentials configured. Run: gmcli accounts:credentials <file.json>');
        }

        if (! $this->env->hasAccount()) {
            return $this->failWith('No account configured. Run: gmcli accounts:add <email>');
        }

        $account = $this->env->accountFor($email);

        if (! $account) {
            $configured = implode(', ', $this->env->accountEmails());

            return $this->failWith(
                "Account not configured: {$email}. Configured accounts: {$configured}. "
                ."Add it with: gmcli accounts:add {$email}"
            );
        }

        $this->account = $account['email'];

        $this->gmail = app(GmailClientFactory::class)->make(
            $this->env->get('GOOGLE_CLIENT_ID'),
            $this->env->get('GOOGLE_CLIENT_SECRET'),
            $account['refresh_token'],
            $this->logger
        );

        $warning = $this->env->getPermissionWarning();
        if ($warning) {
            $this->warn($warning);
            $this->newLine();
        }

        return null;
    }

    /**
     * Rewrite Gmail credential errors into an actionable fix.
     *
     * @return array{message: string, meta: array<string, mixed>}|null
     */
    protected function extractExceptionDetails(Throwable $e): ?array
    {
        if (! $e instanceof RuntimeException) {
            return null;
        }

        if ($this->isScopeError($e->getMessage())) {
            $account = $this->account ?? '<email>';

            return $this->details([
                'Filter management requires renewed Gmail consent.',
                'Run: gmcli accounts:remove '.$account,
                'Then: gmcli accounts:add '.$account,
            ]);
        }

        if ($e instanceof GmailAuthException) {
            $account = $this->account ?? '<email>';

            return $this->details([
                "Gmail rejected the credentials for {$account}.",
                'Reason: '.$this->oneLine($e->getMessage()),
                "Re-authenticate: gmcli accounts:add {$account}",
                'Check every account: gmcli accounts:doctor',
            ]);
        }

        return null;
    }

    private function isScopeError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'insufficient authentication scopes')
            || str_contains($normalized, 'insufficientpermissions')
            || str_contains($normalized, 'insufficient permissions');
    }

    /**
     * JSON consumers get the whole failure on one line, humans get one hint per line.
     *
     * @param  list<string>  $lines
     * @return array{message: string, meta: array<string, mixed>}
     */
    private function details(array $lines): array
    {
        return [
            'message' => implode($this->wantsJson() ? ' ' : "\n", $lines),
            'meta' => [],
        ];
    }

    private function oneLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }
}
