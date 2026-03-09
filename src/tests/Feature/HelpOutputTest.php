<?php

it('shows help with gmcli header', function () {
    $this->artisan('list')
        ->expectsOutputToContain('gmcli')
        ->assertSuccessful();
});

it('shows help usage section', function () {
    $this->artisan('list')
        ->expectsOutputToContain('USAGE:')
        ->assertSuccessful();
});

it('shows help account commands', function () {
    $this->artisan('list')
        ->expectsOutputToContain('accounts:add')
        ->assertSuccessful();
});

it('shows help gmail commands', function () {
    $this->artisan('list')
        ->expectsOutputToContain('gmail:search')
        ->assertSuccessful();
});

it('shows help filters commands', function () {
    $this->artisan('list')
        ->expectsOutputToContain('gmail:filters:list')
        ->assertSuccessful();
});

it('routes to accounts list command', function () {
    $this->artisan('accounts:list')
        ->assertSuccessful();
});
