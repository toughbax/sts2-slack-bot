<?php

use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

beforeEach(function () {
    Route::post('test-slack', fn () => response()->json(['ok' => true]))
        ->middleware(VerifySlackSignature::class);
});

it('accepts a correctly signed request', function () {
    $this->postSlack('test-slack', ['text' => 'hi'])->assertSuccessful();
});

it('rejects a bad signature', function () {
    $this->postSlack('test-slack', ['text' => 'hi'], ['signature' => 'v0=deadbeef'])
        ->assertUnauthorized();
});

it('rejects a stale timestamp', function () {
    $stale = (string) now()->subMinutes(10)->getTimestamp();
    $body = http_build_query(['text' => 'hi']);
    $signature = 'v0='.hash_hmac('sha256', "v0:{$stale}:{$body}", config('services.slack.signing_secret'));

    $this->postSlack('test-slack', ['text' => 'hi'], ['timestamp' => $stale, 'signature' => $signature])
        ->assertUnauthorized();
});

it('rejects missing headers', function () {
    $this->post('test-slack', ['text' => 'hi'])->assertUnauthorized();
});
