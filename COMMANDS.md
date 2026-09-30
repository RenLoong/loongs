# loongs 命令速查（server/）

本文件列出 `server/` 下所有可执行命令及用法，内容取自 `./loongs list --no-ansi` / `./loongs help <命令> --no-ansi` 的真实输出。
命令行基于 **symfony/console**（`Loongs\Console\Kernel`），业务逻辑在 `Loongs\Process\ProcessManager` / `Loongs\Rpc\HotReload\RpcServiceManager`。

> 查看最新帮助：`./loongs list`、`./loongs help <命令>`（加 `--no-ansi` 得到纯文本）。

---

## 0. 如何运行

在 `server/` 目录下：

```bash
./loongs <命令> [参数] [选项]
# 宝塔等面板的 PHP 常禁用 pcntl_* 等函数，推荐显式放开：
/www/server/php/84/bin/php -d disable_functions= loongs <命令> [参数] [选项]
```

- `server/loongs` 是入口脚本（原名 `server/start`，已更名；命令名 `start` 不变）：定义 `LOONGS_BASE_PATH = __DIR__`，加载 `vendor/autoload.php`，运行控制台。缺少 vendor 时提示 `Autoloader not found. Run: composer update` 并退出 1。
- 不带命令时默认执行 `start`：`./loongs` 等价于 `./loongs start`，`./loongs --only=http,rpc` 同样有效。
- 可在任意目录用绝对路径执行（如 `/www/wwwroot/loong-swoole/server/loongs status`），基准目录固定为 `loongs` 所在目录。

## 1. 命令总览

| 命令 | 说明 |
|---|---|
| `start` | 启动进程管理器（http / rpc / websocket / queue / crontab / custom） |
| `stop` | 停止运行中的 master（SIGTERM，30 秒后 SIGKILL）；master 已不在时清理残留孤儿进程 |
| `restart` | 先 stop（如在运行）再 start |
| `reload` | 平滑重载：master 按角色重载子进程并加载新代码，在途请求处理完，不产生崩溃式退出（见 3.4） |
| `status` | 显示 master 状态与进程表（未运行 / 孤儿残留时退出码 1） |
| `rpc:show` | 显示生效的 rpc.services（来源 config / override）及覆盖文件状态 |
| `rpc:switch` | 热切换某个 service 的 transport：local / loopback / remote（无需重启） |
| `rpc:set` | 用 JSON 整体替换某个 service 配置（多实例、权重、metadata…） |
| `rpc:reset` | 删除运行时覆盖，回到 `config/rpc.php` |
| `completion` | 输出 shell 自动补全脚本 |
| `help` | 显示某个命令的帮助 |
| `list` | 列出所有命令 |

## 2. 全局选项（所有命令可用）

| 选项 | 说明 |
|---|---|
| `-h, --help` | 显示该命令帮助（不带命令时显示 `start` 的帮助） |
| `--silent` | 不输出任何信息 |
| `-q, --quiet` | 只输出错误，其余全部屏蔽（退出码不变） |
| `-V, --version` | 显示版本（如 `Loongs dev-main@<commit>`） |
| `--ansi` / `--no-ansi` | 强制开启 / 关闭颜色。输出不是终端（管道、重定向、日志文件）时自动无颜色 |
| `-n, --no-interaction` | 不进行任何交互询问 |
| `-v` / `-vv` / `-vvv`, `--verbose` | 提高输出详细程度（如 `status -v` 额外显示 log 文件与 daemonize） |

通用退出码：成功 `0`；未知命令、缺少参数、校验失败等为 `1`。

---

## 3. 进程管理命令

### 3.1 `start` — 启动

启动 master 并按 `config/process.php` 的 `processes` 派生所有 enabled 子进程。默认前台运行，直到收到 SIGTERM/SIGINT（即 `stop` 或 Ctrl+C）；`-d` 为后台运行。

