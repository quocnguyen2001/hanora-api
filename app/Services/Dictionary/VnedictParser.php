<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use Generator;
use Normalizer;
use RuntimeException;

/**
 * Đọc `vnedict.txt` thành từng bản ghi Việt → danh sách nghĩa tiếng Anh.
 *
 * Đọc STREAMING bằng generator, cùng lý do với `CedictParser`: import phải chạy
 * được trên VPS nhỏ.
 *
 * Định dạng danh nghĩa là `việt : english`, nhưng file thật KHÔNG sạch tuyệt
 * đối. Đo trên bản 15/02/2019 (54.377 dòng vật lý):
 *
 *   54.356  đúng ` : `
 *       13  dấu hai chấm dính (`kền kền: vulture`, `hậu nghiệm :a posteriori`)
 *        5  không có dấu hai chấm — câu ví dụ lọt vào, không cứu được
 *        2  dòng trống
 *        1  dòng header `#`
 *
 * Nên tách theo dấu hai chấm ĐẦU TIÊN với khoảng trắng linh hoạt, không cứng
 * nhắc ` : `. Mười ba mục kia là dữ liệu thật, bỏ chúng chỉ vì người biên soạn
 * gõ thiếu một dấu cách là mất mát vô cớ.
 *
 * Trong 54.369 mục tách được, 66 mục bị bỏ vì phần tiếng Anh CHỈ gồm chú thích
 * trong ngoặc (`Bình Định : (province name)`, `Cửu Long : (river in Vietnam)`).
 * Đó là danh từ riêng có "định nghĩa" là nhãn phân loại chứ không phải bản dịch —
 * làm từ khóa tìm kiếm thì vô dụng. `skipped()` đếm chúng để nguồn đổi thì thấy.
 */
final class VnedictParser
{
    /**
     * Hư từ tiếng Anh KHÔNG lọc ở đây.
     *
     * Bộ lọc nhiễu thuộc `VietnameseQueryBridge` (query time) để chỉnh được bằng
     * deploy thay vì reimport. Parser chỉ làm những bước KHÔNG mất thông tin.
     */
    public function __construct(private readonly PinyinNormalizer $pinyin) {}

    /** Số mục bị bỏ vì không còn nghĩa nào sau khi làm sạch. */
    private int $skipped = 0;

    /** Số mục có gloss bị cắt vì nguồn dính hai dòng vào nhau. */
    private int $truncated = 0;

    /**
     * Thống kê của lần `parse()` gần nhất.
     *
     * Import in ra để nguồn đổi thì thấy ngay. Không có nó thì một bản VNEDICT
     * mới làm hỏng gấp mười lần số mục cũng chỉ hiện ra dưới dạng "đã upsert N
     * mục" với N nhỏ hơn một chút.
     *
     * @return array{skipped: int, truncated: int}
     */
    public function stats(): array
    {
        return ['skipped' => $this->skipped, 'truncated' => $this->truncated];
    }

    /**
     * @return Generator<int, array{term: string, term_plain: string, senses: list<string>}>
     */
    public function parse(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được từ điển Việt-Anh: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được từ điển Việt-Anh: {$path}");
        }

