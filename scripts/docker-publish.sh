#!/usr/bin/env bash
#
# Dựng ảnh production và đẩy lên GitHub Container Registry.
#
#   ./scripts/docker-publish.sh              # dựng + đẩy phiên bản trong VERSION
#   ./scripts/docker-publish.sh --dry-run    # dựng thử, KHÔNG đẩy
#
# Version lấy từ file `VERSION` ở thư mục gốc — một nguồn duy nhất. Script đối
# chiếu nó với git (tag `v<version>`, working tree sạch) rồi mới dựng, vì một
# tag ảnh không truy ra được commit thì lúc rollback chỉ còn cách đoán.
#
# Ảnh ĐA KIẾN TRÚC (amd64 + arm64). Máy Mac Apple Silicon dựng ảnh arm64 theo
# mặc định, và một ảnh arm64 đẩy lên registry sẽ KHÔNG chạy nổi trên VPS amd64 —
# lỗi chỉ lộ ra lúc `docker compose up` trên máy thật.
set -euo pipefail

# ─── Cấu hình (đè được bằng biến môi trường) ────────────────────────────────

REGISTRY="${REGISTRY:-ghcr.io}"
IMAGE_OWNER="${IMAGE_OWNER:-quocnguyen2001}"
IMAGE_NAME="${IMAGE_NAME:-hanora-api}"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
DOCKERFILE="${DOCKERFILE:-docker/php/Dockerfile.prod}"

# Builder riêng, driver `docker-container`. Driver `docker` mặc định của Docker
# Desktop KHÔNG đẩy được ảnh đa kiến trúc; script tự dựng builder này nếu chưa
# có, và nó tồn tại qua các lần chạy sau.
BUILDER="${BUILDER:-hanora}"

# Buildx từ 0.10 mặc định gắn kèm provenance attestation, và trên trang package
# của GHCR nó hiện thành các mục kiến trúc `unknown/unknown` xen giữa các tag
# thật. Tắt đi để danh sách tag đọc được lúc cần chọn tag rollback.
PROVENANCE="${PROVENANCE:-false}"

IMAGE="$REGISTRY/$IMAGE_OWNER/$IMAGE_NAME"

# ─── Cờ ─────────────────────────────────────────────────────────────────────

DRY_RUN=false      # dựng nhưng không đẩy
ALLOW_DIRTY=false  # cho phép dựng khi working tree bẩn
PUSH_LATEST=true   # có dời tag `latest` không
FORCE=false        # cho phép ghi đè một tag version đã tồn tại trên registry

usage() {
  cat <<'USAGE'
Dựng ảnh production và đẩy lên GHCR.

  ./scripts/docker-publish.sh [tuỳ chọn]

Tuỳ chọn:
  --dry-run       Dựng đủ cả hai kiến trúc để bắt lỗi build, nhưng không đẩy.
  --allow-dirty   Cho phép dựng khi working tree còn thay đổi chưa commit.
                  Khi đó CHỈ đẩy tag `sha-<commit>-dirty`: tag version và
                  `latest` sẽ nói dối về nội dung ảnh.
  --no-latest     Không dời tag `latest` (dùng khi phát hành bản vá cho một
                  dòng version cũ hơn bản đang chạy).
  --force         Đẩy đè một tag version đã có trên registry.
  -h, --help      In trợ giúp này.

Biến môi trường:
  GHCR_TOKEN   PAT có scope `write:packages`. Không đặt thì script mượn token
               của `gh` CLI.
  PLATFORMS    Mặc định linux/amd64,linux/arm64.
  IMAGE_OWNER  Mặc định quocnguyen2001.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)     DRY_RUN=true ;;
    --allow-dirty) ALLOW_DIRTY=true ;;
    --no-latest)   PUSH_LATEST=false ;;
    --force)       FORCE=true ;;
    -h|--help)     usage; exit 0 ;;
    *) echo "Tuỳ chọn không hiểu: $1" >&2; echo >&2; usage >&2; exit 2 ;;
  esac
  shift
done

