<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\DictionaryWordResource;
use App\Models\DictionaryWord;
use App\Services\Dictionary\CharacterBreakdownService;
use Illuminate\Http\JsonResponse;

final class DictionaryWordController
{
    private const CACHE_SECONDS = 60 * 60 * 24;

    public function __invoke(DictionaryWord $word, CharacterBreakdownService $breakdown): JsonResponse
    {
        // Tối đa 3 câu — đủ để thấy ngữ cảnh, không biến màn chi tiết thành một
        // bức tường chữ.
        $word->load(['examples' => fn ($query) => $query->limit(3)]);

        $resource = new DictionaryWordResource($word, $breakdown->forWord($word));

        return response()
            ->json(['data' => $resource->resolve()])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
