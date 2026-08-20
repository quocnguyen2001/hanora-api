<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\HandwritingRequest;
use App\Services\Handwriting\HandwritingRecognizer;
use Illuminate\Http\JsonResponse;

final class HandwritingController
{
    public function __invoke(HandwritingRequest $request, HandwritingRecognizer $recognizer): JsonResponse
    {
        $candidates = $recognizer->recognise(
            $request->validated('strokes'),
            (int) $request->validated('width'),
            (int) $request->validated('height'),
        );

        return response()->json(['data' => $candidates])
            ->header('Cache-Control', 'private, no-store');
    }
}