# ─── Tiện ích ───────────────────────────────────────────────────────────────

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mCẢNH BÁO:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mLỖI:\033[0m %s\n' "$*" >&2; exit 1; }

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ─── Version ────────────────────────────────────────────────────────────────

[[ -f VERSION ]] || die "Không thấy file VERSION ở thư mục gốc."
VERSION="$(tr -d '[:space:]' < VERSION)"

# Semver rút gọn: cho phép hậu tố kiểu 1.2.3-rc1, chặn "v1.2.3" và "1.2".
# Tag ảnh sai định dạng chỉ lộ ra sau khi đã đẩy lên registry.
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
  || die "VERSION không hợp lệ: '$VERSION' (cần dạng 1.2.3 hoặc 1.2.3-rc1, không có tiền tố 'v')."

MAJOR_MINOR="${VERSION%.*}"

# ─── Trạng thái git ─────────────────────────────────────────────────────────

git rev-parse --git-dir >/dev/null 2>&1 || die "Không phải repo git."

SHA_SHORT="$(git rev-parse --short HEAD)"
SHA_FULL="$(git rev-parse HEAD)"
DIRTY=false
# Dạng `[[ ... ]] && VAR=true` sẽ tự kết liễu script dưới `set -e` mỗi khi vế
# trái sai — tức là ĐÚNG lúc working tree sạch, ca chạy thường xuyên nhất.
if [[ -n "$(git status --porcelain)" ]]; then DIRTY=true; fi

if [[ "$DIRTY" == true && "$ALLOW_DIRTY" == false ]]; then
  die "Working tree còn thay đổi chưa commit — ảnh dựng ra sẽ không truy được về commit nào.
     Commit trước, hoặc chạy lại với --allow-dirty (chỉ đẩy tag sha-...-dirty)."
fi

GIT_TAG="v$VERSION"
if git rev-parse -q --verify "refs/tags/$GIT_TAG" >/dev/null; then
  if [[ "$(git rev-list -n1 "$GIT_TAG")" != "$SHA_FULL" ]]; then
    warn "Tag $GIT_TAG trỏ vào commit KHÁC HEAD. Ảnh :$VERSION sẽ mang nội dung của HEAD, không phải của tag."
  fi
else
  warn "Chưa có tag git $GIT_TAG cho version này. Tạo sau khi đẩy ảnh:
           git tag -a $GIT_TAG -m '$GIT_TAG' && git push origin $GIT_TAG"
fi

# ─── Tag ảnh ────────────────────────────────────────────────────────────────
#
# Tag `sha-` là tag DUY NHẤT không bao giờ bị dời, nên nó là thứ để rollback và
# để đối chiếu ảnh đang chạy với commit.

TAGS=()
if [[ "$DIRTY" == true ]]; then
  warn "Working tree bẩn: chỉ đẩy tag sha-$SHA_SHORT-dirty, KHÔNG đẩy :$VERSION và :latest."
  TAGS+=("sha-$SHA_SHORT-dirty")
else
  TAGS+=("$VERSION" "$MAJOR_MINOR" "sha-$SHA_SHORT")
  if [[ "$PUSH_LATEST" == true ]]; then TAGS+=("latest"); fi
fi

TAG_ARGS=()
for t in "${TAGS[@]}"; do TAG_ARGS+=(--tag "$IMAGE:$t"); done

# ─── Kiểm tra trước khi dựng ────────────────────────────────────────────────
#
# Mọi thứ có thể hỏng đều kiểm ở đây. Build đa kiến trúc mất vài phút, và hỏng
# đăng nhập ở phút thứ năm thì mất trắng cả build.

command -v docker >/dev/null || die "Không tìm thấy docker."
docker buildx version >/dev/null 2>&1 || die "Không có docker buildx (cần Docker 19.03+ hoặc plugin buildx)."

ghcr_login() {
  local token
  if [[ -n "${GHCR_TOKEN:-}" ]]; then
    token="$GHCR_TOKEN"
    info "Đăng nhập $REGISTRY bằng \$GHCR_TOKEN"
  elif command -v gh >/dev/null 2>&1; then
    # Token mặc định của `gh` KHÔNG có `write:packages` — scope đó không nằm
    # trong bộ `gh auth login` xin lúc đầu. Kiểm trước, vì lỗi thật sự chỉ hiện
    # ra ở bước push cuối cùng.
    if ! gh auth status 2>&1 | grep -q 'write:packages'; then
      die "gh CLI chưa đăng nhập, hoặc token của nó thiếu scope 'write:packages'. Chọn một cách:
       gh auth refresh -h github.com -s write:packages
     hoặc tạo PAT tại https://github.com/settings/tokens (scope write:packages) rồi:
       export GHCR_TOKEN=<token>"
    fi
    token="$(gh auth token)"
    info "Đăng nhập $REGISTRY bằng token của gh CLI"
  else
    die "Không có \$GHCR_TOKEN và không có gh CLI. Đặt GHCR_TOKEN bằng một PAT có scope write:packages."
  fi
  echo "$token" | docker login "$REGISTRY" -u "$IMAGE_OWNER" --password-stdin >/dev/null \
    || die "Đăng nhập $REGISTRY thất bại."
}

if [[ "$DRY_RUN" == false ]]; then
  ghcr_login

  # Ảnh là bất biến: mỗi lần deploy một ảnh mới, và rollback là trỏ lại tag cũ
  # (README §Rollback). Đẩy đè :$VERSION làm hỏng đúng cơ chế đó — bản
  # "0.1.0" trên VPS sẽ không còn là bản 0.1.0 đã kiểm.
  if [[ "$DIRTY" == false && "$FORCE" == false ]] \
     && docker buildx imagetools inspect "$IMAGE:$VERSION" >/dev/null 2>&1; then
    die "Tag $IMAGE:$VERSION đã tồn tại trên registry.
     Tăng số trong file VERSION, hoặc dùng --force nếu thật sự muốn ghi đè."
  fi
fi

# Builder đa kiến trúc; --bootstrap để lỗi khởi động lộ ra ngay tại đây.
if ! docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
  info "Tạo builder '$BUILDER' (driver docker-container, cần cho ảnh đa kiến trúc)"
  docker buildx create --name "$BUILDER" --driver docker-container --bootstrap >/dev/null
fi

# ─── Dựng ảnh ───────────────────────────────────────────────────────────────

info "Ảnh      $IMAGE"
info "Version  $VERSION (commit $SHA_SHORT)"
info "Nền tảng $PLATFORMS"
info "Tag      ${TAGS[*]}"

OUTPUT_ARGS=(--push)
if [[ "$DRY_RUN" == true ]]; then
  info "DRY RUN: dựng xong sẽ bỏ, không đẩy lên registry."
  OUTPUT_ARGS=(--output type=cacheonly)
fi

# Nhãn OCI. `image.source` không phải trang trí: GitHub dựa vào nó để gắn
# package vào repo — thiếu nó thì package mồ côi, không thừa kế quyền truy cập
# của repo và không có link ngược về mã nguồn.
docker buildx build \
  --builder "$BUILDER" \
  --platform "$PLATFORMS" \
  --file "$DOCKERFILE" \
  --pull \
  --provenance="$PROVENANCE" \
  --build-arg "APP_VERSION=$VERSION" \
  --label "org.opencontainers.image.title=$IMAGE_NAME" \
  --label "org.opencontainers.image.description=REST API cho hanora" \
  --label "org.opencontainers.image.source=https://github.com/$IMAGE_OWNER/$IMAGE_NAME" \
  --label "org.opencontainers.image.version=$VERSION" \
  --label "org.opencontainers.image.revision=$SHA_FULL" \
  --label "org.opencontainers.image.created=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  "${TAG_ARGS[@]}" \
  "${OUTPUT_ARGS[@]}" \
  .

# ─── Tổng kết ───────────────────────────────────────────────────────────────

if [[ "$DRY_RUN" == true ]]; then
  info "Dựng xong cả $PLATFORMS. Không đẩy gì (--dry-run)."
  exit 0
fi

info "Đã đẩy:"
for t in "${TAGS[@]}"; do printf '      %s:%s\n' "$IMAGE" "$t"; done

cat <<EOF

Trên VPS:

  export HANORA_VERSION=$VERSION
  docker compose -f docker-compose.prod.yml pull
  docker compose -f docker-compose.prod.yml up -d

Rollback về bản trước (dùng tag version hoặc tag sha, cả hai đều không bị dời):

  HANORA_VERSION=<version-cũ> docker compose -f docker-compose.prod.yml up -d
EOF
