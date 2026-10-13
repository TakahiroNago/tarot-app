<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('unverified users are redirected to the verification notice', function (string $uri) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get($uri)->assertRedirect(route('verification.notice'));
})->with(['/', '/zen', '/plans']);

test('unverified users can see the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

test('verified users and guests can open public pages', function () {
    $this->get('/zen')->assertOk();
    $this->actingAs(User::factory()->create())->get('/plans')->assertOk();
});
