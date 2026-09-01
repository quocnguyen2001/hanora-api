<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\DictionaryCharacter;
use Generator;
use RuntimeException;

/**
 * Đọc `dictionary.txt` và `graphics.txt` của Make Me a Hanzi.
 *
 * Cả hai là NDJSON — một object JSON mỗi dòng — và cùng có 9.574 dòng. Đọc
 * STREAMING bằng generator, cùng lý do `CedictParser` đã ghi: `graphics.txt`
 * nặng 30 MB và import phải chạy được trên VPS nhỏ.
 *
 * KHÔNG ghép hai file trong bộ nhớ. Importer chạy HAI LƯỢT — hình học trước,
 * metadata sau — nên không lượt nào phải giữ 9.574 bản ghi cùng lúc, và cũng
 * không phụ thuộc vào việc hai file có cùng thứ tự dòng hay không.
 */
final class MakeMeAHanziParser
{
    /**
     * Nguồn dùng dấu hỏi TOÀN RỘNG cho phần nó không phân tích được — 66 chữ
     * trong `decomposition`. Để nguyên thì màn hình hiện "Hình thái: ？".
     */
    private const UNKNOWN = '？';

    /**
     * Metadata: bộ thủ, hình thái, lục thư.
     *
     * @return Generator<int, array{
     *     char: string,
     *     radical: string|null,
     *     decomposition: string|null,
     *     etymology_type: string|null
     * }>
     */
    public function dictionary(string $path): Generator
    {
        foreach ($this->lines($path) as $row) {
            $char = $this->char($row);

            if ($char === null) {
                continue;
            }

            $etymology = $row['etymology'] ?? null;
            $type = is_array($etymology) ? ($etymology['type'] ?? null) : null;

            yield [
                'char' => $char,
                'radical' => $this->text($row['radical'] ?? null),
                'decomposition' => $this->text($row['decomposition'] ?? null),
                /*
                 * Chỉ nhận ba loại đã biết. Nguồn đổi thêm loại mới thì nó rơi
                 * về `null` và FE ẩn dòng — tốt hơn là đẩy một chuỗi tiếng Anh
                 * lạ lên màn hình vì bảng ánh xạ không có nó.
                 */
                'etymology_type' => is_string($type)
                    && isset(DictionaryCharacter::ETYMOLOGY_LABELS[$type])
                        ? $type
                        : null,
            ];
        }
    }

    /**
     * Hình học nét, cho `hanzi-writer`.
     *
     * `stroke_count` suy từ `count($strokes)` chứ không đọc từ một trường riêng
     * — nguồn không có trường đó, và đếm chính mảng nét là định nghĩa đúng.
     *
     * @return Generator<int, array{
     *     char: string,
     *     stroke_count: int,
     *     strokes: list<string>,
     *     medians: list<mixed>
     * }>
     */
    public function graphics(string $path): Generator
    {
        foreach ($this->lines($path) as $row) {
            $char = $this->char($row);
            $strokes = $row['strokes'] ?? null;
            $medians = $row['medians'] ?? null;

            if ($char === null || ! is_array($strokes) || $strokes === [] || ! is_array($medians)) {
                continue;
            }

            yield [
                'char' => $char,
                'stroke_count' => count($strokes),
                'strokes' => array_values($strokes),
                'medians' => array_values($medians),
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function lines(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được file Hán tự: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được file Hán tự: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                // Một dòng hỏng chỉ được làm mất chính nó, không dừng cả lượt
                // import 9.574 chữ.
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function char(array $row): ?string
    {
        $char = $row['character'] ?? null;

        return is_string($char) && $char !== '' ? $char : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || $value === self::UNKNOWN ? null : $value;
    }
}
