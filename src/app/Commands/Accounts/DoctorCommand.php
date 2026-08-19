<?php

namespace App\Commands\Accounts;

use App\Exceptions\GmailAuthException;
use App\Exceptions\GmailConnectionException;
use App\Services\GmailClientFactory;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

/**
 * Checks that every configured account can still reach Gmail.
 *
 * Each account gets one users.getProfile call, the cheapest
 * request that both refreshes the token and names the
 * mailbox it belongs to.
 *
 * @phpstan-type Account array{slug: string, email: string, refresh_token: string, aliases: list<string>, default: bool}
 * @phpstan-type Diagnosis array{email: string, aliases: list<string>, default: bool, status: string, profile_email: string|null, error: string|null, fix: string|null}
 */
class DoctorCommand extends Command
{
    use AgentCommand;

    protected $signature = 'accounts:doctor';

    protected $description = 'Check that every configured account still authenticates';

    public function handle(GmcliEnv $env, GmcliPaths $paths, GmailClientFactory $clients): int
    {
        $accounts = $env->accounts();
        $hasCredentials = $env->hasCredentials();

        $diagnoses = $hasCredentials
            ? array_map(fn (array $account): array => $this->diagnose($clients, $env, $account), $accounts)
            : [];

        $failures = array_values(array_filter($diagnoses, fn (array $diagnosis): bool => $diagnosis['status'] !== 'ok'));
        $healthy = $failures === [] && ($hasCredentials || $accounts === []);

        if ($this->wantsJson()) {
            $this->outputJson([
                'healthy' => $healthy,
                'env_file' => $paths->envFile(),
                'permissions' => $this->formatPermissions($env->getFilePermissions()),
                'secure_permissions' => $env->hasSecurePermissions(),
                'credentials' => $hasCredentials,
                'default_account' => $env->getEmail(),
                'accounts' => $diagnoses,
            ]);

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->renderReport($env, $paths, $accounts, $diagnoses, $hasCredentials);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs one Gmail call for an account and grades the outcome.
     *
     * @param  Account  $account
     * @return Diagnosis
     */
    private function diagnose(GmailClientFactory $clients, GmcliEnv $env, array $account): array
    {
        $client = $clients->make(
            (string) $env->get('GOOGLE_CLIENT_ID'),
            (string) $env->get('GOOGLE_CLIENT_SECRET'),
            $account['refresh_token'],
        );

        try {
            $profile = $client->get('/users/me/profile');
        } catch (GmailAuthException $e) {
            return $this->diagnosis($account, 'auth_failed', $e->getMessage(), fixable: true);
        } catch (GmailConnectionException $e) {
            return $this->diagnosis($account, 'unreachable', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->diagnosis($account, 'error', $e->getMessage());
        }

        $profileEmail = is_string($profile['emailAddress'] ?? null) ? $profile['emailAddress'] : null;

        if ($profileEmail !== null && ! $this->ownsAddress($account, $profileEmail)) {
            return $this->diagnosis(
                $account,
                'mismatch',
                "The stored token authenticates {$profileEmail}, not {$account['email']}.",
                fixable: true,
            );
        }

        return [...$this->diagnosis($account, 'ok'), 'profile_email' => $profileEmail];
    }

    /**
     * @param  Account  $account
     * @return Diagnosis
     */
    private function diagnosis(array $account, string $status, ?string $error = null, bool $fixable = false): array
    {
        return [
            'email' => $account['email'],
            'aliases' => $account['aliases'],
            'default' => $account['default'],
            'status' => $status,
            'profile_email' => null,
            'error' => $error,
            'fix' => $fixable ? "gmcli accounts:add {$account['email']}" : null,
        ];
    }

    /**
     * Checks whether an address is the account's own, primary or alias.
     *
     * @param  Account  $account
     */
    private function ownsAddress(array $account, string $email): bool
    {
        $addresses = array_map('strtolower', [$account['email'], ...$account['aliases']]);

        return in_array(strtolower(trim($email)), $addresses, true);
    }

    /**
     * @param  list<Account>  $accounts
     * @param  list<Diagnosis>  $diagnoses
     */
    private function renderReport(GmcliEnv $env, GmcliPaths $paths, array $accounts, array $diagnoses, bool $hasCredentials): void
    {
        $permissions = $env->getFilePermissions();

        $this->line('Config file: '.$paths->envFile().($permissions === null ? ' (not created yet)' : ' ('.$this->formatPermissions($permissions).')'));
        $this->line('Credentials: '.($hasCredentials ? 'configured' : 'missing'));
        $this->line('Default account: '.($env->getEmail() ?? 'none'));
        $this->newLine();

        if (! $hasCredentials) {
            $this->warn('No credentials configured.');
            $this->line('Run: gmcli accounts:credentials <file.json>');
            $this->newLine();
        }

        if ($accounts === []) {
            $this->warn('No account configured.');
            $this->line('Run: gmcli accounts:add <email>');

            return;
        }

        foreach ($diagnoses as $diagnosis) {
            $this->renderDiagnosis($diagnosis);
        }

        if ($diagnoses === []) {
            return;
        }

        $failed = count(array_filter($diagnoses, fn (array $diagnosis): bool => $diagnosis['status'] !== 'ok'));

        $this->newLine();
        $this->line($failed === 0
            ? count($diagnoses).' of '.count($diagnoses).' accounts healthy.'
            : $failed.' of '.count($diagnoses).' accounts need attention.');

        $warning = $env->getPermissionWarning();
        if ($warning) {
            $this->newLine();
            $this->warn($warning);
        }
    }

    /**
     * @param  Diagnosis  $diagnosis
     */
    private function renderDiagnosis(array $diagnosis): void
    {
        $label = $diagnosis['email'].($diagnosis['default'] ? ' (default)' : '');

        if ($diagnosis['status'] === 'ok') {
            $this->line("<info>OK</info>    {$label}: authenticated as ".($diagnosis['profile_email'] ?? $diagnosis['email']));

            return;
        }

        $this->line("<fg=red>FAIL</>  {$label}: {$diagnosis['error']}");

        if ($diagnosis['fix']) {
            $this->line("      Run: {$diagnosis['fix']}");
        }
    }

    private function formatPermissions(?int $permissions): ?string
    {
        return $permissions === null ? null : '0'.decoct($permissions);
    }
}
