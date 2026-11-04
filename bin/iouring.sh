#!/usr/bin/env bash
# 为 PHP 8.4 的 Swoole 6.2.2 启用 io_uring / uring_socket
#
# 用法:
#   sudo ./iouring.sh
#
# 会备份现有 swoole.so，从 PECL 下载源码编译并安装，最后打印 uring 相关信息。
# 支持 Debian/Ubuntu (apt) 与 RHEL/CentOS/Rocky (dnf/yum)。
# 编译前检查并放开 kernel.io_uring_disabled（0=允许）。

set -euo pipefail

PHP_BIN="${PHP_BIN:-/www/server/php/84/bin/php}"
PHP_CONFIG="${PHP_CONFIG:-/www/server/php/84/bin/php-config}"
SWOOLE_VER="${SWOOLE_VER:-6.2.2}"
BUILD_DIR="${BUILD_DIR:-/tmp}"

enable_kernel_io_uring() {
  local key="kernel.io_uring_disabled"
  local conf="/etc/sysctl.d/99-io-uring.conf"
  local cur=""

  echo "==> 检查内核 io_uring（${key}）"

  if ! cur="$(sysctl -n "${key}" 2>/dev/null)"; then
    echo "警告: 当前内核没有 ${key}，可能过旧或不支持，跳过 sysctl 配置，继续编译。"
    return 0
  fi

  echo "当前 ${key}=${cur}"
  if [[ "${cur}" == "0" ]]; then
    echo "内核已允许 io_uring，无需修改。"
    return 0
  fi

  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "错误: ${key}=${cur}（非 0，io_uring 被限制），需要 root 才能写入 sysctl。请用: sudo ./iouring.sh" >&2
    exit 1
  fi

  echo "将 ${key} 从 ${cur} 改为 0（当前启动立即生效，并持久化）"
  sysctl -w "${key}=0"
  mkdir -p /etc/sysctl.d
  printf '%s\n' "${key} = 0" > "${conf}"
  if [[ -f /etc/sysctl.conf ]]; then
    # 去掉 sysctl.conf 里可能重复的同名项，避免与 drop-in 冲突
    if grep -qE "^[[:space:]]*${key}[[:space:]]*=" /etc/sysctl.conf; then
      tmp="$(mktemp)"
      grep -vE "^[[:space:]]*${key}[[:space:]]*=" /etc/sysctl.conf > "${tmp}"
      cat "${tmp}" > /etc/sysctl.conf
      rm -f "${tmp}"
      echo "已从 /etc/sysctl.conf 去除重复的 ${key} 行（以 ${conf} 为准）"
    fi
  fi
  if sysctl -p "${conf}" >/dev/null 2>&1; then
    echo "已加载 ${conf}"
  elif sysctl --system >/dev/null 2>&1; then
    echo "已执行 sysctl --system"
  else
    echo "警告: 持久化文件已写入 ${conf}，但 sysctl -p/--system 未成功；运行时已用 sysctl -w 设为 0。"
  fi
  echo "生效后 ${key}=$(sysctl -n "${key}")"
}

enable_kernel_io_uring

if [[ ! -x "$PHP_BIN" ]]; then
  echo "找不到 PHP: $PHP_BIN" >&2
  exit 1
fi
if [[ ! -x "$PHP_CONFIG" ]]; then
  echo "找不到 php-config: $PHP_CONFIG" >&2
  exit 1
fi

echo "==> 安装编译依赖"
if command -v apt-get >/dev/null 2>&1; then
  apt-get install -y \
    build-essential autoconf \
    libssl-dev libcurl4-openssl-dev libc-ares-dev \
    liburing-dev
elif command -v dnf >/dev/null 2>&1; then
  dnf install -y \
    gcc gcc-c++ make autoconf \
    openssl-devel libcurl-devel c-ares-devel \
    liburing liburing-devel
elif command -v yum >/dev/null 2>&1; then
  yum install -y \
    gcc gcc-c++ make autoconf \
    openssl-devel libcurl-devel c-ares-devel \
    liburing liburing-devel
else
  echo "找不到 apt-get / dnf / yum，请先手动安装编译依赖与 liburing-devel。" >&2
  exit 1
fi

EXT_DIR="$("$PHP_CONFIG" --extension-dir)"
SWOOLE_SO="$EXT_DIR/swoole.so"
if [[ -f "$SWOOLE_SO" ]]; then
  BAK="$SWOOLE_SO.bak-$(date +%Y%m%d%H%M)"
  echo "==> 备份现有 swoole.so -> $BAK"
  cp "$SWOOLE_SO" "$BAK"
fi

TGZ="swoole-${SWOOLE_VER}.tgz"
SRC_DIR="$BUILD_DIR/swoole-${SWOOLE_VER}"

echo "==> 下载 Swoole ${SWOOLE_VER}"
cd "$BUILD_DIR"
curl -fL -o "$TGZ" "https://pecl.php.net/get/${TGZ}"
rm -rf "$SRC_DIR"
tar xf "$TGZ"
cd "$SRC_DIR"

echo "==> 配置并编译（enable-iouring / enable-uring-socket）"
PHPIZE="$(dirname "$PHP_BIN")/phpize"
"$PHPIZE"
./configure --with-php-config="$PHP_CONFIG" \
  --enable-openssl --enable-sockets --enable-swoole-curl --enable-cares \
  --enable-iouring --enable-uring-socket
make -j"$(nproc)"
make install

echo "==> 验证"
"$PHP_BIN" -d disable_functions= --ri swoole | grep -iE "version|uring" || true

echo "完成。如进程已在跑，请 reload / restart 后再生效。"