启动时依次输出：
1. **启动横幅**：框架名称 + 核心版本（`loongs/framework` 的 Composer 版本；dev 分支附带 commit，本地 path/symlink 安装取 checkout 的 HEAD）、应用名称（`APP_NAME` → `config('app.name')`，只允许字母/数字/下划线，为空或未设时为 `loongs`，见 [3.7](#37-app_name-与同机多服务)）、env/debug、PHP 与 Swoole 版本、RPC io_uring 状态（`-v` 附带原因）、base path、进程标题（`Titles  loong-swoole[<APP_NAME>]: master / <role>`）、pid 文件、日志去向、前台/daemon。
2. **运行日志**：master 与子进程统一为 `[时间] 级别 [标签] 消息`；标签为 `master`、`<进程名>#<序号>`（RPC 多 worker 时为 `rpc#0/w1`），按最长标签对齐。io_uring 状态行只由 RPC 进程输出一次。
3. **进程表**：所有子进程就绪后打印（有端口的进程以端口可连接为准，其余存活 300ms 视为 running，最多等 5s），列出 process / type / app / listen / workers / pid / state / backend（rpc 显示 `uring_socket` 或 `epoll`），随后是 `ProcessManager started ... ready in Xms`。
4. **停止**（Ctrl+C / `stop`）：`shutting down (SIGINT)` → 各子进程 `child exit ... code=0 signal=0 uptime=... stopped in ...` → `ProcessManager exited shutdown=... uptime=...`。Ctrl+C 时子进程忽略终端发来的 SIGINT，由 master 统一发 SIGTERM 平滑停止。停止过程中**再按一次 Ctrl+C**（或再发一次 SIGTERM/SIGINT）即强制退出：见 [3.6](#36-实例锁孤儿进程与强制退出)。

颜色：仅当 stdout 是终端时着色；`--no-ansi`、管道/重定向、日志文件、daemon 模式均为纯文本。`-q` 隐藏横幅和进程表（运行日志照常输出）。

```
start [options]
```

| 选项 | 说明 |
|---|---|
| `--only=ONLY` | 只启动这些进程：逗号分隔；可写精确名，或用 app 通配（如 `user.*`）；可重复 |
| `-d, --daemon` | 后台运行（覆盖 `process.daemonize` / `PROCESS_DAEMONIZE`） |

退出码：正常退出 `0`；以下情况报错并返回 `1`（均不会派生任何子进程）：
- 已在运行（含正在平滑停止中）：`Already running (pid N). Use stop/reload/status.`
- 其他项目有同名（相同 `APP_NAME`）服务在运行：`Another service with APP_NAME "…" is already running from a different project: pid N … (project …)`，不创建任何文件（见 [3.7](#37-app_name-与同机多服务)）。
- 另一个 `start` 正在启动：`Another start is in progress (pid N).`
- 上次的 master 已死但子进程仍存活：`The master is gone but N process(es) of the previous run are still alive (...). Run ./loongs stop to clean them up.`
- 配置端口已被占用：`Port 127.0.0.1:19501 for [http] is already in use by pid N <cmdline> (Address already in use). Free the port or change it in .env / config/process.php.`
- 没有可启动的进程。
- 被第二次 Ctrl+C / SIGTERM 强制退出（前台）。

```bash
./loongs                              # 同 ./loongs start
./loongs start --only=http,rpc
./loongs start --only='user.*' -d
```

前台启动 + Ctrl+C 的真实输出（`--no-ansi`；测试端口 19501/19502、`HTTP_WORKER_NUM=2`，pid/log 文件为测试用的 `*-demo.*`）：

```text
$ ./loongs start --only=http,rpc --no-ansi
 Loongs dev-main@4a2d8cd  ·  loongs

  Framework  Loongs dev-main@4a2d8cd (loongs/framework)
  App        loongs  env=local  debug=on
  Runtime    PHP 8.4.25  ·  Swoole 6.2.2
  io_uring   on  network=uring_socket  coverage=file+network  mode=auto
  Base path  /www/wwwroot/loong-swoole/server
  Pid file   runtime/loong-swoole-demo.pid
  Log        stdout (runtime/loong-swoole-demo.log in daemon mode)
  Mode       foreground (Ctrl+C or ./loongs stop)

[2026-09-30 11:36:04] INFO  [master] spawned http#0 type=http pid=423651
[2026-09-30 11:36:04] INFO  [master] spawned rpc#0 type=rpc pid=423652
[2026-09-30 11:36:04] INFO  [http#0] HTTP server starting Swoole\Http\Server on 127.0.0.1:19501 workers=2
[2026-09-30 11:36:04] INFO  [rpc#0]  RPC io_uring backend=uring_socket (active) network=uring_socket/on mode=auto coverage=file+network reason=Coroutine\Http\Server + Coroutine\Http\Client use UringSocket (compile-time SocketImpl)
[2026-09-30 11:36:04] INFO  [rpc#0]  RPC server starting Coroutine\Http\Server network=uring_socket on 127.0.0.1:19502 path=/rpc workers=1
 --------- ------ ----- ----------------- --------- -------- ----------- --------------
  process   type   app   listen            workers   pid      state       backend
 --------- ------ ----- ----------------- --------- -------- ----------- --------------
  http      http   -     127.0.0.1:19501   2         423651   listening   epoll
  rpc       rpc    -     127.0.0.1:19502   1         423652   listening   uring_socket
 --------- ------ ----- ----------------- --------- -------- ----------- --------------

[2026-09-30 11:36:04] INFO  [master] ProcessManager started pid=423646 children=2 ready in 59ms
[2026-09-30 11:36:05] INFO  [master] shutting down (SIGINT): stopping 2 children with SIGTERM
[2026-09-30 11:36:05] INFO  [rpc#0]  RPC stopped (SIGTERM) in-flight=0 drained in 0ms
[2026-09-30 11:36:05] INFO  [master] child exit http#0 pid=423651 code=0 signal=0 uptime=994ms stopped in 101ms
[2026-09-30 11:36:05] INFO  [master] child exit rpc#0 pid=423652 code=0 signal=0 uptime=993ms stopped in 101ms
[2026-09-30 11:36:05] INFO  [master] ProcessManager exited shutdown=102ms uptime=996ms
```

`-d` 后台启动：终端只打印横幅和计划表（pid 未知，state=starting），之后 master 与所有子进程的 stdout/stderr（运行日志、PHP 警告、Swoole 日志）都写入 `process.log_file`（纯文本）：

```text
$ ./loongs start --only=http,rpc -d

 Loongs dev-main@4a2d8cd  ·  loongs

  Framework  Loongs dev-main@4a2d8cd (loongs/framework)
  App        loongs  env=local  debug=on
  Runtime    PHP 8.4.25  ·  Swoole 6.2.2
  io_uring   on  network=uring_socket  coverage=file+network  mode=auto
  Base path  /www/wwwroot/loong-swoole/server
  Pid file   runtime/loong-swoole-demo.pid
  Log        runtime/loong-swoole-demo.log
  Mode       daemon

 --------- ------ ----- ----------------- --------- ----- ---------- --------------
  process   type   app   listen            workers   pid   state      backend
 --------- ------ ----- ----------------- --------- ----- ---------- --------------
  http      http   -     127.0.0.1:19501   2         -     starting   epoll
  rpc       rpc    -     127.0.0.1:19502   1         -     starting   uring_socket
 --------- ------ ----- ----------------- --------- ----- ---------- --------------

 Running in the background. Logs: runtime/loong-swoole-demo.log · ./loongs status · ./loongs stop
```

### 3.2 `stop` — 停止

```
stop
```

向 master 发 SIGTERM，等待 master 退出**且**实例锁释放（即所有子进程也已退出），超过 30 秒则 SIGKILL master 及仍持有锁的全部进程。

- 是否在运行由实例锁判断（见 3.6），pid 文件里的 pid 若已被系统复用给无关进程，**不会**被发信号，只提示 `Not running.` 并删除陈旧 pid 文件。
- master 已不在但子进程残留（孤儿）时：提示 `Master is gone; stopping N orphaned process tree(s) (M processes) with SIGTERM: ...`，SIGTERM 各孤儿进程树，10 秒后仍未退出则 SIGKILL。
- 第二次 SIGTERM：若 master 正在平滑停止（例如 RPC 在等待慢请求），`stop` 在等待期间再对 master 发一次 SIGTERM 即触发强制退出。

退出码：已停止、本来就没运行（`Not running.`）、孤儿已被 SIGTERM 清理为 `0`；超时后 SIGKILL（master 或孤儿）为 `1`。

孤儿清理的真实输出（master 与看门狗被 kill -9、rpc 子进程被 SIGSTOP 以模拟"不响应 SIGTERM"）：

```text
$ ./loongs stop --no-ansi
 [WARNING] Master is gone; stopping 2 orphaned process tree(s) (4 processes)
           with SIGTERM: pid 429268 loong-swoole[loongs]: http, pid 429270
           loong-swoole[loongs]: rpc
 [ERROR] Orphans ignored SIGTERM; sent SIGKILL to 429270 (10.09s).
$ echo $?
1
```

```bash
./loongs stop
```

### 3.3 `restart` — 重启

`stop`（SIGTERM，等待）后用相同选项 `start`。如只需平滑重载 worker，用 `reload`。

```
restart [options]
```

选项与 `start` 相同：`--only=ONLY`、`-d, --daemon`。

退出码：stop 失败则返回 stop 的退出码，否则返回 start 的退出码。

```bash
./loongs restart -d
./loongs restart --only=http,rpc
```

### 3.4 `reload` — 平滑重载

```
reload
```

向 master 发 SIGUSR1，master 按角色逐个平滑重载子进程并加载新代码（`apps/`、`routes`、RPC 服务类等在 worker 内加载的文件）；在途请求会处理完，重载期间新请求照常被服务，日志中不会出现崩溃式退出（`signal=10`、`restarting in …`）。是否在运行与 `stop`/`status` 同样按实例锁判断，不会向复用了 pid 的无关进程发信号。

| 角色 | 方式 |
| --- | --- |
| `http` / `websocket` / epoll 模式 `rpc` | 对该子进程发 SIGUSR1 → Swoole 平滑重启所有 worker（监听 socket 不关闭；旧 worker 处理完在途请求才退出；每个 worker 在 WorkerStart 中新建 Application，所以会加载新代码） |
| io_uring 模式 `rpc` | 先起替换进程，等它监听到端口（SO_REUSEPORT）后，再对旧进程发 SIGTERM；旧进程停止接收、排空在途请求（最多 10 秒）后以 0 退出，日志记 `replaced by reload`。不调用 `shutdown()`（避开 uring fd 0 问题） |
| `queue` / `crontab` / `custom` / app 进程 | SIGTERM（各自的平滑停止：当前任务做完），退出后 master 立即重建，日志为 INFO `reload, stopped in … → respawning`，不计入崩溃重启 |

子进程本身忽略直接收到的 SIGUSR1（只有 master 转发的才生效），误发 `kill -USR1 <子进程>` 不会杀死它。

**Swoole Server 模式**：HTTP 等 `Swoole\Server` 默认按 worker 数选择：`worker_num > 1` 为 `SWOOLE_BASE`，`worker_num ≤ 1` 以及 websocket 为 `SWOOLE_PROCESS`（BASE 单 worker 下无法重载 worker）。可在 `config/process.php` 对应进程里用 `'mode' => 'process'|'base'` 覆盖。

**reload 不生效、需要 `restart` 的**：`.env`、`config/process.php`（进程表、端口、worker 数）、master 本身的代码、框架引导阶段在 fork 前已加载的类。

退出码：已发送为 `0`；未运行（`Not running.`）为 `1`。

```bash
./loongs reload
```

实测（http×2 worker、uring rpc、websocket、queue、crontab、custom-example、user.stats；reload 前改代码 CODE_V1 → CODE_V2，并发起 4 秒的慢 RPC、3 秒的慢 HTTP，reload 后持续压 6 秒）：

```text
$ ./loongs reload --no-ansi
 [OK] Reload signal (SIGUSR1) sent to master pid 467483.
  http / websocket: Swoole worker reload · rpc (uring): replacement, then the old one drains · queue / crontab / custom: graceful respawn. Progress: the master log (reload: …).
requests during the 6s after reload: http ok=114 fail=0 · rpc ok=114 fail=0
slow RPC  (4s, started before reload): 200 4.000534s {"code":0,"message":"ok","data":{"slept":4,"pid":467486,"ver":"CODE_V1"},"id":"s"}
slow HTTP (3s, started before reload): 200 3.000597s {"code":0,"message":"ok","data":{"slept":3,"pid":467495,"ver":"CODE_V1"}}
after reload: http → "ver":"CODE_V2"   rpc → "ver":"CODE_V2"

INFO [master] reload: 7 children
INFO [master] reload: http#0 pid=467484 → SIGUSR1 (Swoole worker reload; listener stays open)
INFO [master] reload: rpc#0 new pid=467582 listening on :19502 after 28ms → SIGTERM old pid=467486 (drains in-flight requests, then exits)
INFO [rpc#0]  RPC stopping (SIGTERM): draining 1 in-flight request(s), max 10s
INFO [master] reload: queue#0 pid=467490 → SIGTERM, respawned when it exits
INFO [master] reload: done in 31ms
INFO [master] child exit crontab#0 pid=467494 code=0 signal=0 uptime=2.04s — reload, stopped in 301ms → respawning
INFO [master] child exit queue#0 pid=467490 code=0 signal=0 uptime=2.14s — reload, stopped in 404ms → respawning
INFO [rpc#0]  RPC stopped (SIGTERM) in-flight=1 drained in 3.43s
INFO [master] child exit rpc#0 pid=467486 code=0 signal=0 uptime=5.25s — replaced by reload, drained in 3.51s
signal=10 lines: 0 · 'restarting in' (crash-style) lines: 0
```

### 3.5 `status` — 状态

显示 master 状态、pid 文件，以及合并后（全局 + 各 app）的进程表。表列为 process / type / app / listen / count / pid / state，state 取值为 running、stopped、not running、orphaned、disabled。master 状态按实例锁判断（pid 文件 + 锁被持有 + 该 pid 是锁持有者）；陈旧 pid 文件只在确认无人持锁时才删除。pid / 锁文件在运行中被删除时仍显示 running，并附 `note:`（master 1 秒内自愈，见 3.6）。master 已不在但仍有本实例进程时显示 `orphaned` 及 `→ run ./loongs stop to clean them up`。只读操作，不影响运行中的服务。

```
status [options]
```

| 选项 | 说明 |
|---|---|
| `--only=ONLY` | 只把这些进程标记为选中（语法同 `start --only`，可重复） |

退出码：运行中 `0`；未运行或孤儿残留 `1`。

```bash
./loongs status
./loongs status -v          # 额外显示 log file / daemonize
./loongs status --no-ansi   # 纯文本（脚本解析用）
```

未运行时的真实输出示例（`--no-ansi`）：

```
Loongs status
=============

  master: stopped
  pid file: /www/wwwroot/loong-swoole/server/runtime/loong-swoole.pid

 ----------------- ----------- --------- -------------- -------- ----- ---------- 
  process           type        app       listen         count    pid   state     
 ----------------- ----------- --------- -------------- -------- ----- ---------- 
  http              http        -         0.0.0.0:9501   1 × 1w   -     stopped   
  rpc               rpc         -         0.0.0.0:9502   1 × 1w   -     stopped   
  websocket         websocket   -         0.0.0.0:9503   1 × 1w   -     disabled  
  queue             queue       -         -              1        -     disabled  
  crontab           crontab     -         -              1        -     disabled  
  custom-example    custom      -         -              1        -     disabled  
  user.stats        custom      User      -              1        -     disabled  
  website.crontab   crontab     Website   -              1        -     disabled  
 ----------------- ----------- --------- -------------- -------- ----- ---------- 
```

（`user.stats` / `website.crontab` 来自本地 apps，具体以你的 apps 为准。）

孤儿残留时（节选）：

```text
  master: stopped
  orphaned: the master is gone but 2 process(es) are still alive: pid 429268 loong-swoole[loongs]: http, pid 429270 loong-swoole[loongs]: rpc
  → run ./loongs stop to clean them up
  ...
  http              http        -         127.0.0.1:19501   1 × 2w   429268   orphaned
  rpc               rpc         -         127.0.0.1:19502   1 × 1w   429270   orphaned
```

### 3.6 实例锁、孤儿进程与强制退出

**实例锁**：pid 文件旁有锁文件 `<pid 文件去掉 .pid>.lock`（默认 `runtime/loongs.lock`；`.env` 设了 `PROCESS_PID_FILE=runtime/loong-swoole.pid` 时为 `runtime/loong-swoole.lock`；永不删除）。`start` 在任何 fork / daemonize **之前**以 `flock(LOCK_EX|LOCK_NB)` 取锁（最多重试 1 秒），取到后立刻写 pid 文件，daemonize 后再以 daemon 的 pid 覆盖。锁的打开文件描述会被所有子进程继承，因此只要本实例还有任何进程活着，锁就一直被持有：

- 同时执行多个 `start`（实测 10 个并发）只有一个成功，其余报 `Already running (pid N)`（或 `Another start is in progress`），退出码 1。
- 平滑停止期间（RPC 在排空请求）再 `start` 会被拒绝：`Already running (pid N)`。
- `status` / `stop` / `reload` / `start` 都以"锁被持有且 pid 文件里的 pid 是锁持有者"判断是否在运行，不再只看 `posix_kill(pid, 0)`；pid 被复用给无关进程时视为未运行，也不会给它发信号。

**pid / 锁文件在运行中被删除或替换（自愈）**：

- 兜底识别 master：pid 文件缺失 / 内容不对、或锁文件被删除 / 替换时，`status` / `stop` / `reload` / `start` 通过 `/proc/*/fd` 找**打开着本实例锁路径**（当前文件，或已删除的 inode `<路径> (deleted)`）且进程名为 `loong-swoole[<APP_NAME>]: master` 的进程。锁路径由 pid 文件派生、每个实例唯一，标题又带 APP_NAME，不会认错其他实例或其他服务。
- master 每 1 秒自检（`HEAL_INTERVAL`）：pid 文件缺失或不是自己的 pid → 重写；锁文件缺失或 inode 与自己持有的不同 → 重新创建并 `flock(LOCK_EX|LOCK_NB)`。两者都记 WARN。子进程继续持有旧（已删除）的 inode，`status` / `stop` / 孤儿检测照样能找到它们。若新锁已被别的进程持有，记一次 ERROR 并继续运行，对方释放后自动接管。
- 自愈完成前 `status` 仍显示 running，并附 `note:`；`start`（包括只启动无端口进程的 `--only`）一律 `Already running (pid N)`，不会起第二个实例；`stop`、`reload` 正常。
- `start` 端口预检失败时，只删除内容是自己 pid 的 pid 文件。

实测（`-d`，同时删除 pid 与锁文件；只启动无端口进程的 `start --only=custom-example -d` 同样被拒绝）：

```text
$ rm runtime/loong-swoole-edge.pid runtime/loong-swoole-edge.lock
$ ./loongs status --no-ansi
  master: running  pid=445189
  note: pid file missing or invalid — the master rewrites it within ~1s
  note: lock file missing — the master re-creates it within ~1s
$ ./loongs start --only=http,rpc --no-ansi
 [ERROR] Already running (pid 445189). Use stop/reload/status.
# runtime/loong-swoole-edge.log
[2026-09-30 13:29:39] WARN  [master] pid file /www/wwwroot/loong-swoole/server/runtime/loong-swoole-edge.pid was missing: rewritten with pid 445189
[2026-09-30 13:29:39] WARN  [master] lock file /www/wwwroot/loong-swoole/server/runtime/loong-swoole-edge.lock was missing: re-created and locked (children keep the unlinked inode; status/stop still find them)
```

锁文件被替换且被别的进程持有时：

```text
[2026-09-30 13:29:47] ERROR [master] lock file /www/wwwroot/loong-swoole/server/runtime/loong-swoole-edge.lock was replaced and is locked by another process (pid 446031 flock -n runtime/loong-swoole-edge.lock sleep 4, pid 446033 sleep 4); still running — check ./loongs status
[2026-09-30 13:29:51] WARN  [master] lock file /www/wwwroot/loong-swoole/server/runtime/loong-swoole-edge.lock was replaced: re-created and locked (children keep the unlinked inode; status/stop still find them)
```

**端口预检**：取锁后、fork 之前逐个 bind 配置端口（**不**设 SO_REUSEPORT，因此即使对方用 SO_REUSEPORT 监听、而 RPC 本身使用 reuse_port 也能发现冲突），被占用时从 `/proc/net/tcp*` 找出占用者 pid 与命令行并拒绝启动（退出码 1，pid 文件被清除、锁被释放）。

**master 意外死亡（kill -9、终端关闭）**：每个子进程在启动角色前 fork 一个看门狗（进程名 `loong-swoole[<APP_NAME>]: watchdog <tag>`），每 200ms 检查 master 是否仍在；master 消失后看门狗对子进程发 SIGTERM（平滑退出），15 秒后仍未退出则 SIGKILL 其整棵进程树。实测 kill -9 master 后 0.38s 内所有进程退出，端口释放，下次 `start` 正常。若仍有残留（看门狗也被杀、子进程卡死），`status` 显示 `orphaned`，`start` 拒绝并提示运行 `./loongs stop`，`stop` 负责清理（见 3.2）。

**强制退出**：平滑停止期间第二次收到 SIGINT / SIGTERM（前台再按一次 Ctrl+C，或 daemon 再 `kill -TERM`），master 立刻 SIGKILL 剩余子进程（含看门狗），删除 pid 文件、释放锁并记录：

```text
[2026-09-30 12:18:13] INFO  [master] shutting down (SIGINT): stopping 2 children with SIGTERM
[2026-09-30 12:18:13] INFO  [rpc#0]  RPC stopping (SIGTERM): draining 1 in-flight request(s), max 10s
[2026-09-30 12:18:13] INFO  [master] child exit http#0 pid=428924 code=0 signal=0 uptime=695ms stopped in 101ms
[2026-09-30 12:18:14] WARN  [master] SIGINT again during shutdown (after 1.52s): force exit — SIGKILL 2 process(es): 428926,428927
[2026-09-30 12:18:14] WARN  [master] child exit rpc#0 pid=428926 code=0 signal=9 uptime=2.12s stopped in 1.54s
[2026-09-30 12:18:14] INFO  [master] ProcessManager exited (forced) shutdown=1.54s uptime=2.14s
```

前台被强制退出时 `start` 的退出码为 `1`（实测第二次 Ctrl+C 后 0.05s 退出，无残留进程，端口已释放；在途请求被中断）。

**停止超时一览**：

| 阶段 | 时长 | 超时后 |
|---|---|---|
| RPC 排空在途请求 | `min(max_wait_time, 10)` 秒 | 直接退出事件循环 |
| master 等待子进程退出（`STOP_GRACE`） | 15 秒 | SIGKILL 子进程树 |
| `./loongs stop` 等待 master + 锁释放 | 30 秒 | SIGKILL master 及所有锁持有者 |
| `./loongs stop` 清理孤儿 | 10 秒 | SIGKILL |
| 看门狗：master 消失后等待子进程（`ORPHAN_GRACE`） | 15 秒 | SIGKILL 子进程树 |
| 第二次 Ctrl+C / SIGTERM | 立即 | SIGKILL 全部，退出码 1 |
| master 自检 pid / 锁文件（`HEAL_INTERVAL`） | 每 1 秒 | 重写 pid 文件 / 重建并重新加锁 |

### 3.7 APP_NAME 与同机多服务

`APP_NAME`（`.env`）是服务名，出现在所有进程标题中，用来区分同一台机器上的多个服务；同一台机器上必须唯一（见下文“同名保护”）。

**规则**：只允许字母、数字、下划线：`^[A-Za-z0-9_]+$`。空值或未设置时为 `loongs`。`start` / `restart` / `stop` / `reload` / `status` 在任何 fork 之前校验，不合法则打印错误并退出码 1（不会创建 pid / 锁 / 日志文件）；`rpc:*`、`list`、`help` 不依赖它，不做校验。

```text
$ ./loongs start --no-ansi            # APP_NAME=my-app
 [ERROR] Invalid APP_NAME "my-app": only letters, digits and underscores are
         allowed ([A-Za-z0-9_]+), e.g. APP_NAME=my_app. Fix APP_NAME in .env
         (empty or unset means "loongs").
$ echo $?
1
```

`中文`、`"my app"`（含空格）、`app.name` 同样被拒绝。

**进程标题**：`loong-swoole[<APP_NAME>]: <role>`

```text
loong-swoole[alpha]: master
loong-swoole[alpha]: http
loong-swoole[alpha]: rpc
loong-swoole[alpha]: custom-example
loong-swoole[alpha]: user.stats          # app 进程
loong-swoole[alpha]: watchdog http#0     # 看门狗
```

`ps -eo pid,args | grep '[l]oong-swoole\[alpha\]:'` 只列出 alpha 服务的进程。/proc 兜底找 master、孤儿检测、锁持有者、`status` / `stop` 都按本服务的前缀 `loong-swoole[<APP_NAME>]:` 匹配（外加锁路径），APP_NAME 不同的实例互不识别。端口被别的 loongs 服务占用时，端口预检会提示 `It belongs to another loongs service (other APP_NAME)`。

**默认文件名**：`PROCESS_PID_FILE` / `PROCESS_LOG_FILE` 留空时固定为框架名 `runtime/loongs.pid` / `runtime/loongs.log`，锁文件为 `runtime/loongs.lock`，与 `APP_NAME` 无关（改 APP_NAME 不会改文件名）。每个项目目录有自己的 `runtime/`，因此不同项目互不冲突；同一目录跑两个服务需显式设置不同的 `PROCESS_PID_FILE`。显式设置的值照常生效（例如本仓库 `.env` 里的 `PROCESS_PID_FILE=runtime/loong-swoole.pid`）。

**同机多服务**：每个服务使用不同的 `APP_NAME` + 端口（通常各自一个项目目录，默认文件都在各自的 `runtime/loongs.*`）。实测 alpha（19501/19502，`server/`）与 beta（19503，`/tmp/loongs-beta`）同时运行：

```text
     467135  466304 loong-swoole[alpha]: master
     467136  467135 loong-swoole[alpha]: http
     467137  467135 loong-swoole[alpha]: rpc
     467168  466304 loong-swoole[beta]: master
     467169  467168 loong-swoole[beta]: http
alpha$ ./loongs status      →  app: alpha … master: running  pid=467135 … pid file: …/server/runtime/loongs.pid
beta $ ./loongs status      →  app: beta  … master: running  pid=467168 … pid file: /tmp/loongs-beta/runtime/loongs.pid
alpha$ ./loongs start -d    →  [ERROR] Already running (pid 467135). Use stop/reload/status.
alpha$ ./loongs stop        →  [OK] Stopped (pid 467135, 1.01s).   beta 的 7 个进程全部仍在
kill -9 <alpha master>      →  alpha 的 9 个进程约 1.0s 内全部退出；beta 不受影响
```

**同名保护**：同一台机器上 `APP_NAME` 必须唯一。`start` / `restart` 在任何 fork、取锁、写文件之前扫描 `/proc`，找标题为 `loong-swoole[<APP_NAME>]: …` 的进程（master 或残留子进程）；若它不属于本项目实例（打开的锁文件路径 / 工作目录与本项目不同），拒绝启动、退出码 1、不创建任何文件，已在运行的那个服务不受影响。本项目自己已在运行时仍是原来的 `Already running (pid N)`。实测（`server` 与 `/tmp/loongs-beta` 都设 `APP_NAME=same`，端口不同）：

```text
beta$ ./loongs start -d --no-ansi          # start / restart -d 同样
 [ERROR] Another service with APP_NAME "same" is already running from a
         different project: pid 466373 loong-swoole[same]: master (project
         /www/wwwroot/loong-swoole/server, lock
         /www/wwwroot/loong-swoole/server/runtime/loongs.lock). APP_NAME must be
         unique on this host: change APP_NAME in /tmp/loongs-beta/.env, or stop
         that service first (cd /www/wwwroot/loong-swoole/server && ./loongs
         stop).
$ echo $?
1
beta$ ls runtime/                          # 空，未创建 pid / 锁 / 日志
beta$ ./loongs status --no-ansi
  app: same  (process titles loong-swoole[same]: …)
  master: stopped
  conflict: another service with APP_NAME "same" runs from /www/wwwroot/loong-swoole/server (pid 466373 loong-swoole[same]: master) — APP_NAME must be unique on this host; start is refused until it stops or APP_NAME changes
server$ ./loongs start -d --no-ansi        # 同一项目再次启动
 [ERROR] Already running (pid 466373). Use stop/reload/status.
```

beta 改为 `APP_NAME=other` 后两者可同时运行，各自 `stop` 正常。

同一项目目录里用另一个 `PROCESS_PID_FILE` 再起一个同名实例也会被拒绝（同样 exit 1、不建文件）：

```text
server$ ./loongs start -d --no-ansi        # .env: PROCESS_PID_FILE=runtime/sn2.pid, HTTP_PORT=19503
 [ERROR] Another instance with APP_NAME "same" is already running from this
         project with a different pid file: pid 475266 loong-swoole[same]:
         master (project /www/wwwroot/loong-swoole/server, lock
         /www/wwwroot/loong-swoole/server/runtime/loongs.lock). APP_NAME must be
         unique on this host: give this instance its own APP_NAME in .env, or
         stop that instance first (kill -TERM 475266, or ./loongs stop with its
         PROCESS_PID_FILE).
```


**升级提示**：旧版本的进程标题是 `loong-swoole: …`（不带 APP_NAME）。升级前请先用旧代码 `./loongs stop` 停掉正在运行的实例；新代码仍能通过 pid 文件 + 锁找到旧 master，但基于标题的孤儿识别不认旧标题。

---

## 4. RPC 热切换命令（无需重启）

作用对象是 `config/rpc.php` 的 `services`。写操作会先严格校验，再以原子方式（临时文件 + rename）写入覆盖文件 `rpc.hot_reload.override_file`（默认 `runtime/rpc_services.json`）。运行中的 worker 在 `rpc.hot_reload.interval_ms`（默认 1000ms）内生效。校验失败时输出错误块、退出码 `1`，覆盖文件不变。

代码中也可以用同一套实现：`rpc_services()->switch(...)` / `set` / `reset` / `resetAll` / `show` / `reload` / `version`，详见 `config/rpc.php` 顶部注释。

### 4.1 `rpc:show` — 查看

```
rpc:show [<service>]
```

| 参数 | 说明 |
|---|---|
| `service` | 可选，只看该 service |

输出包括：hot reload 状态与间隔、config 文件、覆盖文件状态，以及表格 service / source / # / transport / endpoint / weight / timeout_ms / metadata。

退出码：正常 `0`；覆盖文件或生效配置无效时 `1`（此时 worker 继续使用旧配置）；指定的 service 不存在时 `1`。

```bash
./loongs rpc:show
./loongs rpc:show user
```

### 4.2 `rpc:switch` — 切换 transport

```
rpc:switch <service> <transport> [<endpoint>]
```

| 参数 | 说明 |
|---|---|
| `service` | `config/rpc.php` 中的 service 名（如 `user`） |
| `transport` | `local` / `loopback` / `remote` |
| `endpoint` | `http(s)://host:port`；`remote` 必填；`loopback` 缺省为 `http://127.0.0.1:$RPC_PORT` |

保留当前条目的 `timeout_ms` / `metadata`。

退出码：成功 `0`；未知 transport、remote 缺 endpoint、非法 URL、未定义的 service 均为 `1`。

```bash
./loongs rpc:switch user loopback
./loongs rpc:switch user remote http://10.0.0.12:9502
./loongs rpc:switch user local
```

### 4.3 `rpc:set` — 整体设置

```
rpc:set <service> <json>
```

| 参数 | 说明 |
|---|---|
| `service` | service 名 |
| `json` | 该 service 的完整配置（JSON 对象） |

退出码：成功 `0`；JSON 非法、非对象、校验失败（如负权重、非法 endpoint）为 `1`。

```bash
./loongs rpc:set user '{"transport":"remote","instances":[{"endpoint":"http://10.0.0.1:9502","weight":1},{"endpoint":"http://10.0.0.2:9502","weight":3}]}'
```

### 4.4 `rpc:reset` — 恢复

```
rpc:reset [options] [--] [<service>]
```

| 参数 / 选项 | 说明 |
|---|---|
| `service` | 要恢复的 service |
| `-a, --all` | 删除全部覆盖（也可修复无效的覆盖文件） |

退出码：成功 `0`（该 service 本来没有覆盖时提示 nothing to do，仍为 `0`）；既没给 service 也没给 `--all` 时为 `1`。

```bash
./loongs rpc:reset user
./loongs rpc:reset --all
```

---

## 5. 辅助命令

### 5.1 `list` — 列出命令

```
list [options] [--] [<namespace>]
```

| 参数 / 选项 | 说明 |
|---|---|
| `namespace` | 只列出该命名空间（如 `rpc`） |
| `--raw` | 原始列表（便于嵌入其它工具） |
| `--format=FORMAT` | 输出格式 txt / xml / json / md，默认 txt |
| `--short` | 不描述命令参数 |

```bash
./loongs list
./loongs list rpc
./loongs list --format=json
```

### 5.2 `help` — 命令帮助

```
help [options] [--] [<command_name>]
```

| 参数 / 选项 | 说明 |
|---|---|
| `command_name` | 命令名，默认 `help` |
| `--format=FORMAT` | txt / xml / json / md，默认 txt |
| `--raw` | 原始帮助 |

```bash
./loongs help rpc:switch
./loongs rpc:switch --help        # 等价
./loongs help --format=md start
```

### 5.3 `completion` — Shell 自动补全

```
completion [options] [--] [<shell>]
```

| 参数 / 选项 | 说明 |
|---|---|
| `shell` | shell 类型（bash / fish / zsh）；不写则使用 `$SHELL` |
| `--debug` | 跟踪补全调试日志 |

```bash
# 静态安装（全局）
./loongs completion bash | sudo tee /etc/bash_completion.d/loongs
# 或写到本地文件后 source
./loongs completion bash > completion.sh && source completion.sh
# 动态安装：加到 ~/.bashrc 末尾
eval "$(/www/wwwroot/loong-swoole/server/loongs completion bash)"
```

---

## 6. 扩展自定义命令

可在以下文件注册自己的 symfony/console 命令（`Command` 子类 + `#[AsCommand]`）：

- 全局：`server/config/console.php`
- 单个 app：`server/apps/<App>/config/console.php`（格式相同）

```php
<?php
// server/config/console.php
return [
    'commands' => [
        App\Website\Console\CacheWarmCommand::class,
    ],
];
```

- 继承 `Loongs\Console\Command` 即可使用 `basePath()` / `io()`（SymfonyStyle）/ `processManager()` / `rpcServices()`。
- 注册的类如果不是 Symfony `Command` 子类，启动控制台时直接报错（fail fast）。
- 注册后会出现在 `./loongs list` 中。

---

## 7. 相关 .env 键（均已在 config 中使用）

**进程管理**

| 键 | 作用 |
|---|---|
| `APP_NAME` | 服务名：只允许字母、数字、下划线（`^[A-Za-z0-9_]+$`），空或未设 = `loongs`；出现在进程标题 `loong-swoole[<APP_NAME>]: …`；同机唯一（start/restart 发现其他项目的同名服务则拒绝，exit 1）；start/stop/restart/reload/status 启动前校验，非法则 exit 1 |
| `PROCESS_PID_FILE` | master pid 文件（留空默认 `runtime/loongs.pid`，与 APP_NAME 无关；锁文件 = 去掉 `.pid` 加 `.lock`） |
| `PROCESS_LOG_FILE` | daemon 模式下 master + 子进程 stdout/stderr 的去向（留空默认 `runtime/loongs.log`，追加写，纯文本）；前台模式输出到终端 |
| `PROCESS_DAEMONIZE` | 是否后台运行（`start -d` 可覆盖） |
| `HTTP_ENABLED` / `HTTP_HOST` / `HTTP_PORT` / `HTTP_WORKER_NUM` | HTTP 进程（默认端口 9501） |
| `RPC_ENABLED` / `RPC_HOST` / `RPC_PORT` / `RPC_WORKER_NUM` | RPC 进程（默认端口 9502，loopback 缺省 endpoint 也用 `RPC_PORT`） |
| `WS_ENABLED` / `WS_HOST` / `WS_PORT` / `WS_WORKER_NUM` / `WS_HANDLER` | WebSocket 进程 |
| `QUEUE_ENABLED` / `QUEUE_COUNT` / `QUEUE_CONNECTION` / `QUEUE_QUEUES` / `QUEUE_PREFIX` / `QUEUE_TIMEOUT` | 队列进程 |
| `CRONTAB_ENABLED` | 定时任务进程 |
| `CUSTOM_EXAMPLE_ENABLED` / `CUSTOM_EXAMPLE_INTERVAL` / `CUSTOM_EXAMPLE_KEY` | 示例自定义进程 |

**RPC**

| 键 | 作用 |
|---|---|
| `RPC_NODE` | 实例标记；非空时响应带 `meta.served_by {node, pid}` |
| `RPC_HOT_RELOAD` | 是否开启 services 热加载（默认 true） |
| `RPC_HOT_RELOAD_INTERVAL_MS` | 热加载检查间隔（默认 1000） |
| `RPC_HOT_RELOAD_FILE` | 运行时覆盖文件（默认 `runtime/rpc_services.json`） |
| `RPC_IOURING` | io_uring 模式 `auto` / `on` / `off` |
| `RPC_IOURING_ENTRIES` / `RPC_IOURING_WORKERS` / `RPC_IOURING_FLAG` | io_uring 参数 |

其它键（`APP_*`、`DB_*`、`REDIS_*`、`CACHE_*`）见 `.env.example`。`.env` 在 master 启动时加载，修改后需要 `restart` 才能确保生效；只有 `rpc.services` 支持不重启热加载（`rpc:*` 命令或直接编辑 `config/rpc.php`）。

---

## 8. Composer 命令

**部署 / 使用者**：依赖来自 Packagist（`loongs/framework`、`loongs/cache`、`loongs/helper`、`symfony/console` 等，`loongs/*` 约束 `dev-main`）。

```bash
cd server
composer install
# 如果 repo.packagist.org 连不上，可改用腾讯镜像：
composer config -g repos.packagist composer https://mirrors.cloud.tencent.com/composer/
```

**本地框架开发**：`composer.dev.json` 只在本地使用（不入库），以 path 仓库软链到 `../composer/framework`、`../composer/cache`、`../composer/helper`（`loongs/helper` 也已在 Packagist，`composer.json` 与 `composer.dev.json` 都 require 它）。

```bash
cd server
COMPOSER=composer.dev.json composer update
```

---

## 9. bin/ 下的运维脚本（已入库）

| 脚本 | 用途 |
|---|---|
| `sudo ./bin/iouring.sh` | 为 PHP 8.4 编译安装启用 io_uring / uring_socket 的 Swoole 6.2.2（先备份原 swoole.so）。可用环境变量 `PHP_BIN` / `PHP_CONFIG` / `SWOOLE_VER` / `BUILD_DIR` |
| `sudo ./bin/remove_disable_functions.sh` | 移除宝塔 PHP 的 `disable_functions`。`--swoole` 只移除 Swoole 常用函数；`--php=83` 指定版本（默认 84）；`--dry-run` 只显示改动；`--restore` 从最近一次备份还原；`--all` 全部清空（默认） |

本地另有 `bin/smoke_*` 冒烟脚本，已 gitignore，不随仓库发布。
