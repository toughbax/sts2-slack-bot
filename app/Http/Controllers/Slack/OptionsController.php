<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\EntitySearchService;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptionsController extends Controller
{
    public function __invoke(
        Request $request,
        EntitySearchService $search,
        BlockKitFormatter $formatter,
    ): JsonResponse {
        $payload = json_decode((string) $request->input('payload'), true) ?? [];
        $query = (string) ($payload['value'] ?? '');

        return response()->json($formatter->options($search->search($query, 25)));
    }
}
