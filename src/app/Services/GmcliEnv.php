<?php

namespace App\Services;

use RuntimeException;

/**
 * Manages gmcli environment configuration.
 *
 * Handles reading and writing ~/.gmcli/.env file with
 * atomic writes and secure permissions (0600).
 *
 * Accounts are stored one key group per account:
 * GMAIL_ACCOUNT_<SLUG>_ADDRESS, _REFRESH_TOKEN and _ALIASES,
 * with GMAIL_DEFAULT_ACCOUNT naming the account used when
 * no --account is given.
 *
 * @phpstan-type Account array{slug: string, email: string, refresh_token: string, aliases: list<string>, default: bool}
 */
class GmcliEnv
{
    private const REQUIRED_FILE_PERMS = 0600;

    private const DEFAULT_ACCOUNT_KEY = 'GMAIL_DEFAULT_ACCOUNT';

    private const ACCOUNT_KEY_PREFIX = 'GMAIL_ACCOUNT_';

    /** Single-account keys written by gmcli before per-account storage */
    private const LEGACY_ADDRESS_KEY = 'GMAIL_ADDRESS';

    private const LEGACY_REFRESH_TOKEN_KEY = 'GMAIL_REFRESH_TOKEN';

    private const LEGACY_ALIASES_KEY = 'GMAIL_ADDRESS_ALIASES';

    private const LEADING_KEYS = [
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        self::DEFAULT_ACCOUNT_KEY,
    ];

    private GmcliPaths $paths;

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, string> */
    private array $skillValues = [];

    private bool $loaded = false;

    public function __construct(GmcliPaths $paths)
    {
        $this->paths = $paths;
    }

    /**
     * Gets a configuration value.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $this->ensureLoaded();

        return $this->values[$key] ?? $default;
    }

    /**
     * Sets a configuration value.
     */
    public function set(string $key, string $value): self
    {
        $this->ensureLoaded();
        $this->values[$key] = $value;

        return $this;
    }

    /**
     * Removes a configuration value.
     */
    public function remove(string $key): self
    {
        $this->ensureLoaded();
        unset($this->values[$key]);

        return $this;
    }

    /**
     * Checks if a key exists.
     */
    public function has(string $key): bool
    {
        $this->ensureLoaded();

        return isset($this->values[$key]);
    }

    /**
     * Returns all configuration values.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->values;
    }

    /**
     * Checks if credentials are configured.
     */
    public function hasCredentials(): bool
    {
        return $this->has('GOOGLE_CLIENT_ID') && $this->has('GOOGLE_CLIENT_SECRET');
    }

    /**
     * Returns every configured account, ordered as stored.
     *
     * @return list<Account>
     */
    public function accounts(): array
    {
        $accounts = $this->readAccounts();
        $defaultEmail = strtolower($this->resolveDefaultEmail($accounts) ?? '');

        return array_map(
            fn (array $account): array => [...$account, 'default' => strtolower($account['email']) === $defaultEmail],
            $accounts
        );
    }

    /**
     * Returns the primary address of every configured account.
     *
     * @return list<string>
     */
    public function accountEmails(): array
    {
        return array_column($this->accounts(), 'email');
    }

    /**
     * Finds the account owning an address, matching primary and aliases.
     *
     * @return Account|null
     */
    public function accountFor(string $email): ?array
    {
        $email = strtolower(trim($email));

        foreach ($this->accounts() as $account) {
            if (strtolower($account['email']) === $email) {
                return $account;
            }

            foreach ($account['aliases'] as $alias) {
                if (strtolower($alias) === $email) {
                    return $account;
                }
            }
        }

        return null;
    }

