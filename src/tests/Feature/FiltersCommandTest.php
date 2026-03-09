<?php

use App\Services\GmailClient;
use App\Services\GmailClientFactory;
use App\Services\GmcliEnv;
use App\Services\GmcliPaths;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/gmcli-filters-test-'.uniqid();
    mkdir($this->tempDir, 0700, true);

    $paths = new GmcliPaths($this->tempDir);
    $env = new GmcliEnv($paths);
    $env->set('GOOGLE_CLIENT_ID', 'client-id');
    $env->set('GOOGLE_CLIENT_SECRET', 'secret-key');
    $env->set('GMAIL_ADDRESS', 'test@gmail.com');
    $env->set('GMAIL_REFRESH_TOKEN', 'refresh-token');
    $env->save();

    app()->instance(GmcliPaths::class, $paths);
    app()->instance(GmcliEnv::class, $env);

    $this->fakeClient = new class extends GmailClient
    {
        public array $calls = [];

        private array $responses = [
            'GET' => [],
            'POST' => [],
            'DELETE' => [],
        ];

        private array $exceptions = [
            'GET' => [],
            'POST' => [],
            'DELETE' => [],
        ];

        public function __construct()
        {
            parent::__construct('client-id', 'secret-key', 'refresh-token');
        }

        public function queueResponse(string $method, string $endpoint, array $response): void
        {
            $this->responses[$method][$endpoint][] = $response;
        }

        public function queueException(string $method, string $endpoint, string $message): void
        {
            $this->exceptions[$method][$endpoint][] = new RuntimeException($message);
        }

        public function get(string $endpoint, array $params = []): array
        {
            $this->calls[] = ['method' => 'GET', 'endpoint' => $endpoint, 'params' => $params];

            return $this->next('GET', $endpoint);
        }

        public function post(string $endpoint, array $data = [], array $params = []): array
        {
            $this->calls[] = ['method' => 'POST', 'endpoint' => $endpoint, 'data' => $data, 'params' => $params];

            return $this->next('POST', $endpoint);
        }

        public function delete(string $endpoint, array $params = []): array
        {
            $this->calls[] = ['method' => 'DELETE', 'endpoint' => $endpoint, 'params' => $params];

            return $this->next('DELETE', $endpoint);
        }

        private function next(string $method, string $endpoint): array
        {
            if (! empty($this->exceptions[$method][$endpoint])) {
                throw array_shift($this->exceptions[$method][$endpoint]);
            }

            if (! empty($this->responses[$method][$endpoint])) {
                return array_shift($this->responses[$method][$endpoint]);
            }

            return [];
        }
    };

    app()->instance(GmailClientFactory::class, new class($this->fakeClient) extends GmailClientFactory
    {
        public function __construct(private GmailClient $client) {}

        public function make(string $clientId, string $clientSecret, string $refreshToken, ?\App\Services\GmailLogger $logger = null): GmailClient
        {
            return $this->client;
        }
    });
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($this->tempDir);
    }
});

it('lists filters in text output', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_1', 'name' => 'Infra'],
        ],
    ]);
    $this->fakeClient->queueResponse('GET', '/users/me/settings/filters', [
        'filter' => [
            [
                'id' => 'filter-1',
                'criteria' => ['from' => 'alert@ohdear.app'],
                'action' => ['addLabelIds' => ['Label_1'], 'removeLabelIds' => ['INBOX']],
            ],
        ],
    ]);

    $this->artisan('gmail:filters:list')
        ->expectsOutput("filter-1\tfrom:alert@ohdear.app\t+Infra, -INBOX")
        ->assertSuccessful();
});

it('lists filters in json output', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', ['labels' => []]);
    $this->fakeClient->queueResponse('GET', '/users/me/settings/filters', [
        'filter' => [
            ['id' => 'filter-1', 'criteria' => ['from' => 'nightwatch@laravel.com'], 'action' => []],
        ],
    ]);

    $this->artisan('gmail:filters:list', ['--json' => true])
        ->expectsOutputToContain('"id":"filter-1"')
        ->assertSuccessful();
});

