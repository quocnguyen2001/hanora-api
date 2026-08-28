<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Search;

/**
 * Quyết định xem kết quả SQL đã đủ mạnh chưa, hay cần hỏi AI.
 *
 * Hàm thuần, không chạm database. Nó là toàn bộ chỗ mà "gọi AI hay không" được
 * quyết, nên đặt riêng để test bằng bảng thay vì qua HTTP.
 *
 * Luật đến từ số đo trên DB thật 123.646 dòng, `mode=vi`, ngày 2026-08-28:
 *
 * | truy vấn          | rank | prec | total | SQL trả          | đúng? |
 * |-------------------|------|------|-------|------------------|-------|
 * | xin chào          |   6  |   0  |     7 | 你好 您好         |  có   |
 * | cảm ơn            |   6  |   0  |    74 | 谢谢 谢           |  có   |
 * | con mèo           |   6  |   1  |    73 | 猫 咪             |  có   |
 * | anh yêu em        |   6  |   2  |     3 | 博爱 基情         | KHÔNG |
 * | học sinh          |   5  |   6  |   317 | 学生              |  có   |
 * | bác sĩ            |   5  |   6  |    99 | 博士              | KHÔNG |
 * | yêu               |   5  |   6  |   831 | 要 要求           | KHÔNG |
 * | tôi muốn ăn cơm   |   -  |   -  |     0 | (rỗng)            | KHÔNG |
 * | bệnh viện ở đâu   |   -  |   -  |     0 | (rỗng)            | KHÔNG |
 */
final class SearchWeakness
{
    /**
     * Ranh giới của bằng chứng mạnh trong nhánh nghĩa tiếng Việt.
     *
     * Bậc 0 và 1 là khớp gloss CHÍNH XÁC — cả ba ca đúng ở bảng trên đều nằm ở
     * đây. Bậc 2 là khớp một phần, và `anh yêu em` (bậc 2, ra 博爱) là lý do
     * ranh giới đặt ở 1 chứ không phải 2.
     */
    private const STRONG_VI_PRECISION = 1;

    /** Rank 1-4 là khớp chữ Hán hoặc pinyin. Không mơ hồ, AI không thêm được gì. */
    private const STRONG_RANK_CEILING = 4;

    private const RANK_VI_MEANING = 6;

    public static function isWeak(?int $rank, ?int $precision, int $total): bool
    {
        // Không có gì để trả thì không có gì để mất khi hỏi AI.
        if ($total === 0) {
            return true;
        }

        if ($rank !== null && $rank <= self::STRONG_RANK_CEILING) {
            return false;
        }

        if ($rank === self::RANK_VI_MEANING
            && $precision !== null
            && $precision <= self::STRONG_VI_PRECISION) {
            return false;
        }

        /*
         * Còn lại chủ yếu là rank 5 — nhánh âm Hán-Việt, nơi `bác sĩ` ra 博士
         * (tiến sĩ) và `yêu` ra 要.
         *
         * Đã đo trên ~1.452 truy vấn thuộc lớp này rằng KHÔNG có tín hiệu cấu
         * trúc nào tách được "muốn nghĩa" khỏi "muốn âm Hán-Việt": mọi luật đưa
         * 爱 lên trước 要 cũng đưa 馅 lên trước 人. AI chính là tín hiệu phi cấu
         * trúc đó, nên gọi AI ở đây không phải lãng phí — nó là lý do lớp này
         * tồn tại.
         *
         * Cái giá: `học sinh`, `điện thoại`, `học tập` đang đúng ở rank 5 vẫn bị
         * hỏi lần đầu. AI cũng trả đúng những từ đó, và sau lần đầu chúng nằm
         * trong cache.
         */
        return true;
    }
}
