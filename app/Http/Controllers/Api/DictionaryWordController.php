<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\DictionaryWordResource;
use App\Models\DictionaryExample;
use App\Models\DictionaryWord;
use App\Services\Dictionary\CharacterBreakdownService;
use Illuminate\Http\JsonResponse;

final class DictionaryWordController
{
    private const CACHE_SECONDS = 60 * 60 * 24;

    public function __invoke(DictionaryWord $word, CharacterBreakdownService $breakdown): JsonResponse
    {
        // Hằng số dùng chung với endpoint dịch câu ví dụ: hai con số lệch nhau
        // nghĩa là FE nhận bản dịch cho câu nó không hiện.
        $word->load(['examples' => fn ($query) => $query->limit(DictionaryExample::MAX_PER_WORD)]);

        $resource = new DictionaryWordResource($word, $breakdown->forWord($word));

        return response()
            ->json(['data' => $resource->resolve()])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
