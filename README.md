# hanora-api

REST API cho `hanora` — PWA học từ vựng tiếng Trung cho người Việt.

Laravel 13 · PHP 8.4 · PostgreSQL 16 · Redis 7. Repo này **chỉ** phục vụ API:
không view, không asset bundle, không Node.

Frontend nằm ở repo riêng [`hanora-app`](../hanora-app).

## Chạy từ máy trống

Cần Docker. Không cần cài PHP, Composer hay PostgreSQL trên máy.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
curl http://localhost:8080/api/health
# {"data":{"status":"ok","db":"ok"}}
```

### Cổng

Chốt riêng cho hanora để không đụng project khác trên cùng máy:

| Service | Cổng host |
|---|---|
| API (nginx) | `8080` |
| PostgreSQL | `5433` |
| Redis | `6380` |

## Lệnh

Mọi lệnh chạy trong container `app`:

```bash
docker compose exec app composer test      # Pest
docker compose exec app composer lint      # Pint, sửa tại chỗ
docker compose exec app composer lint:test # Pint, chỉ kiểm tra
docker compose exec app composer analyse   # Larastan level 6
docker compose exec app php artisan migrate
docker compose exec app php artisan dictionary:search-stats  # cache AI: tỉ lệ trúng, chi phí
```

### Test chạy trên database riêng

`phpunit.xml` hardcode `DB_DATABASE=hanora_test`, và
`docker/postgres/init-test-db.sh` tạo sẵn database đó khi volume postgres khởi
tạo lần đầu. Nếu bạn đã có volume từ trước và database chưa tồn tại:

```bash
docker compose exec postgres createdb -U hanora hanora_test
```

**Test chạy trên PostgreSQL thật, không phải SQLite.** Đây không phải sở thích:
P6 dùng generated column trên extension `unaccent`, và trên SQLite lỗi loại đó
chỉ lộ ra lúc deploy. CI cũng dựng service PostgreSQL vì lý do này.

## Dữ liệu từ điển

Ba nguồn, tải về `storage/app/dictionary/` (thư mục này **không** commit — xem
`.gitignore` trong đó). Chạy `php artisan dictionary:import` để nạp.

| Nguồn | File | Phiên bản dùng | License |
|---|---|---|---|
| CC-CEDICT | `cedict_ts.u8` | `version=1 subversion=0`, tải 2026-08-20 | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) |
| HSK 2.0 | `hsk-complete.json` | [drkameleon/complete-hsk-vocabulary](https://github.com/drkameleon/complete-hsk-vocabulary) `complete.json` | xem repo nguồn |
| SUBTLEX-CH-WF | `subtlex-ch-wf.json` | [leonsilicon/subtlex-ch-wf](https://github.com/leonsilicon/subtlex-ch-wf) | xem repo nguồn |
| Unihan | `Unihan_Readings.txt` | Unicode 17.0.0 (2025-07-24) | [Unicode License](https://www.unicode.org/terms_of_use.html) |
| Bảng Hán-Việt bổ sung | `hanviet-supplement.csv` | [ph0ngp/hanviet-pinyin-wordlist](https://github.com/ph0ngp/hanviet-pinyin-wordlist) | xem repo nguồn |
| CVDICT | **`database/data/cvdict.u8`** — commit trong git, xem ghi chú dưới | `version=1.0.1` (02/12/2024), tải 2026-08-23, SHA-256 `4dde4b20…ba0948` | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) |

**CVDICT nằm ở `database/data/`, KHÔNG phải `storage/app/dictionary/` như năm
nguồn trên.** Hai lý do: `storage/app/` gitignore toàn bộ nên `git add` bị bỏ qua
im lặng, và production mount named volume `storage-data` đè lên
`/var/www/html/storage` — volume đã tồn tại từ deploy trước nên Docker không seed
lại từ image. `database/` đi thẳng cùng image và không bị volume nào che.

Nội dung này **hiển thị cho người học**, không chỉ dùng để khớp truy vấn, nên file
được commit kèm SHA-256 mà `cvdict:import` kiểm trước khi parse: một file bị thay
là nghĩa sai dạy thẳng vào mặt người dùng, và ngưỡng độ phủ không phát hiện được
điều đó.

Chạy SAU `dictionary:import` — lệnh này chỉ gắn thêm cột vào dòng đã có, không tạo
dòng mới:

```bash
php artisan cvdict:import
php artisan cvdict:status  # exit code khác 0 nếu độ phủ tập ưu tiên < 95%
```

```bash
cd storage/app/dictionary
curl -L -o cedict.txt.gz https://www.mdbg.net/chinese/export/cedict/cedict_1_0_ts_utf-8_mdbg.txt.gz
gunzip -c cedict.txt.gz > cedict_ts.u8
curl -L -o hsk-complete.json https://raw.githubusercontent.com/drkameleon/complete-hsk-vocabulary/main/complete.json
curl -L -o subtlex-ch-wf.json https://raw.githubusercontent.com/leonsilicon/subtlex-ch-wf/main/SUBTLEX-CH-WF.json
curl -L -o Unihan.zip https://www.unicode.org/Public/UCD/latest/ucd/Unihan.zip && unzip -o Unihan.zip Unihan_Readings.txt
curl -L -o hanviet-supplement.csv https://raw.githubusercontent.com/ph0ngp/hanviet-pinyin-wordlist/master/hanviet.csv
```

CVDICT tải riêng, vào `database/data/` chứ không vào thư mục trên:

```bash
curl -L -o database/data/cvdict.u8 https://raw.githubusercontent.com/ph0ngp/CVDICT/main/CVDICT.u8
shasum -a 256 database/data/cvdict.u8   # phải khớp EXPECTED_SHA256 trong CvdictImport
```

Rồi ghép âm Hán-Việt và kiểm cổng chất lượng:

```bash
php artisan han-viet:import
php artisan han-viet:status    # exit code khác 0 nếu độ phủ dưới ngưỡng
```

**Attribution là nghĩa vụ, không phải phép lịch sự (D9).** Trang "Về hanora"
(P20) phải ghi công CC-CEDICT (CC BY-SA 4.0), Unihan (Unicode License), Tatoeba
(CC BY 2.0 FR) và **CVDICT (CC BY-SA 4.0)**.

CVDICT còn phải nói rõ **nguồn gốc**, không chỉ tên: nó dịch bằng GPT-4o
fine-tune, tác giả rà tay và thừa nhận còn sót lỗi. Người học cần biết mức tin cậy
của thứ họ đang học, và đó là lý do định nghĩa tiếng Anh vẫn hiển thị song song —
nó là cơ chế đối chiếu duy nhất họ có khi nghi ngờ một nghĩa.

Nghĩa tiếng Việt hiển thị trong app, nên nghĩa vụ ShareAlike của CVDICT áp cho
chính nội dung đó. Không sinh dữ liệu phái sinh nào khác từ CC-CEDICT.

**Sửa nghĩa sai: mở issue trên repo CVDICT rồi reimport, KHÔNG sửa tay trong DB.**
`cvdict:import` ghi đè hai cột nghĩa Việt mỗi lần chạy, nên một bản vá tay sẽ biến
mất im lặng ở lần import sau.

### Âm Hán-Việt: vì sao cần HAI nguồn

Đo trên tập ưu tiên thật (8.848 mục):

| Nguồn | `ok` |
|---|---|
| Chỉ Unihan `kVietnamese` | **71,2%** — vừa đủ qua cổng 70% |
| Unihan + bảng bổ sung | **99,1%** |

Unihan hỏng ở hai chỗ, và cả hai đều nghiêm trọng:

1. **Thiếu ký tự thường dùng.** `面`, `說`, `愛`, `電`, `以`, `為` đều không có
   `kVietnamese`. Đây không phải ký tự hiếm.
2. **Khóa theo ký tự, không theo cách đọc.** Unihan chỉ cho 行 một âm `hàng`,
   nên `行走` sẽ ra `hàng tẩu` thay vì `hành tẩu` — sai im lặng, đúng loại lỗi
   mà R6 trong plan cảnh báo.

Bảng bổ sung khóa theo **(ký tự, âm tiết pinyin)** nên phân giải được chữ đa âm
bằng chính pinyin của mục từ: `行 hang2 → hàng` cho `銀行`, `行 xing2 → hành`
cho `行走`. Chi tiết thứ tự ưu tiên nằm trong `HanVietReadingTable`.

### Số đo của lần import gần nhất

| | |
|---|---|
| Mục trong `dictionary_words` | 123.646 |
| Khóa trùng trong nguồn, đã gộp nghĩa | 1.054 |
| Có `frequency_rank` | 47.448 |
| Khớp HSK 2.0 | 4.987 |
| Từ đa âm phải đoán cấp HSK | 0 |
| **Tập ưu tiên (`is_priority`)** | **8.848** |
| Âm Hán-Việt `ok` toàn từ điển | 120.571 |
| Âm Hán-Việt `ok` trên tập ưu tiên | **8.766 (99,1%)** — cổng đòi ≥ 70% |

**Ngưỡng `N = 6000` là số ĐO ĐƯỢC, không phải số chọn bừa.** D5 đòi tập ưu tiên
rơi vào 8.000–10.000. Đo trên nguồn thật:

| N | \|HSK ∪ freq ≤ N\| |
|---|---|
| 3000 | 6.269 |
| 5000 | 7.427 |
| **6000** | **8.094 → 8.848 sau khi tính cả biến thể đa âm** |
| 8000 | 9.528 |

Đúng như red team H10 cảnh báo, `N = 5000` **không** chạm được mục tiêu vì HSK
2.0 trùng nặng với top-5000 SUBTLEX. Ngưỡng nằm ở
`DictionaryEnricher::FREQUENCY_THRESHOLD`; đổi nguồn dữ liệu thì phải đo lại —
lệnh import tự cảnh báo nếu kết quả rơi ra ngoài dải.

## Lớp AI (Gemini)

Từ điển vẫn là **CC-CEDICT + CVDICT + Unihan + HSK + SUBTLEX**. AI không thay
nguồn nào; nó chỉ làm hai việc mà dữ liệu tĩnh không làm được.

### Diễn giải truy vấn — đang chạy

`/api/dictionary/search` gọi Gemini **chỉ khi SQL không có bằng chứng mạnh**,
rồi cache vĩnh viễn theo truy vấn đã chuẩn hóa. Đo trên DB thật 2026-08-28:

| Truy vấn | Trước | Sau |
|---|---|---|
| `yêu` | 要 要求 约 邀请 | 爱 喜欢 热爱 爱情 |
| `bác sĩ` | 博士 (tiến sĩ) | 医生 大夫 医师 |
| `anh yêu em` | 博爱 基情 贤妻 | 爱 喜欢 |
| `tôi muốn ăn cơm` | (rỗng) | 我 想 要 吃 饭 吃饭 |
| `xin chào` | 你好 ✅ | 你好 ✅ — **không gọi AI, 9ms** |

Truy vấn dạng **CÂU** còn nhận thêm bản dịch, nằm ở trường `translation` NGOÀI
`data`: `bạn có nhớ tôi không?` → `你还记得我吗？`. Nó nằm ngoài `data` vì một câu
không có `id`, không lưu được vào sổ từ vựng và không phải mục từ điển — nhét vào
cùng mảng là làm nút lưu hỏng ở đúng phần tử đầu tiên. `translation` là `null`
cho mọi truy vấn dạng từ.

Ba tính chất bắt buộc, mỗi cái có test khóa lại:

1. **Không bao giờ 5xx vì Gemini.** API chết thì `/search` trả nguyên kết quả SQL.
2. **Truy vấn mạnh không chạm AI.** Khớp chữ Hán, pinyin, hoặc gloss tiếng Việt
   chính xác đều trả trong vài mili-giây như trước.
3. **Chữ Hán do AI trả về phải tồn tại trong `dictionary_words`.** Chữ bịa bị loại
   trước khi tới người dùng — đo được 8–11% đề xuất của AI rơi vào nhóm này.

Độ trễ: lời gọi thật **3,2–4,5 giây**, trúng cache **~1ms**. Luật quyết định nằm
ở `SearchWeakness`, và bộ truy vấn vàng dùng để hiệu chỉnh nó nằm trong
`tests/Unit/SearchWeaknessTest.php` — **đó là số đo, không phải ví dụ**. Đổi luật
thì đo lại, đừng sửa kỳ vọng cho khớp luật mới.

### Làm giàu mục từ — đang chạy

`GET /api/dictionary/words/{word}/enrichment` trả nghĩa theo từ loại, ví dụ song
ngữ zh–vi, bộ thủ và số nét, từ ghép liên quan, thành ngữ. Sinh **một lần cho mỗi
từ** rồi cache vĩnh viễn; lần tra thứ hai không phát sinh request nào ra Gemini.

Gọi **async sau khi màn chi tiết đã render** phần dữ liệu cứng:

| Trạng thái | Mã | Ý nghĩa |
|---|---|---|
| đã có nội dung | `200` | `data` đầy đủ, `Cache-Control: public, max-age=86400` |
| đang sinh | `202` | `data: null`, `Retry-After: 3`, `no-store` |
| không dùng được | `200` | `data: null`, `meta.status: "unavailable"` — **không bao giờ 5xx** |

Payload mang `source: "ai"` và tên model; FE phải hiện nhãn đó.

```bash
docker compose exec app php artisan dictionary:enrich --hsk        # nạp sẵn tập HSK
docker compose exec app php artisan dictionary:enrich --hsk --limit=50   # chạy thử trước
docker compose exec app php artisan dictionary:enrich --stale --force    # sinh lại khi prompt đổi
docker compose exec app php artisan queue:work --queue=enrichment
```

Đo thật: **4–5 giây mỗi từ**, ~$0,0011. Tập HSK 4.987 từ ≈ **$5,70**; cả 123.646
từ ≈ **$141**. Chạy `--limit=50` và kiểm tay trước khi chạy toàn bộ — đốt 4.987
lượt gọi để phát hiện prompt sai ở lượt thứ ba là cách học đắt nhất.

Job xếp trên queue **`enrichment`**, tách khỏi `default`: một đợt pre-warm không
được đẩy mail đặt lại mật khẩu xuống sau 4.987 job.

### Chi tiết câu — `GET /api/dictionary/sentences?zh=…`

Bấm vào thẻ dịch ở màn tìm kiếm là mở trang này. Trả pinyin, bản dịch tự nhiên,
**nghĩa đen**, **tách từ** và ghi chú ngữ pháp.

Khoá cache là chính chuỗi Hán đã chuẩn hoá, KHÔNG phải một id sinh ra rồi trả về:
câu không phải mục từ điển nên không có id, và khoá theo văn bản cho phép FE điều
hướng ngay khi bấm thay vì gọi API lấy id trước rồi mới chuyển trang.

Mỗi token mang `word_id` tra ngược từ corpus, nên bấm vào một từ trong câu là mở
được trang chi tiết từ đó. `null` là trạng thái hợp lệ và thường gặp — dấu câu,
tên riêng, cụm không có trong CC-CEDICT đều rơi vào đó.

**Tách từ bị bỏ hẳn nếu nối các token lại không ra đúng câu gốc** (xét trên chữ,
bỏ qua dấu câu). Ca này nguy hiểm nhất: model làm rơi một chữ thì người học đọc
một câu khác với câu trên màn hình mà không cách nào thấy bằng mắt. Mất khối tách
từ, giữ pinyin và bản dịch.

Đồng bộ chứ không 202 như `/enrichment`: đây là nội dung CHÍNH của trang người
dùng vừa mở. Đo thật: **3,8–5,2 giây** lần đầu, **36ms** khi trúng cache.

### Nghĩa tiếng Việt cho câu ví dụ — `GET /api/dictionary/words/{word}/example-translations`

Câu ví dụ Tatoeba chỉ có bản dịch **tiếng Anh**. Endpoint này dịch tối đa 3 câu
của một từ sang tiếng Việt trong **một** lời gọi Gemini, lưu vĩnh viễn vào
`dictionary_examples.translation_vi`.

Lười và async như `/enrichment`, không đồng bộ như `/sentences`: nó nổ ra ở **mọi
lần mở trang chi tiết** — thao tác điều hướng thường xuyên nhất của app — nên giữ
một worker PHP-FPM cho mỗi lượt mở từ chưa dịch là đặt rủi ro sai chỗ.

| Trạng thái | Mã | Ý nghĩa |
|---|---|---|
| đã dịch xong | `200` | `data` đủ câu, `Cache-Control: public, max-age=86400` |
| đang dịch | `202` | `Retry-After: 3`, `no-store` |
| cạn lượt / thiếu key | `200` | `meta.status: "unavailable"` — **không bao giờ 5xx** |

`data` **luôn là mảng**, kể cả ở hai nhánh sau: một từ có thể dịch xong 2 câu rồi
cạn lượt ở câu thứ ba, và vứt cả lô khi đó là vứt đi bản dịch đã trả tiền để có.

Job `TranslateWordExamples` là `ShouldBeUnique` theo `word_id` — đo thật: 5 request
đồng thời cho một từ chưa dịch tạo đúng **1** job. Chạy trên queue `default`, mất
**~4 giây** mỗi từ.

Bản dịch do máy sinh và không có người rà, nên dòng tiếng Anh Tatoeba **ở lại**
trên giao diện làm chốt đối chiếu — cùng lý do `definitions_vi` không thay thế
`definitions_en`.

### Dọn nghĩa tiếng Việt — chạy thủ công

`definitions_vi` của CVDICT có nhiễu đo được trên 115.040 mục: 14.113 dòng lẫn
chữ Hán, 4.092 lẫn mã pinyin, 31.467 có ngoặc chú thích trong nghĩa đầu. Nhưng
THỨ TỰ nghĩa mới là phần hại nhất — mọi bậc `precision` của nhánh nghĩa Việt đều
tính trên nghĩa ĐẦU:

```
的  → "xe taxi; xe cab (viết tắt của 的士[di1 shi4])"
你  → "bạn (ngôi thứ hai thông dụng, khác với kính trọng 您[nin2])"
吗  → "dùng trong 嗎啡|吗啡[ma3 fei1]"
```

`dictionary:optimize-glosses` dọn và sắp lại bằng AI, ghi vào cột `*_vi_ai*`.
**`definitions_vi` của CVDICT KHÔNG bị đụng tới** — nó vẫn là thứ hiển thị, cột
AI chỉ dùng để KHỚP. Hỏng thì xoá cột AI là quay về đúng hành vi cũ.

```bash
docker compose exec app php artisan dictionary:optimize-glosses --all --pretend  # đếm và ước giá
docker compose exec app php artisan dictionary:optimize-glosses --frequency=2000 # chạy thử nhóm phổ biến
docker compose exec app php artisan dictionary:optimize-glosses --all            # 115.040 mục
docker compose exec app php artisan queue:work --queue=glosses
```

Gọi theo lô 20 từ: 5.752 lời gọi thay vì 115.040. Ước **~$10** cho toàn bộ từ
điển. Chạy lại được — mục đã dọn ở phiên bản prompt hiện hành bị bỏ qua.

Sau khi chạy, `search("của")` khớp 的 ở `rank 6 precision 0`; trước đó không thể,
vì nghĩa đầu của 的 là "xe taxi".

### Biến môi trường

| Biến | Mặc định | Ghi chú |
|---|---|---|
| `GEMINI_API_KEY` | *(rỗng)* | Thiếu key thì cả hai lớp AI tắt êm, không lỗi |
| `GEMINI_MODEL` | `gemini-3.1-flash-lite` | `2.5-flash-lite` trả 404 cho tài khoản mới |
| `GEMINI_RPM` | `10` | Van giảm áp; nguồn sự thật là mã 429 trả về |
| `GEMINI_TIMEOUT` | `30` | Cho việc sinh nội dung nền |
| `GEMINI_SEARCH_TIMEOUT` | `6` | Diễn giải truy vấn; đo được 3,2–4,5s |
| `GEMINI_SENTENCE_TIMEOUT` | `15` | Phân tích câu; đầu ra dài hơn hẳn |

Ba hàng đợi, theo thứ tự ưu tiên: `default` (mail, có người đang chờ) →
`enrichment` → `glosses`.

> **Nội dung do AI sinh khác bản chất với năm nguồn trong bảng trên.** Nó không có
> license, không có người rà, và có thể sai. FE phải hiển thị nhãn nguồn —
> response mang sẵn `meta.source` và, ở lớp làm giàu, `source: "ai"` kèm tên model.

> **`GEMINI_API_KEY` chỉ sống trong `.env`.** Không commit, không đưa vào
> `.env.example`, không log. Key nào đã từng bị dán ra ngoài trình quản lý bí mật
> thì phải rotate ở Google AI Studio trước khi deploy.

## Deploy (P20)

> **Chưa chạy lần nào.** Toàn bộ cấu hình dưới đây đã viết và kiểm cú pháp,
> nhưng chưa có VPS để thực thi. Mọi bước đánh dấu ⚠️ chỉ verify được trên máy
> thật.

### Kiến trúc

```text
docker-compose.prod.yml
├── nginx       service DUY NHẤT publish port (80/443), TLS Let's Encrypt
│               phục vụ FE tĩnh + proxy /api → app (cùng origin, D12)
├── certbot     tự gia hạn chứng chỉ
├── app         PHP-FPM, opcache validate_timestamps=0   ─┐
├── worker      queue:work (chỉ mail đặt lại mật khẩu)    ├─ internal network
├── scheduler   schedule:work (sanctum:prune-expired)     │  KHÔNG có `ports:`
├── postgres    volume riêng + pg_dump hằng ngày          │
└── redis       requirepass bật                           ─┘
```

**Chỉ nginx có `ports:`, và đó là ranh giới bảo mật quan trọng nhất của cả
stack.** Docker chèn rule thẳng vào chain `DOCKER` của iptables và **đi vòng qua
chính sách deny của `ufw`** — `ufw status` báo chặn trong khi cổng vẫn mở ra
Internet. Publish nhầm Redis nghĩa là ai đó **tiêm được job tùy ý** vào hàng đợi
mà worker sẽ ngoan ngoãn chạy; publish nhầm Postgres nghĩa là lộ bảng `users` và
`personal_access_tokens`.

### Các bước

```bash
# 1. Chuẩn bị env (TRÊN VPS, không commit)
cp .env.production.example .env.production
cp .env.production.db.example .env.production.db
docker run --rm hanora-api:latest php artisan key:generate --show   # dán vào APP_KEY

