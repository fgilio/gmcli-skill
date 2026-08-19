# gmcli - Development

Laravel Zero CLI for Gmail workflows.

## Development Setup

```bash
cd $AGENT_HOME/skills/gmcli/src
composer install
./gmcli --help
```

## Project Structure

```
src/
├── app/
│   ├── Commands/           # CLI commands
│   │   ├── DefaultCommand.php   # Main dispatcher
│   │   ├── BuildCommand.php     # Build binary
│   │   ├── Accounts/            # accounts:credentials|list|add|default|remove|doctor
│   │   └── Gmail/               # gmail:search|thread|labels|filters|drafts|send|url
│   ├── Exceptions/         # GmailAuthException, GmailConnectionException
│   └── Services/           # Core services
│       ├── GmcliPaths.php       # ~/.gmcli/ directory management
│       ├── GmcliEnv.php         # .env file handling
│       ├── OAuthService.php     # Google OAuth 2.0
│       ├── GmailClient.php      # Gmail API client
│       ├── GmailClientFactory.php # Gmail client construction seam
│       ├── MimeHelper.php       # MIME parsing
│       ├── LabelResolver.php    # Label name → ID
│       └── MessageBuilder.php   # RFC2822 message building
├── tests/                  # Pest tests
├── box.json               # Box PHAR config
└── composer.json
```

## Building

First-time setup (builds PHP + micro.sfx):

```bash
php-cli-skill-runtime-setup --doctor
php-cli-skill-runtime-build
```

Build and install to skill root:

```bash
./gmcli build              # builds + copies to ../skill/gmcli
./gmcli build --no-install # only builds to builds/gmcli
```

The build:

1. Creates `builds/gmcli.phar` using Box
2. Combines with `micro.sfx` for standalone binary
3. Copies to `../gmcli` (skill root)

## OAuth Scope

Uses:

- `https://www.googleapis.com/auth/gmail.modify`
- `https://www.googleapis.com/auth/gmail.settings.basic`

Capabilities:

- Read, compose, send, and modify email
- Manage labels
- Create, list, and delete Gmail filters
- **Cannot** permanently delete messages (only trash)

Accounts authenticated before the settings scope landed grant it by running `accounts:add` again for the same address, which re-authenticates in place.

`accounts:doctor` calls `users.getProfile` once per account and grades the outcome: `ok`, `auth_failed` (`GmailAuthException`, a token Google rejects or a 401/403 answer), `mismatch` (the token authenticates another mailbox), `unreachable` (`GmailConnectionException`), or `error`. It exits non-zero when any account is not `ok`.

## Account Storage

`~/.gmcli/.env` holds one key group per account plus the default pointer:

```
GMAIL_DEFAULT_ACCOUNT=you@gmail.com
GMAIL_ACCOUNT_YOU_GMAIL_COM_ADDRESS=you@gmail.com
GMAIL_ACCOUNT_YOU_GMAIL_COM_REFRESH_TOKEN=...
GMAIL_ACCOUNT_YOU_GMAIL_COM_ALIASES=alias@gmail.com,other@gmail.com
```

The slug comes from the address, so the keys of an account stay put across saves. Files still holding the single-account `GMAIL_ADDRESS` and `GMAIL_REFRESH_TOKEN` keys read as one account and get rewritten into the keyed form on the next save. Account keys never go in the shared `.env` next to the binary, which carries the OAuth credentials only.

## Testing

```bash
./vendor/bin/pest
```

Test coverage includes:

- OAuth code extraction and URL building
- Multi-account storage, default selection, and migration from the single-account keys
- MIME parsing and base64url encoding
- Label name resolution
- Filter create/list/delete command flows
- Message building (headers, attachments, threading)
- Secret redaction and HTTP verb coverage

## License

MIT
