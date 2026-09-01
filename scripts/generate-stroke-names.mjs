#!/usr/bin/env node
/**
 * Sinh bảng tra "chữ → dãy hình nét" cho `characters:import`.
 *
 * Chạy TAY, không nằm trong build và không phải phụ thuộc runtime của API.
 * `cnchar` là thư viện JS còn API là PHP; bắt PHP shell ra Node mỗi lần import
 * là thêm một phụ thuộc runtime cho một trường trang trí. Sinh sẵn một JSON rồi
 * để importer đọc file thì API không cần biết Node tồn tại.
 *
 *   npm i --no-save cnchar cnchar-order
 *   node scripts/generate-stroke-names.mjs \
 *     --chars storage/app/dictionary/dictionary.txt \
 *     --out   storage/app/dictionary/stroke-names.json
 *
 * Nguồn: cnchar 3.x, MIT. Spike đo 20/20 trên bộ mẫu — xem
 * `hanora-app/plans/reports/spike-260901-net-but.md`.
 */
import { readFileSync, writeFileSync } from 'node:fs'
import cnchar from 'cnchar'
import order from 'cnchar-order'

cnchar.use(order)

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)

  return i === -1 ? fallback : process.argv[i + 1]
}

const charsPath = arg('chars', 'storage/app/dictionary/dictionary.txt')
const outPath = arg('out', 'storage/app/dictionary/stroke-names.json')

/**
 * Dãy hình nét của một chữ, hoặc `null` khi cnchar không biết.
 *
 * Ba cái bẫy ở đây, cả ba đều cho ra dữ liệu SAI mà không báo gì — spike mắc
 * đúng cái đầu tiên:
 *
 *   1. `cnchar.stroke()` nhận cả CHUỖI nhiều chữ nên nó trả mảng LỒNG. Đọc
 *      thẳng `result.length` cho ra "1 nét" cho mọi chữ.
 *   2. Chữ không có dữ liệu trả `[null]`, mà `[null].length === 1`. Phải kiểm
 *      chính phần tử, không kiểm độ dài.
 *   3. Vài nét trả dạng `"㇇|乛"` — hai biến thể của một nét. Lấy biến thể đầu;
 *      người học cần một hình để nhận mặt, không cần bảng đối chiếu.
 */
function strokeNames(char) {
  const result = cnchar.stroke(char, 'order', 'shape')
  const list = Array.isArray(result) ? result[0] : null

  if (!Array.isArray(list) || list.length === 0) return null

  const names = list.map((s) => (typeof s === 'string' ? s.split('|')[0] : null))

  return names.every((s) => typeof s === 'string' && s !== '') ? names : null
}

const chars = []

for (const line of readFileSync(charsPath, 'utf8').split('\n')) {
  if (line.trim() === '') continue

  try {
    const { character } = JSON.parse(line)

    if (typeof character === 'string' && character !== '') chars.push(character)
  } catch {
    // Dòng hỏng chỉ mất chính nó — cùng quy ước mà parser PHP đang giữ.
  }
}

const table = {}
let missing = 0

for (const char of chars) {
  const names = strokeNames(char)

  if (names === null) {
    // Phần lớn là chữ PHỒN THỂ: cnchar chỉ phủ giản thể. Bỏ hẳn khỏi bảng thay
    // vì ghi mảng rỗng — importer để cột `null`, và FE ẩn dòng "Nét bút".
    missing += 1

    continue
  }

  table[char] = names
}

writeFileSync(outPath, `${JSON.stringify(table)}\n`)

console.log(`Đã đọc ${chars.length} chữ từ ${charsPath}`)
console.log(`Sinh được ${Object.keys(table).length}, không có dữ liệu ${missing}`)
console.log(`Ghi ${outPath}`)
