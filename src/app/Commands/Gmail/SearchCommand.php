<?php

namespace App\Commands\Gmail;

use App\Services\MimeHelper;

/**
 * Searches Gmail threads using query syntax.
 *
 * Returns: thread ID, date, sender, subject, labels.
 */
class SearchCommand extends BaseGmailCommand
{
    protected $signature = 'gmail:search
        {query : Search query (Gmail search syntax)}
        {--max=20 : Maximum results}
        {--page= : Page token for pagination}';

    protected $description = 'Search threads using Gmail query syntax';

    private MimeHelper $mime;

    private array $labelsMap = [];

    private array $results = [];

    public function handle(): int
    {
        if ($failure = $this->initGmail()) {
            return $failure;
        }

        $query = $this->argument('query');
        $max = (int) $this->option('max');
        $pageToken = $this->option('page');

        $this->mime = new MimeHelper;
        $this->loadLabelsMap();

        $params = [
            'q' => $query,
            'maxResults' => min($max, 500),
        ];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $this->logger->verbose("Searching: {$query}");

        $response = $this->gmail->get('/users/me/threads', $params);
        $threads = $response['threads'] ?? [];

        if (empty($threads)) {
            if ($this->wantsJson()) {
                return $this->outputJson([]);
            }

            $this->info('No threads found.');

            return self::SUCCESS;
        }

        foreach ($threads as $thread) {
            $this->collectThread($thread['id']);
        }

        if ($this->wantsJson()) {
            $output = ['threads' => $this->results];
            if (isset($response['nextPageToken'])) {
                $output['nextPageToken'] = $response['nextPageToken'];
            }

            return $this->outputJson($output);
        }

        foreach ($this->results as $result) {
            $labels = $result['labels'] ? '['.implode(', ', $result['labels']).']' : '';
            $this->line("{$result['threadId']}\t{$result['date']}\t{$result['from']}\t{$result['subject']}\t{$labels}");
        }

        if (isset($response['nextPageToken'])) {
            $this->newLine();
            $this->line("Next page: --page {$response['nextPageToken']}");
        }

        return self::SUCCESS;
    }

    private function loadLabelsMap(): void
    {
        $response = $this->gmail->get('/users/me/labels');
        $labels = $response['labels'] ?? [];

        foreach ($labels as $label) {
            $this->labelsMap[$label['id']] = $label['name'];
        }
    }

    private function collectThread(string $threadId): void
    {
        $thread = $this->gmail->get("/users/me/threads/{$threadId}", [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Date'],
        ]);

        $messages = $thread['messages'] ?? [];
        if (empty($messages)) {
            return;
        }

        $firstMessage = $messages[0];
        $payload = $firstMessage['payload'] ?? [];

        $date = $this->formatDate($this->mime->getHeader($payload, 'Date') ?? '');
        $from = $this->formatSender($this->mime->getHeader($payload, 'From') ?? '');
        $subject = $this->mime->getHeader($payload, 'Subject') ?? '(no subject)';

        $labelIds = $firstMessage['labelIds'] ?? [];
        $labelNames = [];
        foreach ($labelIds as $id) {
            $labelNames[] = $this->labelsMap[$id] ?? $id;
        }

        $this->results[] = [
            'threadId' => $threadId,
            'date' => $date,
            'from' => $from,
            'subject' => $subject,
            'labels' => $labelNames,
        ];
    }

    private function formatDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return (new \DateTime($date))->format('Y-m-d H:i');
        } catch (\Exception) {
            return substr($date, 0, 16);
        }
    }

    private function formatSender(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }

        return $from;
    }
}
