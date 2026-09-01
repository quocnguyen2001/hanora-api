<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use Generator;
use RuntimeException;

/**
 * Đọc `cedict_ts.u8` (và `cvdict.u8`, cùng định dạng) thành từng bản ghi.
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

    /**
     * Khối lượng từ trong phần nghĩa: `CL:` rồi một danh sách ngăn bằng dấu phẩy.
     *
     * Neo CHẶT vào hình dạng `chữ[pinyin số]` chứ không chỉ vào hai chữ `CL:`.
     * Một nghĩa chứa "CL" ở ngữ cảnh khác — tên viết tắt chẳng hạn — không có
     * ngoặc vuông theo sau nên không khớp, và test có một ca âm tính khoá điều đó.
     *
     * `[^\[\]]+` dừng trước `[`, nên nó không nuốt qua ngoặc đơn đóng của dạng
     * nhúng: `cat (CL:隻|只[zhi1])` khớp đúng `CL:隻|只[zhi1]` và chừa lại `)`.
     */
    private const MEASURE_WORD_PATTERN = '/CL:([^\[\]]+\[[^\]]*\](?:,[^\[\]]+\[[^\]]*\])*)/u';

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
     * Đọc `cvdict.u8` — cùng định dạng, nghĩa bằng tiếng Việt.
     *
     * CVDICT sinh ra TỪ CHÍNH CC-CEDICT nên định dạng dòng giống hệt: đo được
     * regex ở trên parse 122.596/122.597 mục. Nên đây là một method chứ không
     * phải một parser thứ hai — một parser nữa là một regex nữa để đồng bộ.
     *
     * Chỉ phát khóa tự nhiên và nghĩa: pinyin có dấu, `char_count`, dạng trần
     * đều do `dictionary:import` sở hữu, và import nghĩa Việt KHÔNG được đụng
     * vào chúng.
     *
     * @return Generator<int, array{
     *     simplified: string,
     *     pinyin_numbered: string,
     *     definitions_vi: list<string>,
     *     definitions_vi_text: string
     * }>
     */
    public function parseVietnamese(string $path): Generator
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
                $entry = $this->parseVietnameseLine($line);

                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{
     *     simplified: string,
     *     pinyin_numbered: string,
     *     definitions_vi: list<string>,
     *     definitions_vi_text: string
     * }|null null cho comment, dòng trống, dòng hỏng
     */
    public function parseVietnameseLine(string $line): ?array
    {
        $matches = $this->matchLine($line);

        if ($matches === null) {
            return null;
        }

        [, , $simplified, $pinyinNumbered, $body] = $matches;

        /*
         * CVDICT sinh ra TỪ CC-CEDICT nên nó mang theo cả mã `CL:`. Dọn ở đây
         * cũng vì lý do đã dọn bên `parseLine`: chuỗi đó hiện nguyên dạng mã cho
         * người dùng, và `definitions_vi` được hiển thị y như `definitions_en`.
         *
         * Nhưng **vứt bỏ** phần lượng từ vừa rút, không trả về. Cột
         * `measure_words` thuộc sở hữu của `dictionary:import`; `cvdict:import`
         * chỉ được đụng vào nghĩa tiếng Việt — cùng ranh giới mà pinyin và
         * `char_count` đang giữ.
         */
        $definitions = $this->extractMeasureWords($this->splitDefinitions($body))['definitions'];

        if ($definitions === []) {
            return null;
        }

        return [
            'simplified' => $simplified,
            'pinyin_numbered' => $pinyinNumbered,
            'definitions_vi' => $definitions,
            'definitions_vi_text' => implode('; ', $definitions),
        ];
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
        $matches = $this->matchLine($line);

        if ($matches === null) {
            return null;
        }

        [, $traditional, $simplified, $pinyinNumbered, $body] = $matches;

        /*
         * Rút lượng từ TRƯỚC khi kiểm rỗng: một mục mà mọi nghĩa đều là `CL:`
         * thì sau khi rút không còn nghĩa nào để dạy, và nó phải bị bỏ như mọi
         * mục rỗng khác thay vì đi vào bảng với `definitions_en: []`.
         */
        ['definitions' => $definitions, 'measure_words' => $measureWords]
            = $this->extractMeasureWords($this->splitDefinitions($body));

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
            // Dựng TỪ danh sách đã dọn, không phải từ `$body` gốc — nếu không
            // thì vector tìm kiếm sinh từ cột này vẫn chứa `CL:`.
            'definitions_en_text' => implode('; ', $definitions),
            /*
             * `null` khi không có, KHÔNG phải mảng rỗng: cột là `jsonb` nullable
             * và `null` đọc ra là "từ này không có lượng từ" — trạng thái hợp lệ
             * và thường gặp, cùng quy ước `definitions_vi` đang giữ.
             */
            'measure_words' => $measureWords === [] ? null : $measureWords,
            'char_count' => $charCount,
            'is_single_char' => $charCount === 1,
        ];
    }

    /**
     * Tách một dòng thành `[toàn dòng, phồn thể, giản thể, pinyin số, thân]`.
     *
     * @return list<string>|null null cho comment, dòng trống, dòng hỏng
     */
    private function matchLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");

        // Header của CC-CEDICT (và CVDICT) là các dòng bắt đầu bằng `#`.
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (preg_match(self::LINE_PATTERN, $line, $matches) !== 1) {
            return null;
        }

        return $matches;
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

    /**
     * Rút lượng từ ra khỏi danh sách nghĩa.
     *
     * CC-CEDICT mã hoá lượng từ bằng `CL:` NGAY TRONG phần nghĩa, ở hai dạng —
     * cả hai đều có thật trong nguồn:
     *
     *     銀行 银行 [yin2 hang2] /bank/CL:家[jia1],個|个[ge4]/    ← nghĩa ĐỘC LẬP
     *     貓  猫  [mao1]        /cat (CL:隻|只[zhi1])/          ← NHÚNG trong nghĩa
     *
     * Không rút ra thì chuỗi đó hiện nguyên dạng mã cho người dùng, ở cả thẻ
     * tìm kiếm lẫn màn chi tiết — và nó cũng lọt vào vector tìm kiếm sinh từ
     * `definitions_en_text`, tức gõ "cl" ra kết quả rác.
     *
     * @param  list<string>  $definitions
     * @return array{
     *     definitions: list<string>,
     *     measure_words: list<array{simplified: string, traditional: string, pinyin: string}>
     * }
     */
    public function extractMeasureWords(array $definitions): array
    {
        $measureWords = [];
        $cleaned = [];

        foreach ($definitions as $definition) {
            $rest = preg_replace_callback(
                self::MEASURE_WORD_PATTERN,
                function (array $match) use (&$measureWords): string {
                    foreach (explode(',', $match[1]) as $item) {
                        $parsed = $this->parseMeasureWord(trim($item));

                        if ($parsed !== null) {
                            $measureWords[] = $parsed;
                        }
                    }

                    return '';
                },
                $definition,
            );

            if ($rest === null) {
                // `preg_replace_callback` chỉ trả `null` khi chính regex hỏng.
                // Giữ nguyên nghĩa gốc thay vì làm mất nó.
                $cleaned[] = $definition;

                continue;
            }

            $rest = $this->tidyAfterExtraction($rest);

            if ($rest !== '') {
                $cleaned[] = $rest;
            }
        }

        return [
            'definitions' => $cleaned,
            /*
             * Khử trùng lặp theo dạng giản thể: cùng một lượng từ xuất hiện ở
             * hai nghĩa của một mục là chuyện có thật, và hiện `个` hai lần trên
             * màn hình đọc ra như một lỗi.
             */
            'measure_words' => array_values(
                array_column($measureWords, null, 'simplified'),
            ),
        ];
    }

    /**
     * Một mục lượng từ: `個|个[ge4]` hoặc `家[jia1]`.
     *
     * Không có `|` thì phồn thể và giản thể là một — cùng quy ước mà chính dòng
     * CC-CEDICT dùng cho mục từ.
     *
     * @return array{simplified: string, traditional: string, pinyin: string}|null
     */
    private function parseMeasureWord(string $item): ?array
    {
        if (preg_match('/^([^|\[]+)(?:\|([^\[]+))?\[([^\]]+)\]$/u', $item, $m) !== 1) {
            return null;
        }

        $traditional = trim($m[1]);
        // Nhóm phồn-thể-`|`-giản-thể nằm GIỮA nên PHP luôn đặt `$m[2]`, thành
        // chuỗi rỗng khi dòng không có `|`. Không cần `??`.
        $simplified = trim($m[2]) !== '' ? trim($m[2]) : $traditional;

        if ($simplified === '') {
            return null;
        }

        return [
            'simplified' => $simplified,
            'traditional' => $traditional,
            // Cùng đường chuẩn hoá mà pinyin của mục từ đang đi, nên không đẻ ra
            // hai kiểu hiển thị pinyin trong cùng một response.
            'pinyin' => $this->pinyin->toneMarked(trim($m[3])),
        ];
    }

    /**
     * Dọn phần nghĩa còn lại sau khi rút `CL:`.
     *
     * `cat (CL:隻|只[zhi1])` để lại `cat ()`, và một cặp ngoặc rỗng trên màn hình
     * đọc ra như dữ liệu hỏng. Nghĩa nào rỗng hẳn sau khi dọn thì bị bỏ ở chỗ gọi
     * — đó là ca `银行`, nơi `CL:` là một nghĩa độc lập.
     */
    private function tidyAfterExtraction(string $text): string
    {
        $text = (string) preg_replace('/\(\s*\)/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B,;");
    }
}
