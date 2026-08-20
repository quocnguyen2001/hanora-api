<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Bộ đếm throttle sống trong cache, và driver `array` giữ nguyên trạng
        // thái suốt cả process test. Không dọn thì một test chạm trần rate
        // limit sẽ làm mọi test sau nó nhận 429 — lỗi trông như của test khác,
        // ở file khác.
        Cache::flush();
    })
    ->in('Feature');
