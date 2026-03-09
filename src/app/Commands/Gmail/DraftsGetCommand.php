<?php

namespace App\Commands\Gmail;

use App\Services\Analytics;
use App\Services\MimeHelper;

/**
 * Gets a Gmail draft with content.
 */
class DraftsGetCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:drafts:get
        {--draft-id= : Draft ID}
        {--download : Download attachments}';

    protected $description = 'View draft with attachments';

    private MimeHelper $mime;

    public function handle(Analytics $analytics): int
    {
        $startTime = microtime(true);
        $email = null;
        $draftId = $this->option('draft-id');
        $download = $this->option('download');

        if (empty($draftId)) {
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:get', self::FAILURE, ['found' => false], $startTime);

                return $this->jsonError('Missing draft ID.');
            }
            $this->error('Missing draft ID.');
            $this->line('Usage: gmcli gmail:drafts:get --draft-id=<draft-id> [--download]');

            $analytics->track('gmail:drafts:get', self::FAILURE, ['found' => false], $startTime);

            return self::FAILURE;
        }

        if (! $this->initGmail()) {
            $analytics->track('gmail:drafts:get', self::FAILURE, ['found' => false], $startTime);

            return self::FAILURE;
        }

        $this->mime = new MimeHelper;

        try {
            $this->logger->verbose("Fetching draft: {$draftId}");

            $draft = $this->gmail->get("/users/me/drafts/{$draftId}", [
                'format' => 'full',
            ]);

            $message = $draft['message'] ?? [];
            $payload = $message['payload'] ?? [];

            // Headers
            $to = $this->mime->getHeader($payload, 'To') ?? '';
            $cc = $this->mime->getHeader($payload, 'Cc');
            $subject = $this->mime->getHeader($payload, 'Subject') ?? '(no subject)';
            $body = $this->mime->extractTextBody($payload);
            $attachments = $this->mime->getAttachments($payload);

            // JSON output
            if ($this->shouldOutputJson()) {
                $analytics->track('gmail:drafts:get', self::SUCCESS, ['found' => true], $startTime);

                return $this->outputJson([
                    'draftId' => $draftId,
                    'messageId' => $message['id'] ?? '',
                    'to' => $to,
                    'cc' => $cc,
                    'subject' => $subject,
                    'body' => $body,
                    'attachments' => array_map(fn ($a) => [
                        'filename' => $a['filename'],
                        'mimeType' => $a['mimeType'],
                        'size' => $a['size'],
                    ], $attachments),
                ]);
            }

            // Text output
            $this->line("Draft-ID: {$draftId}");
            $this->line('Message-ID: '.($message['id'] ?? ''));
            $this->line("To: {$to}");
            if ($cc) {
                $this->line("Cc: {$cc}");
            }
            $this->line("Subject: {$subject}");
            $this->newLine();

            if ($body !== '') {
                $this->line($body);
            } else {
                $this->line('(no text/plain body)');
            }

            if (! empty($attachments)) {
                $this->newLine();
                $this->line('Attachments:');

                foreach ($attachments as $att) {
                    $size = $this->formatSize($att['size']);
                    $this->line("  - {$att['filename']} ({$att['mimeType']}, {$size})");

                    if ($download && $att['attachmentId']) {
                        $this->downloadAttachment($message['id'], $att);
                    }
                }
            }

            $analytics->track('gmail:drafts:get', self::SUCCESS, ['found' => true], $startTime);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $analytics->track('gmail:drafts:get', self::FAILURE, ['found' => false], $startTime);

            return $this->jsonError($e->getMessage());
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
        $paths = app(\App\Services\GmcliPaths::class);
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