    /**
     * Returns the account used when no account is requested.
     *
     * @return Account|null
     */
    public function defaultAccount(): ?array
    {
        foreach ($this->accounts() as $account) {
            if ($account['default']) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Stores an account, replacing any account with the same address.
     *
     * The first account added becomes the default.
     *
     * @param  list<string>|null  $aliases  null keeps the aliases already stored
     * @return Account
     */
    public function addAccount(string $email, string $refreshToken, ?array $aliases = null, bool $makeDefault = false): array
    {
        $hadAccounts = $this->readAccounts() !== [];
        $slug = $this->slugFor($email);

        $this->values[$this->accountKey($slug, 'ADDRESS')] = trim($email);
        $this->values[$this->accountKey($slug, 'REFRESH_TOKEN')] = $refreshToken;

        if ($aliases !== null) {
            $this->writeAliases($slug, $aliases);
        }

        if ($makeDefault || ! $hadAccounts) {
            $this->values[self::DEFAULT_ACCOUNT_KEY] = trim($email);
        }

        return $this->accountFor($email) ?? throw new RuntimeException("Failed to store account: {$email}");
    }

    /**
     * Removes an account and reassigns the default when needed.
     *
     * @return Account|null the removed account, or null when no account owns the address
     */
    public function removeAccount(string $email): ?array
    {
        $account = $this->accountFor($email);

        if (! $account) {
            return null;
        }

        unset(
            $this->values[$this->accountKey($account['slug'], 'ADDRESS')],
            $this->values[$this->accountKey($account['slug'], 'REFRESH_TOKEN')],
            $this->values[$this->accountKey($account['slug'], 'ALIASES')],
        );

        if ($account['default']) {
            $remaining = $this->readAccounts();

            if ($remaining === []) {
                unset($this->values[self::DEFAULT_ACCOUNT_KEY]);
            } else {
                $this->values[self::DEFAULT_ACCOUNT_KEY] = $remaining[0]['email'];
            }
        }

        return $account;
    }

    /**
     * Points the default at an existing account.
     *
     * @return Account|null the new default, or null when no account owns the address
     */
    public function setDefaultAccount(string $email): ?array
    {
        $account = $this->accountFor($email);

        if (! $account) {
            return null;
        }

        $this->values[self::DEFAULT_ACCOUNT_KEY] = $account['email'];

        return [...$account, 'default' => true];
    }

    /**
     * Checks if at least one account is configured.
     */
    public function hasAccount(): bool
    {
        return $this->readAccounts() !== [];
    }

    /**
     * Gets the address of the default account.
     */
    public function getEmail(): ?string
    {
        return $this->defaultAccount()['email'] ?? null;
    }

    /**
     * Gets the aliases of the default account.
     *
     * @return list<string>
     */
    public function getAliases(): array
    {
        return $this->defaultAccount()['aliases'] ?? [];
    }

    /**
     * Checks if the address belongs to the default account.
     */
    public function matchesEmail(string $email): bool
    {
        $default = $this->defaultAccount();

        if (! $default) {
            return false;
        }

        $match = $this->accountFor($email);

        return $match !== null && $match['slug'] === $default['slug'];
    }

    /**
     * Saves configuration to file with atomic write.
     *
     * @throws RuntimeException if write fails
     */
    public function save(): void
    {
        $this->paths->ensureBaseDir();

        $content = $this->serialize();
        $path = $this->paths->envFile();
        $tempPath = $path.'.tmp.'.getmypid();

        // Write to temp file first
        if (file_put_contents($tempPath, $content) === false) {
            throw new RuntimeException("Failed to write to: {$tempPath}");
        }

        // Set permissions before rename
        if (! chmod($tempPath, self::REQUIRED_FILE_PERMS)) {
            unlink($tempPath);
            throw new RuntimeException("Failed to set permissions on: {$tempPath}");
        }

        // Atomic rename
        if (! rename($tempPath, $path)) {
            unlink($tempPath);
            throw new RuntimeException("Failed to rename temp file to: {$path}");
        }
    }

    /**
     * Reloads configuration from file.
     */
    public function reload(): self
    {
        $this->loaded = false;
        $this->values = [];
        $this->skillValues = [];
        $this->ensureLoaded();

        return $this;
    }

    /**
     * Checks if .env file exists.
     */
    public function exists(): bool
    {
        return file_exists($this->paths->envFile());
    }

    /**
     * Returns file permissions or null if file doesn't exist.
     */
    public function getFilePermissions(): ?int
    {
        $path = $this->paths->envFile();
        if (! file_exists($path)) {
            return null;
        }

        return fileperms($path) & 0777;
    }

    /**
     * Checks if file permissions are secure (0600 or stricter).
     */
    public function hasSecurePermissions(): bool
    {
        $perms = $this->getFilePermissions();
        if ($perms === null) {
            return true; // No file = secure
        }

        // Check that group and others have no permissions
        return ($perms & 0077) === 0;
    }

    /**
     * Returns a warning message if permissions are insecure.
     */
    public function getPermissionWarning(): ?string
    {
        if ($this->hasSecurePermissions()) {
            return null;
        }

        $perms = $this->getFilePermissions();
        $octal = decoct($perms);

        return "Warning: {$this->paths->envFile()} has insecure permissions (0{$octal}). "
            ."Expected 0600. Run: chmod 600 {$this->paths->envFile()}";
    }

    /**
     * Reads the stored accounts, migrating single-account keys on the way.
     *
     * @return list<array{slug: string, email: string, refresh_token: string, aliases: list<string>}>
     */
    private function readAccounts(): array
    {
        $this->ensureLoaded();
        $this->migrateLegacyAccount();

        $accounts = [];

        foreach ($this->accountSlugs() as $slug) {
            $email = trim($this->values[$this->accountKey($slug, 'ADDRESS')] ?? '');
            $refreshToken = trim($this->values[$this->accountKey($slug, 'REFRESH_TOKEN')] ?? '');

            if ($email === '' || $refreshToken === '') {
                continue;
            }

            $accounts[] = [
                'slug' => $slug,
                'email' => $email,
                'refresh_token' => $refreshToken,
                'aliases' => $this->splitAliases($this->values[$this->accountKey($slug, 'ALIASES')] ?? null),
            ];
        }

        return $accounts;
    }

    /**
     * Moves single-account keys into per-account storage.
     *
     * Values stay in memory until the next save, so an install that
     * never writes keeps reading its original file.
     */
    private function migrateLegacyAccount(): void
    {
        $email = trim($this->values[self::LEGACY_ADDRESS_KEY] ?? '');
        $refreshToken = trim($this->values[self::LEGACY_REFRESH_TOKEN_KEY] ?? '');

        if ($email === '' || $refreshToken === '') {
            return;
        }

        $slug = $this->slugFor($email);

        // A stored account already holds the current token, so the legacy keys only get dropped.
        if (! isset($this->values[$this->accountKey($slug, 'ADDRESS')])) {
            $this->values[$this->accountKey($slug, 'ADDRESS')] = $email;
            $this->values[$this->accountKey($slug, 'REFRESH_TOKEN')] = $refreshToken;

            $aliases = $this->splitAliases($this->values[self::LEGACY_ALIASES_KEY] ?? null);
            if ($aliases !== []) {
                $this->writeAliases($slug, $aliases);
            }
        }

        if (! isset($this->values[self::DEFAULT_ACCOUNT_KEY])) {
            $this->values[self::DEFAULT_ACCOUNT_KEY] = $email;
        }

        unset(
            $this->values[self::LEGACY_ADDRESS_KEY],
            $this->values[self::LEGACY_REFRESH_TOKEN_KEY],
            $this->values[self::LEGACY_ALIASES_KEY],
        );
    }

    /**
     * Returns the default address, falling back to the first account.
     *
     * @param  list<array{slug: string, email: string, refresh_token: string, aliases: list<string>}>  $accounts
     */
    private function resolveDefaultEmail(array $accounts): ?string
    {
        if ($accounts === []) {
            return null;
        }

        $configured = strtolower(trim($this->values[self::DEFAULT_ACCOUNT_KEY] ?? ''));

        foreach ($accounts as $account) {
            if (strtolower($account['email']) === $configured) {
                return $account['email'];
            }
        }

        return $accounts[0]['email'];
    }

    /**
     * Returns the slugs of every stored account, in file order.
     *
     * @return list<string>
     */
    private function accountSlugs(): array
    {
        $pattern = '/^'.preg_quote(self::ACCOUNT_KEY_PREFIX, '/').'(.+)_ADDRESS$/';
        $slugs = [];

        foreach (array_keys($this->values) as $key) {
            if (preg_match($pattern, $key, $matches)) {
                $slugs[] = $matches[1];
            }
        }

        return $slugs;
    }

    /**
     * Returns the slug of an address, reusing the slug of a stored account.
     *
     * Distinct addresses can sanitize to the same slug, so a taken
     * slug gets a numeric suffix.
     */
    private function slugFor(string $email): string
    {
        $email = trim($email);
        $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $email));
        $base = trim($base, '_');

        foreach ($this->accountSlugs() as $slug) {
            if (strcasecmp($this->values[$this->accountKey($slug, 'ADDRESS')] ?? '', $email) === 0) {
                return $slug;
            }
        }

        $slug = $base;
        $suffix = 2;

        while (isset($this->values[$this->accountKey($slug, 'ADDRESS')])) {
            $slug = $base.'_'.$suffix++;
        }

        return $slug;
    }