it('creates a filter with convenience actions', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_1', 'name' => 'Infra'],
        ],
    ]);
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_1', 'name' => 'Infra'],
        ],
    ]);
    $this->fakeClient->queueResponse('POST', '/users/me/settings/filters', [
        'id' => 'filter-1',
        'criteria' => ['from' => 'alert@ohdear.app'],
        'action' => ['addLabelIds' => ['Label_1'], 'removeLabelIds' => ['INBOX', 'UNREAD']],
    ]);

    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
        '--add-label' => ['Infra'],
        '--skip-inbox' => true,
        '--mark-read' => true,
    ])
        ->expectsOutputToContain('Filter created: filter-1')
        ->assertSuccessful();

    $createCall = collect($this->fakeClient->calls)
        ->first(fn (array $call) => $call['method'] === 'POST' && $call['endpoint'] === '/users/me/settings/filters');

    expect($createCall['data'])->toBe([
        'criteria' => ['from' => 'alert@ohdear.app'],
        'action' => [
            'addLabelIds' => ['Label_1'],
            'removeLabelIds' => ['INBOX', 'UNREAD'],
        ],
    ]);
});

it('auto-creates missing add labels', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', ['labels' => []]);
    $this->fakeClient->queueResponse('POST', '/users/me/labels', [
        'id' => 'Label_99',
        'name' => 'Infra',
    ]);
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_99', 'name' => 'Infra'],
        ],
    ]);
    $this->fakeClient->queueResponse('POST', '/users/me/settings/filters', [
        'id' => 'filter-1',
    ]);

    $this->artisan('gmail:filters:create', [
        '--from' => 'no-reply@laravel.com',
        '--add-label' => ['Infra'],
    ])->assertSuccessful();

    $createLabelCall = collect($this->fakeClient->calls)
        ->first(fn (array $call) => $call['method'] === 'POST' && $call['endpoint'] === '/users/me/labels');

    expect($createLabelCall['data']['name'])->toBe('Infra');
});

it('fails when no criteria are provided', function () {
    $this->artisan('gmail:filters:create', [
        '--add-label' => ['Infra'],
    ])
        ->expectsOutputToContain('At least one filter criterion is required.')
        ->assertFailed();
});

it('fails when no action is provided', function () {
    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
    ])
        ->expectsOutputToContain('At least one filter action is required.')
        ->assertFailed();
});

it('fails when remove labels cannot be resolved', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_1', 'name' => 'Infra'],
        ],
    ]);
    $this->fakeClient->queueResponse('GET', '/users/me/labels', [
        'labels' => [
            ['id' => 'Label_1', 'name' => 'Infra'],
        ],
    ]);

    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
        '--remove-label' => ['Missing'],
    ])
        ->expectsOutputToContain('Unable to find label(s) to remove: Missing')
        ->assertFailed();
});

it('shows actionable reauth guidance for insufficient scope on create', function () {
    $this->fakeClient->queueResponse('GET', '/users/me/labels', ['labels' => []]);
    $this->fakeClient->queueResponse('GET', '/users/me/labels', ['labels' => []]);
    $this->fakeClient->queueException('POST', '/users/me/settings/filters', 'Gmail API error: Request had insufficient authentication scopes.');

    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
        '--skip-inbox' => true,
    ])
        ->expectsOutputToContain('Filter management requires renewed Gmail consent.')
        ->expectsOutputToContain('Run: gmcli accounts:remove test@gmail.com')
        ->expectsOutputToContain('Then: gmcli accounts:add test@gmail.com')
        ->assertFailed();
});

it('deletes a filter', function () {
    $this->fakeClient->queueResponse('DELETE', '/users/me/settings/filters/filter-1', []);

    $this->artisan('gmail:filters:delete', [
        '--filter-id' => 'filter-1',
    ])
        ->expectsOutputToContain('Filter deleted: filter-1')
        ->assertSuccessful();
});

it('shows actionable reauth guidance for insufficient scope on delete', function () {
    $this->fakeClient->queueException('DELETE', '/users/me/settings/filters/filter-1', 'Gmail API error: Request had insufficient authentication scopes.');

    $this->artisan('gmail:filters:delete', [
        '--filter-id' => 'filter-1',
    ])
        ->expectsOutputToContain('Filter management requires renewed Gmail consent.')
        ->assertFailed();
});

it('uses shared delete support for draft deletion', function () {
    $this->fakeClient->queueResponse('DELETE', '/users/me/drafts/draft-1', []);

    $this->artisan('gmail:drafts:delete', [
        '--draft-id' => 'draft-1',
    ])
        ->expectsOutputToContain('Draft deleted: draft-1')
        ->assertSuccessful();

    expect($this->fakeClient->calls)->toContain([
        'method' => 'DELETE',
        'endpoint' => '/users/me/drafts/draft-1',
        'params' => [],
    ]);
});
