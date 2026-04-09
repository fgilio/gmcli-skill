<?php

namespace App\Commands\Gmail;

use App\Services\GmailIdHelper;
use App\Services\GmcliPaths;
use App\Services\MimeHelper;

/**
 * Displays a Gmail thread with all messages.
 *
 * Shows: Message-ID, headers, body, attachments.
 */
class ThreadCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:thread
        {--thread-id= : Thread ID to view}
        {--download : Download attachments}';

    protected $description = 'Get thread with all messages';

    private MimeHelper $mime;

    public function handle(): int
    {
        $threadId = $this->option('thread-id');
        $download = $this->option('download');

        if (empty($threadId)) {
            return $this->failWith('Missing thread ID. Usage: gmcli gmail:thread --thread-id=<thread-id> [--download]');
        }

        $helper = new GmailIdHelper;
        $parsed = $helper->parse($threadId);
        $threadId = $parsed['threadId'];

        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $this->logger->verbose("Resolved: {$parsed['original']} -> {$threadId} ({$parsed['source']})");
        $this->mime = new MimeHelper;

        $this->logger->verbose("Fetching thread: {$threadId}");

        $thread = $this->gmail->get("/users/me/threads/{$threadId}", [
            'format' => 'full',
        ]);

        $messages = $thread['messages'] ?? [];

        if (empty($messages)) {
            if ($this->wantsJson()) {
                return $this->outputJson(['threadId' => $threadId, 'messages' => []]);
            }

            $this->info('Thread has no messages.');

            return self::SUCCESS;
        }

        if ($this->wantsJson()) {
            $jsonMessages = array_map(fn ($message) => $this->buildMessageData($message), $messages);

            return $this->outputJson(['threadId' => $threadId, 'messages' => $jsonMessages]);
        }

        foreach ($messages as $index => $message) {
            if ($index > 0) {
                $this->line(str_repeat('-', 60));
            }

            $this->displayMessage($message, $download);
        }

        return self::SUCCESS;
    }

    private function buildMessageData(array $message): array
    {
        $payload = $message['payload'] ?? [];

        return [
            'id' => $message['id'],
            'from' => $this->mime->getHeader($payload, 'From') ?? '',
            'to' => $this->mime->getHeader($payload, 'To') ?? '',
            'cc' => $this->mime->getHeader($payload, 'Cc'),
            'date' => $this->mime->getHeader($payload, 'Date') ?? '',
            'subject' => $this->mime->getHeader($payload, 'Subject') ?? '(no subject)',
            'messageIdHeader' => $this->mime->getHeader($payload, 'Message-ID') ?? '',
            'labels' => $message['labelIds'] ?? [],
            'body' => $this->mime->extractTextBody($payload),
            'attachments' => array_map(fn ($a) => [
                'filename' => $a['filename'],
                'mimeType' => $a['mimeType'],
                'size' => $a['size'],
            ], $this->mime->getAttachments($payload)),
        ];
    }

    private function displayMessage(array $message, bool $download): void
    {
        $payload = $message['payload'] ?? [];
        $messageId = $message['id'];

        $from = $this->mime->getHeader($payload, 'From') ?? '';
        $to = $this->mime->getHeader($payload, 'To') ?? '';
        $cc = $this->mime->getHeader($payload, 'Cc');
        $date = $this->mime->getHeader($payload, 'Date') ?? '';
        $subject = $this->mime->getHeader($payload, 'Subject') ?? '(no subject)';
        $msgIdHeader = $this->mime->getHeader($payload, 'Message-ID') ?? '';

        $this->line("Message-ID: {$messageId}");
        if ($msgIdHeader) {
            $this->line("Header-ID: {$msgIdHeader}");
        }
        $this->line("Date: {$date}");
        $this->line("From: {$from}");
        $this->line("To: {$to}");
        if ($cc) {
            $this->line("Cc: {$cc}");
        }
        $this->line("Subject: {$subject}");

        $labelIds = $message['labelIds'] ?? [];
        if (! empty($labelIds)) {
            $this->line('Labels: '.implode(', ', $labelIds));
        }

        $this->newLine();

        $body = $this->mime->extractTextBody($payload);
        $this->line($body !== '' ? $body : '(no text/plain body)');

        $attachments = $this->mime->getAttachments($payload);
        if (! empty($attachments)) {
            $this->newLine();
            $this->line('Attachments:');

            foreach ($attachments as $att) {
                $size = $this->formatSize($att['size']);
                $this->line("  - {$att['filename']} ({$att['mimeType']}, {$size})");

                if ($download && $att['attachmentId']) {
                    $this->downloadAttachment($messageId, $att);
                }
            }
        }
    }

    private function downloadAttachment(string $messageId, array $attachment): void
    {
        $this->logger->verbose("Downloading: {$attachment['filename']}");

        $response = $this->gmail->get(
            "/users/me/messages/{$messageId}/attachments/{$attachment['attachmentId']}"
        );

        $data = $response['data'] ?? '';
        if (empty($data)) {
            $this->warn('    Failed to download: empty data');

            return;
        }

        $content = $this->mime->decodeBase64Url($data);

        $filename = $this->buildSafeFilename($messageId, $attachment);
        $path = $this->getAttachmentsPath().'/'.$filename;

        file_put_contents($path, $content);
        $this->info("    Saved: {$path}");
    }

    private function buildSafeFilename(string $messageId, array $attachment): string
    {
        $attachmentIdPrefix = substr($attachment['attachmentId'], 0, 8);
        $name = $this->sanitizeFilename($attachment['filename']);

        return "{$messageId}_{$attachmentIdPrefix}_{$name}";
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        return $filename ?: 'attachment';
    }

    private function getAttachmentsPath(): string
    {
        $paths = app(GmcliPaths::class);
        $paths->ensureAttachmentsDir();

        return $paths->attachmentsDir();
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
