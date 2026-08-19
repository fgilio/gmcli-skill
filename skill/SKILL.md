---
name: gmcli
description: >
  Gmail CLI. Read, search, and manage email from the terminal. Use when user asks about emails, inbox, or sending messages.
user-invocable: true
disable-model-invocation: false
---

# gmcli - Gmail CLI

## Execution

`gmcli` is a self-contained binary available on PATH. Run it directly - never prefix with `bun`, `node`, `php`, or any runtime.

## Quick Reference

| Command | Purpose |
| --- | --- |
| `gmcli accounts:credentials <file.json>` | Set OAuth credentials |
| `gmcli accounts:list` | List configured accounts |
| `gmcli accounts:add <email>` | Add Gmail account via OAuth |
| `gmcli accounts:default <email>` | Choose the default account |
| `gmcli accounts:remove <email>` | Remove account |
| `gmcli accounts:doctor` | Check that every account still authenticates |
| `gmcli gmail:search "<query>"` | Search threads |
| `gmcli gmail:thread --thread-id=<id>` | View thread messages |
| `gmcli gmail:labels:list` | List all labels |
| `gmcli gmail:labels:modify --thread-ids=<ids> --add/--remove` | Modify thread labels |
| `gmcli gmail:filters:list` | List Gmail filters |
| `gmcli gmail:filters:create ...` | Create a Gmail filter |
| `gmcli gmail:filters:delete --filter-id=<id>` | Delete a Gmail filter |
| `gmcli gmail:drafts:list` | List drafts |
| `gmcli gmail:drafts:create --to --subject --body` | Create draft |
| `gmcli gmail:drafts:get --draft-id=<id>` | View draft |
| `gmcli gmail:drafts:delete --draft-id=<id>` | Delete draft |
| `gmcli gmail:drafts:send --draft-id=<id>` | Send draft |
| `gmcli gmail:send --to --subject --body` | Send email |
| `gmcli gmail:url --thread-ids=<ids>` | Generate Gmail web URLs |

## Full Options Reference

| Command | Options |
| --- | --- |
| `accounts:doctor` | `--json` |
| `gmail:search` | `--limit=20` `--page` `--json` |
| `gmail:thread` | `--thread-id` `--download` `--json` |
| `gmail:send` | `--to` `--subject` `--body` `--cc` `--bcc` `--reply-to` `--attach` `--json` |
| `gmail:drafts:create` | `--to` `--subject` `--body` `--cc` `--bcc` `--reply-to` `--attach` `--open` `--json` |
| `gmail:drafts:get` | `--draft-id` `--download` `--json` |
| `gmail:drafts:delete` | `--draft-id` `--json` |
| `gmail:drafts:send` | `--draft-id` `--json` |
| `gmail:labels:modify` | `--thread-ids` `--add` `--remove` `--json` |
| `gmail:filters:list` | `--json` |
| `gmail:filters:create` | `--from` `--to` `--subject` `--query` `--negated-query` `--has-attachment` `--exclude-chats` `--add-label` `--remove-label` `--skip-inbox` `--mark-read` `--star` `--trash` `--never-spam` `--forward` `--json` |
| `gmail:filters:delete` | `--filter-id` `--json` |
| `gmail:url` | `--thread-ids` `--json` |
| `gmail:labels:list` | `--json` |
| `gmail:drafts:list` | `--json` |

Every gmail command runs against the default account. Use `-a <email>` to pick another configured account. The address can be an account's primary address or one of its aliases.

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

## Multiple Accounts

Add as many accounts as you need. The first one added becomes the default:

```bash
gmcli accounts:add you@gmail.com
gmcli accounts:add you@company.com          # add --default to make it the default
gmcli accounts:list                          # the default is marked "(default)"
gmcli accounts:default you@company.com       # switch the default later
gmcli gmail:search "is:unread" -a you@gmail.com
```

Adding an address that is already configured re-authenticates it and replaces its refresh token, so a scope upgrade or an expired token needs no removal first.

## Health Check

A command that runs into a token Google rejects names the account, the reason Google gave, and the `accounts:add` call that repairs it, then exits 1 (in `--json`, the same information arrives as a one-line `{"error": "..."}` on stderr).

Before trusting a long run, or to see the state of every address at once, check them all:

```bash
gmcli accounts:doctor
gmcli accounts:doctor --json
```

Each account gets one Gmail profile call. The report names the account the token really authenticates, the default account, the config file and its permissions, and prints the `accounts:add` command that repairs any account Google rejects. It exits non-zero when an account needs attention, so it works as a gate in a script.

Account statuses in JSON: `ok`, `auth_failed` (re-run `accounts:add`), `mismatch` (the token belongs to another mailbox), `unreachable` (network), `error` (anything else).

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
gmcli gmail:search "is:unread" --limit=5

# Label operations
gmcli gmail:labels:modify --thread-ids=abc123 --remove UNREAD
gmcli gmail:labels:modify --thread-ids=abc123 --add TRASH --remove INBOX

# Filter operations
gmcli gmail:filters:list
gmcli gmail:filters:create \
    --from "alert@ohdear.app" \
    --add-label "Infra" \
    --skip-inbox
gmcli gmail:filters:delete --filter-id=filter123
```

If an existing account was authenticated before filter support landed, reconnect once to grant Gmail settings access:

```bash
gmcli accounts:add you@gmail.com
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

| Path                    | Purpose                                       |
| ----------------------- | --------------------------------------------- |
| `.env` (next to binary) | Shared OAuth credentials (optional)           |
| `~/.gmcli/.env`         | Per-account tokens and addresses (0600 perms) |
| `~/.gmcli/attachments/` | Downloaded attachments                        |