    private function accountKey(string $slug, string $field): string
    {
        return self::ACCOUNT_KEY_PREFIX.$slug.'_'.$field;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function writeAliases(string $slug, array $aliases): void
    {
        $key = $this->accountKey($slug, 'ALIASES');
        $aliases = array_values(array_filter(array_map('trim', $aliases), fn (string $alias): bool => $alias !== ''));

        if ($aliases === []) {
            unset($this->values[$key]);

            return;
        }

        $this->values[$key] = implode(',', $aliases);
    }

    /**
     * @return list<string>
     */
    private function splitAliases(?string $aliases): array
    {
        if ($aliases === null || trim($aliases) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $aliases)),
            fn (string $alias): bool => $alias !== ''
        ));
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        // Load skill-level .env first (base layer with shared credentials)
        $skillPath = $this->paths->skillEnvFile();
        if ($skillPath) {
            $this->skillValues = $this->parse((string) file_get_contents($skillPath));
            $this->values = $this->skillValues;
        }

        // Load user .env second (overrides skill values)
        $userPath = $this->paths->envFile();
        if (file_exists($userPath)) {
            $this->values = array_merge($this->values, $this->parse((string) file_get_contents($userPath)));
        }

        $this->loaded = true;
    }

    /**
     * Parses dotenv content into array.
     *
     * @return array<string, string>
     */
    private function parse(string $content): array
    {
        $values = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=value
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Serializes values to dotenv format.
     *
     * Keys the skill .env already provides are left out, so shared
     * credentials stay in one place and accounts stay personal.
     */
    private function serialize(): string
    {
        $this->ensureLoaded();
        $this->migrateLegacyAccount();

        $lines = [];

        foreach ($this->orderedKeys() as $key) {
            $value = $this->values[$key];

            if (($this->skillValues[$key] ?? null) === $value) {
                continue;
            }

            $lines[] = $this->formatLine($key, $value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Orders keys as credentials, default account, then one group per account.
     *
     * @return list<string>
     */
    private function orderedKeys(): array
    {
        $ordered = array_values(array_filter(
            self::LEADING_KEYS,
            fn (string $key): bool => isset($this->values[$key])
        ));

        foreach ($this->accountSlugs() as $slug) {
            foreach (['ADDRESS', 'REFRESH_TOKEN', 'ALIASES'] as $field) {
                $key = $this->accountKey($slug, $field);

                if (isset($this->values[$key])) {
                    $ordered[] = $key;
                }
            }
        }

        foreach (array_keys($this->values) as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }

    private function formatLine(string $key, string $value): string
    {
        // Quote value if it contains special characters
        if (preg_match('/[\s#\'"]/', $value)) {
            $value = '"'.addcslashes($value, '"\\').'"';
        }

        return "{$key}={$value}";
    }
}
