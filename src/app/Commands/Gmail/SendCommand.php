<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;
use App\Services\MessageBuilder;
use App\Services\MimeHelper;

/**
 * Sends an email directly.
 */
class SendCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:send
        {--to= : Recipients (comma-separated)}
        {--subject= : Subject line}
        {--body= : Message body}
        {--cc= : CC recipients (comma-separated)}
        {--bcc= : BCC recipients (comma-separated)}
        {--reply-to= : Message ID to reply to}
        {--attach=* : File attachments}';

    protected $description = 'Send an email directly';

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $to = $this->option('to');
        $subject = $this->option('subject');
        $body = $this->option('body');
        $cc = $this->option('cc');
        $bcc = $this->option('bcc');
        $replyTo = $this->option('reply-to');
        $attachments = $this->option('attach') ?: [];

        if (empty($to) || empty($subject) || empty($body)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:send', self::FAILURE, ['success' => false], $startTime);

                return $this->jsonError('Missing required options: --to, --subject, --body');
            }
            $this->error('Missing required options.');
            $this->line('Usage: gmcli gmail:send --to <emails> --subject <s> --body <b>');

            $analytics->track('gmail:send', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:send', self::FAILURE, ['success' => false], $startTime);

            return self::FAILURE;
        }

        try {
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

            // Handle reply-to
            if ($replyTo) {
                $this->setupReplyTo($builder, $replyTo);
            }

            // Add attachments
            foreach ($attachments as $path) {
                $builder->attach($path);
            }

            $raw = $builder->build();
            $payload = ['raw' => $raw];

            // Add thread ID if replying
            if ($builder->getThreadId()) {
                $payload['threadId'] = $builder->getThreadId();
            }

            $this->logger->verbose('Sending message...');
            $response = $this->gmail->post('/users/me/messages/send', $payload);

            $messageId = $response['id'] ?? '';
            $threadId = $response['threadId'] ?? '';

            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:send', self::SUCCESS, ['success' => true], $startTime);

                return $this->outputJson([
                    'messageId' => $messageId,
                    'threadId' => $threadId,
                ]);
            }

            $this->info('Message sent successfully.');
            $this->line("Message-ID: {$messageId}");
            $this->line("Thread-ID: {$threadId}");

            $analytics->track('gmail:send', self::SUCCESS, ['success' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:send', self::FAILURE, ['success' => false], $startTime);

            return $this->jsonError($e->getMessage());
        }
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
}
