# gmcli

Gmail command-line interface. Self-contained binary, no PHP required.

## Install

See [skill/SETUP.md](skill/SETUP.md) or run `./skill/install`

## Setup

### Personal Use

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create project → Enable Gmail API
3. Credentials → OAuth 2.0 → Desktop app
4. Download JSON file

```bash
$AGENT_HOME/skills/gmcli/gmcli accounts:credentials ~/Downloads/client_secret.json
$AGENT_HOME/skills/gmcli/gmcli accounts:add you@gmail.com
```

### Team Distribution

Admin creates shared credentials once:

```bash
# Copy .env.example to .env (next to gmcli binary)
cp .env.example .env
# Fill in GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET
```

Team members only need to:

```bash
$AGENT_HOME/skills/gmcli/gmcli accounts:add their@company.com
```

Credentials load from `.env` next to binary; tokens save to `~/.gmcli/.env`.

## Usage

```bash
$AGENT_HOME/skills/gmcli/gmcli gmail:search "in:inbox is:unread"
$AGENT_HOME/skills/gmcli/gmcli gmail:thread --thread-id=<id>
$AGENT_HOME/skills/gmcli/gmcli gmail:thread --thread-id=<id> --download
$AGENT_HOME/skills/gmcli/gmcli gmail:labels:list
$AGENT_HOME/skills/gmcli/gmcli gmail:labels:modify --thread-ids=<id> --add=STARRED --remove=UNREAD
$AGENT_HOME/skills/gmcli/gmcli gmail:filters:list
$AGENT_HOME/skills/gmcli/gmcli gmail:filters:create --from "alert@ohdear.app" --add-label "Infra" --skip-inbox
$AGENT_HOME/skills/gmcli/gmcli gmail:send --to "to@example.com" --subject "Hi" --body "Hello"
```

Account is optional once configured. Use `$AGENT_HOME/skills/gmcli/gmcli -a you@gmail.com <command>` to override.

If you upgrade to a version with filter create/delete support and already authenticated before, remove and add the account again once to grant the new Gmail settings scope:

```bash
$AGENT_HOME/skills/gmcli/gmcli accounts:remove you@gmail.com
$AGENT_HOME/skills/gmcli/gmcli accounts:add you@gmail.com
```

## Data

| Path | Purpose |
|------|---------|
| `.env` (next to binary) | Shared OAuth credentials (optional) |
| `~/.gmcli/.env` | Personal tokens and email |
| `~/.gmcli/attachments/` | Downloaded attachments |

## Development

See [src/README.md](src/README.md) for building from source.