        // Reset: `duplicateTerms()` chạy `parse()` một lượt riêng trước lượt
        // import, nên không reset là mọi con số bị đếm gấp đôi — và một thống kê
        // sai gấp đôi còn tệ hơn không có thống kê.
        $this->skipped = 0;
        $this->truncated = 0;

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
     * Những `term` xuất hiện nhiều hơn một lần trong nguồn.
     *
     * Đo được 15 khóa như vậy. Postgres từ chối `ON CONFLICT DO UPDATE` chạm cùng
     * một dòng hai lần trong một câu lệnh, nên chúng phải được gộp TRƯỚC khi
     * upsert — nếu không, import crash giữa chừng và để lại bảng dở dang.
     *
     * Pass này chỉ giữ chuỗi khóa, không giữ bản ghi, nên vẫn nhẹ RAM.
     *
     * @return array<string, true>
     */
    public function duplicateTerms(string $path): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->parse($path) as $entry) {
            if (isset($seen[$entry['term']])) {
                $duplicates[$entry['term']] = true;

                continue;
            }

            $seen[$entry['term']] = true;
        }

        return $duplicates;
    }

    /**
     * @return array{term: string, term_plain: string, senses: list<string>}|null
     */
    private function parseLine(string $line): ?array
    {
        // BOM xuất hiện GIỮA file, không chỉ ở byte đầu — bản 2019 có một cái
        // ngay trước dòng header. `utf-8-sig` ở tầng đọc file không bắt được nó.
        $line = trim(str_replace("\u{FEFF}", '', $line));

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $position = mb_strpos($line, ':');

        if ($position === false) {
            return null;
        }

        $term = trim(mb_substr($line, 0, $position));
        $english = trim(mb_substr($line, $position + 1));

        if ($term === '' || $english === '') {
            return null;
        }

        /*
         * NFC bắt buộc: `term` là khóa unique và được so khớp bằng bằng-chuỗi với
         * input từ trình duyệt. Đo được đúng 1 mục không NFC trong nguồn, nhưng
         * một mục cũng đủ để tạo ra hai dòng "giống hệt nhau" mà unique không
         * chặn, và một truy vấn không bao giờ khớp.
         *
         * `mb_strtolower` cùng lý do: cầu nối chuẩn hóa truy vấn về chữ thường
         * trước khi tra. Đo được 1.237 mục có chữ hoa (`A La Hán`,
         * `Bộ Ngoại Giao`) — giữ nguyên hoa thì gõ `bộ ngoại giao` không bao giờ
         * khớp `term`, phải rơi xuống `term_plain` và nhận cả đống đồng âm.
         *
         * Hạ chữ làm 70 cặp đụng nhau (`Ba Lê` = Paris, `ba lê` = ballet). Chúng
         * biến thành khóa trùng và đi qua đúng đường gộp nghĩa của
         * `duplicateTerms()`, giữ cả hai nghĩa — đúng thứ ta muốn, vì cả hai đều
         * là từ khóa hợp lệ để bắc cầu.
         */
        $term = Normalizer::normalize($term, Normalizer::FORM_C) ?: $term;
        $term = mb_strtolower($term);
        $senses = $this->extractSenses($english);

        if ($senses === []) {
            $this->skipped++;

            return null;
        }

        return [
            'term' => mb_substr($term, 0, 64),
            'term_plain' => mb_substr($this->pinyin->stripDiacritics($term), 0, 64),
            'senses' => $senses,
        ];
    }

    /**
     * Tách phần tiếng Anh thành danh sách nghĩa.
     *
     * Chỉ hai bước, cả hai đều không mất thông tin dùng được:
     *
     * 1. Bỏ chú thích trong ngoặc TRÒN và VUÔNG. Ngoặc vuông là dạng thật trong
     *    nguồn — `con : [cl for animals and other small objects]; child` — và bỏ
     *    sót nó nghĩa là nhồi "cl for animals and other small objects" vào tsquery.
     * 2. Tách theo `;` và `,`. VNEDICT dùng `;` cho từ loại/đồng âm và `,` cho từ
     *    đồng nghĩa; cả hai đều cho ra từ khóa dùng được.
     *
     * KHÔNG cắt theo độ dài, KHÔNG giới hạn số nghĩa — đó là việc của query time.
     *
     * @return list<string>
     */
    private function extractSenses(string $english): array
    {
        /*
         * Nguồn có 11 dòng chứa dấu hai chấm thứ hai, hai dạng khác nhau:
         *
         *   phân vuông : square centimeter : to separate, to share
         *   → hai mục dính nhau CÓ ranh giới. Cắt tại đây lấy đúng nghĩa mục một.
         *
         *   bà nội : paternal grandmotherbà phước : sister (religious title)
         *   → hai mục dính nhau KHÔNG có ranh giới ("grandmother" + "bà phước"
         *     liền nhau). Cắt cho ra "paternal grandmotherbà phước".
         *
         * Dạng hai không cứu được: không có dấu hiệu nào cho biết nghĩa mục một
         * kết thúc ở đâu. Phần thừa còn lại là token VÔ HẠI chứ không phải sai —
         * `grandmotherbà` không phải lexeme của bất kỳ định nghĩa nào trong
         * `dictionary_words`, nên nó không khớp gì, không kéo kết quả rác về.
         *
         * Chọn cắt thay vì bỏ cả mục: dạng một được cứu đúng, dạng hai giữ lại
         * dòng thay vì mất hẳn một từ thông dụng. Cả hai đều được đếm.
         */
        $mergedAt = mb_strpos($english, ' : ');

        if ($mergedAt !== false) {
            $english = mb_substr($english, 0, $mergedAt);
            $this->truncated++;
        }

        $english = (string) preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $english);

        $senses = [];

        foreach (preg_split('/[;,]/u', $english) ?: [] as $sense) {
            $sense = trim(mb_strtolower($sense));
            $sense = (string) preg_replace('/\s+/u', ' ', $sense);

            /*
             * Dấu hai chấm còn sót SAU khi bỏ ngoặc → bỏ nghĩa đó.
             *
             *   két : (1) screech, gnashing, grinding (sound): (2) safe, ...
             *
             * Ở đây dấu hai chấm dính vào `)` nên bước cắt phía trên (tìm ` : `)
             * không thấy; nó chỉ lộ ra sau khi ngoặc bị gỡ, dán `grinding` với
             * `safe` thành một nghĩa.
             *
             * Bỏ chứ không tách tiếp: tách trên `:` sẽ đúng cho ca này nhưng gán
             * nghĩa SAI cho ca dòng dính không ranh giới (`bà nội` sẽ nhận thêm
             * nghĩa "sister"). Mất một nghĩa là vô hại; gán sai thì không.
             */
            if ($sense !== '' && ! str_contains($sense, ':') && ! in_array($sense, $senses, true)) {
                $senses[] = $sense;
            }
        }

        return $senses;
    }
}
