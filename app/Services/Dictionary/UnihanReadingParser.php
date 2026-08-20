<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use RuntimeException;

/**
 * Đọc trường `kVietnamese` của Unihan.
 *
 * Đây là nguồn PHỤ, không phải nguồn chính — xem `HanVietReadingTable` để biết
 * lý do. Unihan khóa theo ký tự, không theo cách đọc, nên nó không phân biệt
 * được 行 `hàng` với 行 `hành`.
 */
final class UnihanReadingParser
{
    /**
     * @return array<string, list<string>> ký tự => danh sách âm Hán-Việt
     */
    public function parse(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được Unihan: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được Unihan: {$path}");
        }

        $readings = [];

        try {
            while (($line = fgets($handle)) !== false) {
                if ($line === '' || $line[0] === '#' || ! str_contains($line, "\tkVietnamese\t")) {
                    continue;
                }

                [$codepoint, , $value] = explode("\t", rtrim($line, "\r\n"), 3);

                if (! str_starts_with($codepoint, 'U+')) {
                    continue;
                }

                $ordinal = (int) hexdec(substr($codepoint, 2));

                // Ngoài dải Unicode hợp lệ thì dòng đó hỏng: bỏ qua, thay vì
                // dựng ra một ký tự vô nghĩa rồi gắn âm cho nó.
                if ($ordinal <= 0 || $ordinal > 0x10FFFF) {
                    continue;
                }

                $character = mb_chr($ordinal, 'UTF-8');

                $syllables = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);

                if ($syllables !== false && $syllables !== []) {
                    $readings[$character] = $syllables;
                }
            }
        } finally {
            fclose($handle);
        }

        return $readings;
    }
}
