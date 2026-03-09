# gmcli - Development

Laravel Zero CLI for Gmail workflows.

## Built With

This skill was created using [php-cli-builder](../php-cli-builder/SKILL.md).

## Development Setup

```bash
cd ~/.claude/skills/gmcli/src
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
│   │   ├── Accounts/            # accounts:credentials|list|add|remove
│   │   └── Gmail/               # gmail:search|thread|labels|filters|drafts|send|url
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
php-cli-builder-spc-setup --doctor
php-cli-builder-spc-build
```

Build and install to skill root:
```bash
./gmcli build              # builds + copies to ../gmcli
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

Existing authenticated accounts must be removed and added again once after upgrading so the new Gmail settings scope is granted.

## Testing

```bash
./vendor/bin/pest
```

Test coverage includes:
- OAuth code extraction and URL building
- MIME parsing and base64url encoding
- Label name resolution
- Filter create/list/delete command flows
- Message building (headers, attachments, threading)
- Secret redaction and HTTP verb coverage

## License

MIT
