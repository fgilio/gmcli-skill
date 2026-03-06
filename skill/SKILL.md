---
name: gmcli
description: >
  Gmail CLI. Read, search, and manage email from the terminal. Use when user asks about emails, inbox, or sending messages.
user-invocable: true
disable-model-invocation: false
---

# gmcli - Gmail CLI

## Execution

`gmcli` is a self-contained binary available on PATH.
Run it directly - never prefix with `bun`, `node`, `php`, or any runtime.

## Quick Reference

| Command | Purpose |
|---------|---------|
| `gmcli accounts:credentials <file.json>` | Set OAuth credentials |
| `gmcli accounts:list` | List configured account |
| `gmcli accounts:add <email>` | Add Gmail account via OAuth |
| `gmcli accounts:remove <email>` | Remove account |
| `gmcli gmail:search "<query>"` | Search threads |
| `gmcli gmail:thread --thread-id=<id>` | View thread messages |
| `gmcli gmail:labels:list` | List all labels |
| `gmcli gmail:labels:modify --thread-ids=<ids> --add/--remove` | Modify thread labels |
| `gmcli gmail:drafts:list` | List drafts |
| `gmcli gmail:drafts:create --to --subject --body` | Create draft |
| `gmcli gmail:drafts:get --draft-id=<id>` | View draft |
| `gmcli gmail:drafts:delete --draft-id=<id>` | Delete draft |
| `gmcli gmail:drafts:send --draft-id=<id>` | Send draft |
| `gmcli gmail:send --to --subject --body` | Send email |
| `gmcli gmail:url --thread-ids=<ids>` | Generate Gmail web URLs |

## Full Options Reference

| Command | Options |
|---------|---------|
| `gmail:search` | `--max=20` `--page` `--json` |
| `gmail:thread` | `--thread-id` `--download` `--json` |
| `gmail:send` | `--to` `--subject` `--body` `--cc` `--bcc` `--reply-to` `--attach` `--json` |
| `gmail:drafts:create` | `--to` `--subject` `--body` `--cc` `--bcc` `--reply-to` `--attach` `--open` `--json` |
| `gmail:drafts:get` | `--draft-id` `--download` `--json` |
| `gmail:drafts:delete` | `--draft-id` `--json` |
| `gmail:drafts:send` | `--draft-id` `--json` |
| `gmail:labels:modify` | `--thread-ids` `--add` `--remove` `--json` |
| `gmail:url` | `--thread-ids` `--json` |
| `gmail:labels:list` | `--json` |
| `gmail:drafts:list` | `--json` |

Account is optional when configured. Use `-a <email>` to override.

## Setup

Personal use:
```bash
gmcli accounts:credentials ~/path/to/client_secret.json
gmcli accounts:add you@gmail.com
```

Team use (credentials in `.env` next to binary):
```bash
gmcli accounts:add you@gmail.com
```

## Usage Examples

```bash
# Search unread emails
gmcli gmail:search "in:inbox is:unread"

# View thread with attachments
gmcli gmail:thread --thread-id=19aea1f2f3532db5 --download

# Send email
gmcli gmail:send --to "recipient@example.com" \
    --subject "Hello" --body "Message body"

# Reply to thread (send immediately)
gmcli gmail:send --to "recipient@example.com" \
    --subject "Re: Hello" --body "Reply text" \
    --reply-to 19aea1f2f3532db5

# Create draft reply (opens in browser)
gmcli gmail:drafts:create --to "recipient@example.com" \
    --subject "Re: Hello" --body "Reply text" \
    --reply-to 19aea1f2f3532db5 --open

# Search with limit
gmcli gmail:search "is:unread" --max=5

# Label operations
gmcli gmail:labels:modify --thread-ids=abc123 --remove UNREAD
gmcli gmail:labels:modify --thread-ids=abc123 --add TRASH --remove INBOX
```

## JSON Output

Use `--json` for structured output:

```bash
# Text output (default)
gmcli gmail:search "is:unread"

# JSON output
gmcli gmail:search "is:unread" --json
```

JSON structure:
- Success: `{"data": [...]}`
- Error: `{"error": "message"}` (to stderr)

## Data Storage

| Path | Purpose |
|------|---------|
| `.env` (next to binary) | Shared OAuth credentials (optional) |
| `~/.gmcli/.env` | Personal tokens and email (0600 perms) |
| `~/.gmcli/attachments/` | Downloaded attachments |
