<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DictionaryEnrichmentController;
use App\Http\Controllers\Api\DictionaryIllustrationController;
use App\Http\Controllers\Api\DictionarySearchController;
use App\Http\Controllers\Api\DictionarySentenceController;
use App\Http\Controllers\Api\DictionaryWordController;
use App\Http\Controllers\Api\HandwritingController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ReviewHistoryController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\VocabularyController;
use Illuminate\Support\Facades\Route;

/*
 * D8: mọi endpoint nằm sau `auth:sanctum`, trừ health check và bốn endpoint
 * auth công khai dưới đây. Không có chế độ khách — kể cả tra từ điển.
 *
 * `RouteSurfaceTest` khóa danh sách công khai này lại. Thêm route công khai
 * mới là phải sửa test, tức là phải có chủ đích.
 */
Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('auth')->name('api.auth.')->group(function (): void {
    // Throttle riêng, chặt hơn trần mặc định 60/phút: đây là các endpoint
    // brute-force nhắm tới.
    //
    // Dùng limiter CÓ TÊN (định nghĩa ở AppServiceProvider), không dùng
    // `throttle:5,1` inline: throttle inline sẽ dùng chung khóa cache với
    // `throttle:60,1` của nhóm `api` và mỗi request bị đếm hai lần.
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('login');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-forgot-password')
        ->name('forgot-password');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-reset-password')
        ->name('reset-password');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');

    // D8: từ điển cũng nằm sau auth. Không có chế độ khách.
    Route::prefix('dictionary')->name('api.dictionary.')->group(function (): void {
        Route::get('/search', DictionarySearchController::class)->name('search');

        /*
         * Chi tiết một CÂU. Khoá là chuỗi Hán trong query string, không phải id
         * trên path: câu không phải mục từ điển nên không có id để đặt vào path.
         *
         * Nằm sau `auth:sanctum` như mọi endpoint từ điển khác, và ở đây điều đó
         * quan trọng hơn: mỗi câu mới tiêu một lời gọi Gemini.
         */
        Route::get('/sentences', DictionarySentenceController::class)->name('sentence');
        Route::get('/words/{word}', DictionaryWordController::class)->name('word');

        /*
         * Nội dung do AI sinh, gọi ASYNC sau khi màn chi tiết đã render.
         *
         * Nằm sau `auth:sanctum` như mọi endpoint từ điển khác — nó xếp job vào
         * hàng đợi và tiêu quota, nên để công khai là mời người lạ đốt tiền.
         */
        Route::get('/words/{word}/enrichment', DictionaryEnrichmentController::class)
            ->name('word.enrichment');

        /*
         * Ảnh minh hoạ, cũng gọi ASYNC sau khi màn chi tiết đã render.
         *
         * Nằm sau `auth:sanctum` như mọi endpoint từ điển khác — nó xếp job vào
         * hàng đợi và tiêu trần 100 request/phút của Pixabay, nên để công khai
         * là mời người lạ đốt hạn mức.
         */
        Route::get('/words/{word}/illustration', DictionaryIllustrationController::class)
            ->name('word.illustration');
    });

    Route::prefix('vocabulary')->name('api.vocabulary.')->group(function (): void {
        Route::get('/', [VocabularyController::class, 'index'])->name('index');
        // Đặt TRƯỚC `/{id}` — nếu không `ids` sẽ bị bắt như một id.
        Route::get('/ids', [VocabularyController::class, 'ids'])->name('ids');
        Route::post('/', [VocabularyController::class, 'store'])->name('store');
        Route::delete('/{id}', [VocabularyController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
    });

    Route::get('/stats/summary', StatsController::class)->name('api.stats.summary');

    /*
     * Proxy nhận dạng chữ viết tay (P19).
     *
     * Cần proxy vì CORS, và tiện thể giữ được throttle theo user — endpoint này
     * chuyển tiếp ra dịch vụ bên thứ ba nên không được để ai gọi tự do.
     */
    Route::post('/handwriting/recognize', HandwritingController::class)
        ->middleware('throttle:handwriting')
        ->name('api.handwriting.recognize');

    Route::prefix('reviews')->name('api.reviews.')->group(function (): void {
        /*
         * POST, không GET: mở phiên GHI một dòng vào `review_sessions`. Một
         * `GET` ghi dữ liệu là lời mời cho prefetch của trình duyệt và service
         * worker tạo phiên ma.
         */
        Route::post('/sessions', [ReviewController::class, 'start'])
            ->middleware('throttle:review-sessions')
            ->name('sessions.store');

        Route::post('/sessions/{id}/finish', [ReviewController::class, 'finish'])
            ->whereNumber('id')
            ->name('sessions.finish');

        // Limiter CÓ TÊN, không phải throttle inline: throttle inline dùng
        // chung khóa cache với `throttle:60,1` của nhóm api và bị đếm hai lần.
        Route::post('/answers', [ReviewController::class, 'answer'])
            ->middleware('throttle:review-answers')
            ->name('answers');

        /*
         * Phần CHỈ ĐỌC. Đặt `/sessions` (index) và `/weak-words` TRƯỚC
         * `/sessions/{id}` theo đúng thói quen đã dùng cho `vocabulary/ids`:
         * không thì `weak-words` bị bắt như một id.
         */
        Route::get('/sessions', [ReviewHistoryController::class, 'sessions'])
            ->name('sessions.index');

        Route::get('/weak-words', [ReviewHistoryController::class, 'weakWords'])
            ->name('weak-words');

        Route::get('/words/{word}/history', [ReviewHistoryController::class, 'wordHistory'])
            ->name('words.history');

        Route::get('/sessions/{id}', [ReviewHistoryController::class, 'session'])
            ->whereNumber('id')
            ->name('sessions.show');
    });
});
