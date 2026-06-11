<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class InteractionsController extends Controller
{
    public function __invoke(Request $request, BlockKitFormatter $formatter): Response
    {
        $payload = json_decode((string) $request->input('payload'), true) ?? [];
        $action = $payload['actions'][0] ?? [];
        $responseUrl = $payload['response_url'] ?? null;

        $entityId = $action['selected_option']['value'] ?? $action['value'] ?? null;

        if (! $responseUrl || $entityId === null) {
            return response()->noContent();
        }

        $entity = Entity::find($entityId);

        $message = $entity
            ? [
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => $entity->name,
                'blocks' => $formatter->entityCard($entity),
            ]
            : [
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => 'That entity no longer exists. Try /sts again.',
            ];

        Http::post($responseUrl, $message);

        return response()->noContent();
    }
}
