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

        if (! $responseUrl) {
            return response()->noContent();
        }

        // Share-to-channel branch
        if (str_starts_with($action['action_id'] ?? '', 'sts_share_button_')) {
            $entityId = $action['value'] ?? null;
            $userId = $payload['user']['id'] ?? null;
            $entity = $entityId !== null ? Entity::find($entityId) : null;

            if (! $entity) {
                $this->deliver($responseUrl, [
                    'response_type' => 'ephemeral',
                    'replace_original' => true,
                    'text' => 'That entity no longer exists. Try /sts again.',
                ]);

                return response()->noContent();
            }

            $this->deliver($responseUrl, $formatter->sharedCard($entity, $userId));
            $this->deliver($responseUrl, ['delete_original' => true]);

            return response()->noContent();
        }

        // Select / did-you-mean branch
        $entityId = $action['selected_option']['value'] ?? $action['value'] ?? null;

        if ($entityId === null) {
            return response()->noContent();
        }

        $entity = Entity::find($entityId);

        $message = $entity
            ? $formatter->ephemeralCard($entity)
            : [
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => 'That entity no longer exists. Try /sts again.',
            ];

        $this->deliver($responseUrl, $message);

        return response()->noContent();
    }

    private function deliver(string $url, array $message): void
    {
        $response = Http::post($url, $message);

        if ($response->failed()) {
            logger()->warning('Slack response_url delivery failed.', ['status' => $response->status()]);
        }
    }
}