# 2. Build và khởi động
docker build -f docker/php/Dockerfile.prod -t hanora-api:latest .
docker compose -f docker-compose.prod.yml up -d

# 3. Chứng chỉ TLS (lần đầu)
docker compose -f docker-compose.prod.yml run --rm certbot certonly \
  --webroot -w /var/www/certbot -d hanora.example.com --cert-name hanora

# 4. Migration và dữ liệu
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan dictionary:import
docker compose -f docker-compose.prod.yml exec app php artisan han-viet:import
docker compose -f docker-compose.prod.yml exec app php artisan han-viet:status   # gate ≥70%
docker compose -f docker-compose.prod.yml exec app php artisan examples:import
docker compose -f docker-compose.prod.yml exec app php artisan cvdict:import
docker compose -f docker-compose.prod.yml exec app php artisan cvdict:status    # gate ≥95% tập ưu tiên

# 5. Frontend
# CHỈ deploy frontend sau khi `cvdict:status` PASS. Thiếu nghĩa tiếng Việt thì
# hỏng IM LẶNG theo hai đường cùng lúc — không lỗi, không log: tìm bằng tiếng
# Việt không ra gì, và thẻ từ không hiện nghĩa Việt mà màn Tài khoản đã hứa.
#
# Service worker cache response từ điển 24 giờ. Người dùng đang mở app sẽ thấy
# thẻ từ CHƯA có nghĩa tiếng Việt cho tới khi cache hết hạn — đây là hành vi
# mong đợi sau deploy này, không phải lỗi import.
cd ../hanora-app && npm ci && npm run build
docker cp dist/. "$(docker compose -f ../hanora-api/docker-compose.prod.yml ps -q nginx)":/var/www/html/frontend/

