<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait SignsSlackRequests
{
    protected function postSlack(string $uri, array $data, array $headerOverrides = []): TestResponse
    {
        $body = http_build_query($data);
        $timestamp = $headerOverrides['timestamp'] ?? (string) now()->getTimestamp();
        $signature = $headerOverrides['signature']
            ?? 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", config('services.slack.signing_secret'));

        return $this->call('POST', $uri, $data, [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }
}
