<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use Generator;
use RuntimeException;

/**
 * Đọc `cedict_ts.u8` thành từng bản ghi.
 *
 * Đọc STREAMING bằng generator, không load cả file vào RAM: nguồn có ~125k
 * dòng và ~200k nghĩa, và import phải chạy được trên VPS nhỏ.
 */
final class CedictParser
{
    /**
     * Một dòng CC-CEDICT:
     *
     *   學習 学习 [xue2 xi2] /to learn/to study/
     *   phồn thể · giản thể · [pinyin số] · /nghĩa/nghĩa/
     */
    private const LINE_PATTERN = '/^(\S+)\s+(\S+)\s+\[([^\]]*)\]\s+\/(.*)\/\s*$/u';

    public function __construct(private readonly PinyinNormalizer $pinyin) {}

    /**
     * @return Generator<int, array{
     *     simplified: string,
     *     traditional: string,
     *     pinyin: string,
     *     pinyin_numbered: string,
     *     pinyin_plain: string,
     *     definitions_en: list<string>,
     *     definitions_en_text: string,
     *     char_count: int,
     *     is_single_char: bool
     * }>
     */
    public function parse(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được file từ điển: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được file từ điển: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $entry = $this->parseLine($line);

                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Tìm những khóa tự nhiên xuất hiện nhiều hơn một lần trong nguồn.
     *
     * CC-CEDICT có ~1.054 khóa như vậy: cùng chữ, cùng âm, nhưng tách thành
     * nhiều mục riêng (thường là nghĩa cổ, họ người, biến thể). Chúng KHÔNG nằm
     * cạnh nhau — đo được khoảng cách tới 122.938 dòng — nên cửa sổ trượt không
     * gộp được, phải biết trước danh sách.
     *
     * Pass này chỉ giữ chuỗi khóa chứ không giữ bản ghi, nên vẫn nhẹ RAM.
     *
     * @return array<string, true>
     */
    public function duplicateKeys(string $path): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->parse($path) as $entry) {
            $key = $this->naturalKey($entry['simplified'], $entry['pinyin_numbered']);

            if (isset($seen[$key])) {
                $duplicates[$key] = true;

                continue;
            }

            $seen[$key] = true;
        }

        return $duplicates;
    }

    public function naturalKey(string $simplified, string $pinyinNumbered): string
    {
        return $simplified."\0".$pinyinNumbered;
    }

    /**
     * @return array<string, mixed>|null null cho comment, dòng trống, dòng hỏng
     */
    public function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");

        // Header của CC-CEDICT là các dòng bắt đầu bằng `#`.
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (preg_match(self::LINE_PATTERN, $line, $matches) !== 1) {
            return null;
        }

        [, $traditional, $simplified, $pinyinNumbered, $body] = $matches;

        $definitions = $this->splitDefinitions($body);

        if ($definitions === []) {
            return null;
        }

        // Dạng có dấu là nguồn để sinh dạng trần: `v` chỉ được hiểu là `ü` ở
        // bước âm tiết, nơi ngữ cảnh chắc chắn là pinyin.
        $toneMarked = $this->pinyin->toneMarked($pinyinNumbered);

        $charCount = mb_strlen($simplified);

        return [
            'simplified' => $simplified,
            'traditional' => $traditional,
            'pinyin' => $toneMarked,
            'pinyin_numbered' => $pinyinNumbered,
            'pinyin_plain' => $this->pinyin->plain($toneMarked),
            'definitions_en' => $definitions,
            'definitions_en_text' => implode('; ', $definitions),
            'char_count' => $charCount,
            'is_single_char' => $charCount === 1,
        ];
    }

    /**
     * Nghĩa ngăn nhau bằng `/`.
     *
     * Tách thẳng trên `/` là an toàn: đã đo trên toàn bộ nguồn — 199.610 nghĩa,
     * không nghĩa nào chứa dấu `/` trần (CC-CEDICT viết "or" thay vì "and/or").
     * Những nghĩa ngắn 2 ký tự còn lại đều là từ tiếng Anh thật: "if", "so".
     *
     * @return list<string>
     */
    private function splitDefinitions(string $body): array
    {
        $senses = array_map(trim(...), explode('/', $body));

        return array_values(array_filter($senses, fn (string $s): bool => $s !== ''));
    }
}
