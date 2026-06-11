<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySlackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.slack.signing_secret');
        abort_if(blank($secret), 500, 'Slack signing secret is not configured.');

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');
        abort_if(blank($timestamp) || blank($signature), 401);
        abort_if(abs(now()->getTimestamp() - (int) $timestamp) > 300, 401);

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $secret);
        abort_unless(hash_equals($expected, $signature), 401);

        return $next($request);
    }
}
