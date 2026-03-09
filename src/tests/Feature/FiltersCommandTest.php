<?php

use App\Services\GmcliEnv;
use App\Services\GmcliPaths;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

    Http::preventStrayRequests();
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

function fakeGoogleHttp(array $responses = []): void
{
    Http::fake(array_merge([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ], 200),
    ], $responses));
}

it('lists filters in text output', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response([
            'labels' => [
                ['id' => 'Label_1', 'name' => 'Infra'],
            ],
        ], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response([
            'filter' => [
                [
                    'id' => 'filter-1',
                    'criteria' => ['from' => 'alert@ohdear.app'],
                    'action' => ['addLabelIds' => ['Label_1'], 'removeLabelIds' => ['INBOX']],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('gmail:filters:list')
        ->expectsOutput("filter-1\tfrom:alert@ohdear.app\t+Infra, -INBOX")
        ->assertSuccessful();
});

it('lists filters in json output', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response(['labels' => []], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response([
            'filter' => [
                ['id' => 'filter-1', 'criteria' => ['from' => 'nightwatch@laravel.com'], 'action' => []],
            ],
        ], 200),
    ]);

    $this->artisan('gmail:filters:list', ['--json' => true])
        ->expectsOutputToContain('"id":"filter-1"')
        ->assertSuccessful();
});

it('creates a filter with convenience actions', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::sequence()
            ->push([
                'labels' => [
                    ['id' => 'Label_1', 'name' => 'Infra'],
                ],
            ], 200)
            ->push([
                'labels' => [
                    ['id' => 'Label_1', 'name' => 'Infra'],
                ],
            ], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response([
            'id' => 'filter-1',
            'criteria' => ['from' => 'alert@ohdear.app'],
            'action' => ['addLabelIds' => ['Label_1'], 'removeLabelIds' => ['INBOX', 'UNREAD']],
        ], 200),
    ]);

    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
        '--add-label' => ['Infra'],
        '--skip-inbox' => true,
        '--mark-read' => true,
    ])
        ->expectsOutputToContain('Filter created: filter-1')
        ->assertSuccessful();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters'
            && $request->data() === [
                'criteria' => ['from' => 'alert@ohdear.app'],
                'action' => [
                    'addLabelIds' => ['Label_1'],
                    'removeLabelIds' => ['INBOX', 'UNREAD'],
                ],
            ];
    });
});

it('auto-creates missing add labels', function () {
    $labelsGetRequestCount = 0;

    Http::fake(function (Request $request) use (&$labelsGetRequestCount) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response([
                'access_token' => 'access-token',
                'expires_in' => 3600,
            ], 200);
        }

        if ($request->method() === 'GET' && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/labels') {
            $labelsGetRequestCount++;

            if ($labelsGetRequestCount === 1) {
                return Http::response(['labels' => []], 200);
            }

            return Http::response([
                'labels' => [
                    ['id' => 'Label_99', 'name' => 'Infra'],
                ],
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/labels') {
            return Http::response([
                'id' => 'Label_99',
                'name' => 'Infra',
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters') {
            return Http::response([
                'id' => 'filter-1',
            ], 200);
        }

        return Http::response([], 500);
    });

    $this->artisan('gmail:filters:create', [
        '--from' => 'no-reply@laravel.com',
        '--add-label' => ['Infra'],
    ])->assertSuccessful();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/labels'
            && $request->data()['name'] === 'Infra';
    });
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
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::sequence()
            ->push([
                'labels' => [
                    ['id' => 'Label_1', 'name' => 'Infra'],
                ],
            ], 200)
            ->push([
                'labels' => [
                    ['id' => 'Label_1', 'name' => 'Infra'],
                ],
            ], 200),
    ]);

    $this->artisan('gmail:filters:create', [
        '--from' => 'alert@ohdear.app',
        '--remove-label' => ['Missing'],
    ])
        ->expectsOutputToContain('Unable to find label(s) to remove: Missing')
        ->assertFailed();

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters');
});

it('shows actionable reauth guidance for insufficient scope on create', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::sequence()
            ->push(['labels' => []], 200)
            ->push(['labels' => []], 200),
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters' => Http::response([
            'error' => [
                'message' => 'Request had insufficient authentication scopes.',
            ],
        ], 403),
    ]);

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
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters/filter-1' => Http::response([], 200),
    ]);

    $this->artisan('gmail:filters:delete', [
        '--filter-id' => 'filter-1',
    ])
        ->expectsOutputToContain('Filter deleted: filter-1')
        ->assertSuccessful();
});

it('shows actionable reauth guidance for insufficient scope on delete', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/settings/filters/filter-1' => Http::response([
            'error' => [
                'message' => 'Request had insufficient authentication scopes.',
            ],
        ], 403),
    ]);

    $this->artisan('gmail:filters:delete', [
        '--filter-id' => 'filter-1',
    ])
        ->expectsOutputToContain('Filter management requires renewed Gmail consent.')
        ->assertFailed();
});

it('uses shared delete support for draft deletion', function () {
    fakeGoogleHttp([
        'https://gmail.googleapis.com/gmail/v1/users/me/drafts/draft-1' => Http::response([], 200),
    ]);

    $this->artisan('gmail:drafts:delete', [
        '--draft-id' => 'draft-1',
    ])
        ->expectsOutputToContain('Draft deleted: draft-1')
        ->assertSuccessful();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/drafts/draft-1';
    });
});
