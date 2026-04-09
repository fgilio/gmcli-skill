<?php

namespace App\Commands\Gmail;

use App\Services\MessageBuilder;
use App\Services\MimeHelper;

/**
 * Creates a new Gmail draft.
 */
class DraftsCreateCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:create
        {--to= : Recipients (comma-separated)}
        {--subject= : Subject line}
        {--body= : Message body}
        {--cc= : CC recipients (comma-separated)}
        {--bcc= : BCC recipients (comma-separated)}
        {--reply-to= : Message ID to reply to}
        {--attach=* : File attachments}
        {--open : Open Gmail in browser after creating draft}';

    protected $description = 'Create a new draft';

    public function handle(): int
    {
        $to = $this->option('to');
        $subject = $this->option('subject');
        $body = $this->option('body');

        if (empty($to) || empty($subject) || empty($body)) {
            return $this->failWith('Missing required options: --to, --subject, --body');
        }

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $cc = $this->option('cc');
        $bcc = $this->option('bcc');
        $replyTo = $this->option('reply-to');
        $attachments = $this->option('attach') ?: [];

        $builder = new MessageBuilder;
        $builder->from($this->env->getEmail())
            ->to($this->parseEmails($to))
            ->subject($subject)
            ->body($body);

        if ($cc) {
            $builder->cc($this->parseEmails($cc));
        }

        if ($bcc) {
            $builder->bcc($this->parseEmails($bcc));
        }

        if ($replyTo) {
            $this->setupReplyTo($builder, $replyTo);
        }

        foreach ($attachments as $path) {
            $builder->attach($path);
        }

        $raw = $builder->build();
        $payload = ['message' => ['raw' => $raw]];

        if ($builder->getThreadId()) {
            $payload['message']['threadId'] = $builder->getThreadId();
        }

        $this->logger->verbose('Creating draft...');
        $response = $this->gmail->post('/users/me/drafts', $payload);

        $draftId = $response['id'] ?? '';
        $messageId = $response['message']['id'] ?? '';

        if ($this->wantsJson()) {
            return $this->outputJson([
                'draftId' => $draftId,
                'messageId' => $messageId,
            ]);
        }

        $this->info("Draft created: {$draftId}");

        if ($this->option('open')) {
            $this->openInBrowser($builder->getThreadId());
        }

        return self::SUCCESS;
    }

    private function parseEmails(string $emails): array
    {
        return array_map('trim', explode(',', $emails));
    }

    private function setupReplyTo(MessageBuilder $builder, string $messageId): void
    {
        $this->logger->verbose("Fetching reply-to message: {$messageId}");

        $message = $this->gmail->get("/users/me/messages/{$messageId}", [
            'format' => 'metadata',
            'metadataHeaders' => ['Message-ID', 'References'],
        ]);

        $mime = new MimeHelper;
        $payload = $message['payload'] ?? [];

        $headerMsgId = $mime->getHeader($payload, 'Message-ID') ?? '';
        $references = $mime->getHeader($payload, 'References') ?? '';

        $newReferences = $references ? "{$references} {$headerMsgId}" : $headerMsgId;

        $builder->replyTo($headerMsgId, $newReferences, $message['threadId'] ?? null);
    }

    private function openInBrowser(?string $threadId): void
    {
        $email = urlencode($this->env->getEmail());

        if ($threadId) {
            $hex = strtolower(ltrim($threadId, '0x'));
            $url = "https://mail.google.com/mail/u/?authuser={$email}#all/{$hex}";
        } else {
            $url = "https://mail.google.com/mail/u/?authuser={$email}#drafts";
        }

        $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        exec("{$command} ".escapeshellarg($url).' 2>/dev/null &');

        $this->logger->verbose("Opened: {$url}");
    }
}