# 6. Backup hằng ngày — thêm vào crontab của host
0 3 * * * docker compose -f /srv/hanora-api/docker-compose.prod.yml exec -T postgres /usr/local/bin/backup.sh
```

### ⚠️ Kiểm chứng bắt buộc trước khi coi là xong

```bash
# Quét cổng TỪ NGOÀI — không phải từ chính VPS, và không tin `ufw status`.
nmap -Pn hanora.example.com          # chỉ được thấy 22/80/443

# Khôi phục thử backup. "Backup chưa khôi phục thử thì chưa phải backup."
docker compose -f docker-compose.prod.yml exec -T postgres /usr/local/bin/verify-restore.sh

# Header bảo mật, đặc biệt CSP
curl -sI https://hanora.example.com | grep -iE 'content-security|strict-transport|x-content-type'
```

**CSP không phải tùy chọn.** P9 chấp nhận lưu token trong `localStorage` với
điều kiện có kỷ luật render, và CSP ở nginx là thứ **duy nhất thực thi được**
điều kiện đó. Bỏ CSP thì cơ sở của quyết định ở P9 không còn, và phải quay lại
bàn về nơi lưu token.

### Rollback

Mỗi lần deploy là một ảnh Docker mới (`validate_timestamps=0` nên opcache không
tự nhận file đổi). Rollback = trỏ lại tag ảnh trước rồi `up -d`.

## Quy ước API

Năm quy ước dưới đây chốt ở Phase 1 và áp dụng cho **mọi** phase sau. Đổi một
quy ước là đổi hợp đồng của cả API, không phải quyết định cục bộ của một phase.

### 1. API Resource cho mọi response

Không trả model thô. Mọi response đi qua một class trong `app/Http/Resources/`.

Success bọc trong `data`; list có thêm `meta`:

```json
{ "data": { "...": "..." } }
{ "data": [], "meta": { "...": "..." } }
```

Lỗi validation: 422 chuẩn Laravel. Lỗi khác: `{ "message": "..." }`.

### 2. FormRequest là bắt buộc

**Mọi write và mọi read có filter đều phải có FormRequest** với bound cụ thể —
max length, enum, khoảng số. Không có ngoại lệ. Thiếu FormRequest là thiếu
validation, và không phase nào được phép bỏ qua quy ước này.

### 3. Auth mặc định bật

**Mọi endpoint nằm sau `auth:sanctum`** trừ `register`, `login` (thêm ở P9) và
health check. Không có chế độ khách, kể cả cho tra từ điển.

Bề mặt công khai hiện tại là **đúng một route**: `GET /api/health`.
`tests/Feature/RouteSurfaceTest.php` khóa con số đó lại — thêm route công khai
là phải sửa test, tức là phải có chủ đích.

`routes/web.php` cố tình để trống: nhóm `web` chạy session middleware, nên một
route công khai ở đó ghi một dòng `sessions` cho mỗi request ẩn danh — bảng
phình vô hạn từ endpoint không ai xác thực. Health route mặc định `/up` của
Laravel cũng đã gỡ vì trùng vai trò với `/api/health`.

### 4. `timestamptz`, không phải `timestamp`

**Mọi cột thời gian là `timestamptz`** — dùng `timestampTz()` /
`timestampsTz()`, không phải `timestamp()` / `timestamps()`.
`tests/Feature/SchemaConventionTest.php` quét `information_schema` và gãy nếu
một cột không mang múi giờ lọt vào.

`APP_TIMEZONE=Asia/Ho_Chi_Minh`. Ranh
giới ngày — chuỗi ngày học, lịch ôn tập — tính theo múi giờ này chứ không phải
UTC. Một cột `timestamp` lọt vào là một bug lịch ôn đang chờ xảy ra.

### 5. Throttle mặc định

Toàn bộ nhóm route API chạy `throttle:60,1`. Endpoint nào cần chặt hơn thì siết
thêm tại chỗ.

## CORS

**Production không có cấu hình CORS nào, và điều đó là cố ý.**

Frontend được nginx phục vụ *cùng origin* với API (quyết định D12 trong plan),
nên không tồn tại request cross-origin. `config/cors.php` chỉ đọc
`FRONTEND_URL` để Vite dev server (`http://localhost:5173`) gọi được API.
Biến này để trống ở production → không origin nào được phép.

Đừng thêm `*` vào `allowed_origins`. Nếu một request production cần CORS thì
thứ cấu hình sai là nginx, không phải file này.

## Kế hoạch

`hanora-app/plans/260820-0156-hanora-mvp/plan.md` là nguồn quyết định duy nhất.
Các phase gắn nhãn `API` thực thi trong repo này.

> Thư mục `plans/` nằm trong repo `hanora-app` và **không** được commit — nó bị
> loại bởi global gitignore, nên chỉ tồn tại trên máy dev.
