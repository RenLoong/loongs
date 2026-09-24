#!/usr/bin/env bash
# 一键移除宝塔 PHP 的 disable_functions
#
# 用法:
#   sudo ./remove_disable_functions.sh               # 清空 PHP 8.4 的全部禁用函数
#   sudo ./remove_disable_functions.sh --swoole      # 只移除 Swoole 常用的函数（pcntl_*、putenv、exec 等）
#   sudo ./remove_disable_functions.sh --php=83      # 指定 PHP 版本目录（默认 84）
#   sudo ./remove_disable_functions.sh --dry-run     # 只显示改动，不写文件
#   sudo ./remove_disable_functions.sh --restore     # 从最近一次备份还原
#
# 会处理 php.ini（FPM）和 php-cli.ini（命令行），改之前自动备份为 *.bak.<时间>，
# 改完后如果存在 php-fpm 服务会顺带 reload。

set -euo pipefail

PHP_VER="84"
MODE="all"
DRY_RUN=0

SWOOLE_FUNCS="pcntl_alarm pcntl_fork pcntl_waitpid pcntl_wait pcntl_wifexited pcntl_wifstopped \
pcntl_wifsignaled pcntl_wifcontinued pcntl_wexitstatus pcntl_wtermsig pcntl_wstopsig pcntl_signal \
pcntl_signal_dispatch pcntl_get_last_error pcntl_strerror pcntl_sigprocmask pcntl_sigwaitinfo \
pcntl_sigtimedwait pcntl_exec pcntl_getpriority pcntl_setpriority \
putenv exec shell_exec system passthru popen proc_open symlink readlink chown chgrp"

for arg in "$@"; do
  case "$arg" in
    --php=*)   PHP_VER="${arg#--php=}" ;;
    --swoole)  MODE="swoole" ;;
    --all)     MODE="all" ;;
    --restore) MODE="restore" ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
    *) echo "未知参数: $arg（用 --help 查看用法）" >&2; exit 1 ;;
  esac
done

ETC_DIR="${PHP_ETC_DIR:-/www/server/php/${PHP_VER}/etc}"
INI_FILES=("$ETC_DIR/php.ini" "$ETC_DIR/php-cli.ini")
FPM_SERVICE="/etc/init.d/php-fpm-${PHP_VER}"
PHP_BIN="/www/server/php/${PHP_VER}/bin/php"

if [[ ! -d "$ETC_DIR" ]]; then
  echo "找不到目录 $ETC_DIR，请用 --php=版本号 指定" >&2; exit 1
fi

if [[ $DRY_RUN -eq 0 && $EUID -ne 0 && ! -w "$ETC_DIR" ]]; then
  echo "需要 root 权限，正在用 sudo 重新执行..."
  exec sudo -E bash "$0" "$@"
fi

current_list() {
  grep -E '^[[:space:]]*disable_functions[[:space:]]*=' "$1" | tail -n1 \
    | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]]//g; s/^"//; s/"$//'
}

changed=0
for ini in "${INI_FILES[@]}"; do
  [[ -f "$ini" ]] || { echo "跳过（不存在）: $ini"; continue; }

  if [[ "$MODE" == "restore" ]]; then
    latest=$(ls -1t "$ini".bak.* 2>/dev/null | head -n1 || true)
    if [[ -z "$latest" ]]; then echo "没有备份可还原: $ini"; continue; fi
    echo "还原 $ini  <=  $latest"
    [[ $DRY_RUN -eq 1 ]] || cp -p "$latest" "$ini"
    changed=1
    continue
  fi

  before=$(current_list "$ini")
  if [[ "$MODE" == "all" ]]; then
    after=""
  else
    after=$(tr ',' '\n' <<<"$before" | grep -v -x -F -f <(tr ' ' '\n' <<<"$SWOOLE_FUNCS" | sed '/^$/d') | sed '/^$/d' | paste -sd, - || true)
  fi

  echo "== $ini"
  echo "   原来: ${before:-（空）}"
  echo "   之后: ${after:-（空）}"

  if [[ "$before" == "$after" ]]; then echo "   无需修改"; continue; fi
  [[ $DRY_RUN -eq 1 ]] && continue

  backup="$ini.bak.$(date +%Y%m%d%H%M%S)"
  cp -p "$ini" "$backup"
  echo "   已备份: $backup"
  if grep -qE '^[[:space:]]*disable_functions[[:space:]]*=' "$ini"; then
    sed -i -E "s|^[[:space:]]*disable_functions[[:space:]]*=.*$|disable_functions = ${after}|" "$ini"
  else
    echo "disable_functions = ${after}" >> "$ini"
  fi
  changed=1
done

if [[ $DRY_RUN -eq 1 ]]; then echo "（dry-run，未写入任何文件）"; exit 0; fi

if [[ $changed -eq 1 && -x "$FPM_SERVICE" ]]; then
  echo "重载 php-fpm-${PHP_VER} ..."
  "$FPM_SERVICE" reload || echo "php-fpm reload 失败，请手动重启" >&2
fi

if [[ -x "$PHP_BIN" ]]; then
  echo "当前 CLI 生效值: $("$PHP_BIN" -r 'echo ini_get("disable_functions") ?: "(空)";')"
fi
echo "完成。已经在运行的 Swoole 服务需要重启后才会生效。"
