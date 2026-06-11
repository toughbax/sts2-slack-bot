<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\EntitySearchService;
use App\Slack\BlockKitFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandController extends Controller
{
    public function __invoke(
        Request $request,
        EntitySearchService $search,
        BlockKitFormatter $formatter,
    ): JsonResponse {
        $text = trim((string) $request->input('text', ''));

        if ($text === '') {
            return response()->json($formatter->selectPrompt());
        }

        $results = $search->search($text, 5);

        if ($results->isEmpty()) {
            return response()->json($formatter->noResults($text));
        }

        return response()->json($formatter->searchResponse($results->first(), $results->slice(1)));
    }
}
