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
| VNEDICT | **`database/data/vnedict.txt`** — commit trong git, xem ghi chú dưới | 15/02/2019, tải 2026-08-21, SHA-256 `01a48269…19ea24` | [CC BY 3.0](https://creativecommons.org/licenses/by/3.0/) |

**VNEDICT nằm ở `database/data/`, KHÔNG phải `storage/app/dictionary/` như bốn
nguồn trên.** Ba lý do: `storage/app/` gitignore toàn bộ nên `git add` bị bỏ qua im
lặng; production mount named volume `storage-data` đè lên `/var/www/html/storage`,
mà volume đã tồn tại từ deploy trước nên Docker không seed lại từ image; còn
`database/` đi thẳng cùng image và không bị volume nào che. Nguồn upstream
(`http://www.denisowski.org/Vietnamese/vnedict.txt`) chỉ có HTTP và host không phục
vụ được HTTPS, nên file được commit kèm SHA-256 mà `vi-lexicon:import` kiểm trước
khi parse, cộng bản lưu readme ở `database/data/vnedict-readme.html` làm bằng chứng
giấy phép.

```bash
php artisan vi-lexicon:import
php artisan vi-lexicon:status  # exit code khác 0 nếu bảng rỗng hoặc dưới ngưỡng
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

Rồi ghép âm Hán-Việt và kiểm cổng chất lượng:

```bash
php artisan han-viet:import
php artisan han-viet:status    # exit code khác 0 nếu độ phủ dưới ngưỡng
```

**Attribution là nghĩa vụ, không phải phép lịch sự (D9).** Trang "Về hanora"
(P20) phải ghi công CC-CEDICT (CC BY-SA), Unihan (Unicode License), và
**VNEDICT (CC BY 3.0)**. Vì không sinh dữ liệu phái sinh nào từ CC-CEDICT — V1 đã
bỏ dịch máy — nên không phát sinh nghĩa vụ ShareAlike cho nội dung tự tạo.

VNEDICT phải ghi công dù **không hiển thị ở đâu trong app**: nó chỉ dùng để khớp
truy vấn. CC BY yêu cầu ghi công khi sử dụng, không phải khi hiển thị.

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
docker compose -f docker-compose.prod.yml exec app php artisan vi-lexicon:import
docker compose -f docker-compose.prod.yml exec app php artisan vi-lexicon:status # gate ≥50k mục

# 5. Frontend
# CHỈ deploy frontend sau khi `vi-lexicon:status` PASS: màn Tài khoản hứa với
# người dùng là tìm được bằng nghĩa tiếng Việt, còn thiếu bảng thì tính năng đó
# hỏng im lặng — không lỗi, không log, chỉ là không ra kết quả.
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
