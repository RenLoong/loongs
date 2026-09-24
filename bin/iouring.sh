#!/usr/bin/env bash
# 为 PHP 8.4 的 Swoole 6.2.2 启用 io_uring / uring_socket
#
# 用法:
#   sudo ./iouring.sh
#
# 会备份现有 swoole.so，从 PECL 下载源码编译并安装，最后打印 uring 相关信息。

set -euo pipefail

PHP_BIN="${PHP_BIN:-/www/server/php/84/bin/php}"
PHP_CONFIG="${PHP_CONFIG:-/www/server/php/84/bin/php-config}"
SWOOLE_VER="${SWOOLE_VER:-6.2.2}"
BUILD_DIR="${BUILD_DIR:-/tmp}"

if [[ ! -x "$PHP_BIN" ]]; then
  echo "找不到 PHP: $PHP_BIN" >&2
  exit 1
fi
if [[ ! -x "$PHP_CONFIG" ]]; then
  echo "找不到 php-config: $PHP_CONFIG" >&2
  exit 1
fi

echo "==> 安装编译依赖"
apt-get install -y \
  build-essential autoconf \
  libssl-dev libcurl4-openssl-dev libc-ares-dev \
  liburing-dev

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
