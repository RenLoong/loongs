# loongs 命令速查（server/）

本文件列出 `server/` 下所有可执行命令及用法，内容取自 `./start list --no-ansi` / `./start help <命令> --no-ansi` 的真实输出。
命令行基于 **symfony/console**（`Loongs\Console\Kernel`），业务逻辑在 `Loongs\Process\ProcessManager` / `Loongs\Rpc\HotReload\RpcServiceManager`。

> 查看最新帮助：`./start list`、`./start help <命令>`（加 `--no-ansi` 得到纯文本）。

---

## 0. 如何运行

在 `server/` 目录下：

```bash
./start <命令> [参数] [选项]
# 宝塔等面板的 PHP 常禁用 pcntl_* 等函数，推荐显式放开：
/www/server/php/84/bin/php -d disable_functions= start <命令> [参数] [选项]
```

- `server/start` 是入口脚本：定义 `LOONGS_BASE_PATH = __DIR__`，加载 `vendor/autoload.php`，运行控制台。缺少 vendor 时提示 `Autoloader not found. Run: composer update` 并退出 1。
- 不带命令时默认执行 `start`：`./start` 等价于 `./start start`，`./start --only=http,rpc` 同样有效。
- 可在任意目录用绝对路径执行（如 `/www/wwwroot/loong-swoole/server/start status`），基准目录固定为 `start` 所在目录。

## 1. 命令总览

| 命令 | 说明 |
|---|---|
| `start` | 启动进程管理器（http / rpc / websocket / queue / crontab / custom） |
| `stop` | 停止运行中的 master（SIGTERM，30 秒后 SIGKILL）；master 已不在时清理残留孤儿进程 |
| `restart` | 先 stop（如在运行）再 start |
| `reload` | 平滑重载：向 master 发 SIGUSR1，子进程重载 worker |
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
1. **启动横幅**：框架名称 + 核心版本（`loongs/framework` 的 Composer 版本；dev 分支附带 commit，本地 path/symlink 安装取 checkout 的 HEAD）、应用名称（`APP_NAME` → `config('app.name')`，为空时回退 composer 根包名 `loongs/loongs`）、env/debug、PHP 与 Swoole 版本、RPC io_uring 状态（`-v` 附带原因）、base path、pid 文件、日志去向、前台/daemon。
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
- 另一个 `start` 正在启动：`Another start is in progress (pid N).`
- 上次的 master 已死但子进程仍存活：`The master is gone but N process(es) of the previous run are still alive (...). Run ./start stop to clean them up.`
- 配置端口已被占用：`Port 127.0.0.1:19501 for [http] is already in use by pid N <cmdline> (Address already in use). Free the port or change it in .env / config/process.php.`
- 没有可启动的进程。
- 被第二次 Ctrl+C / SIGTERM 强制退出（前台）。

```bash
./start                              # 同 ./start start
./start start --only=http,rpc
./start start --only='user.*' -d
```

前台启动 + Ctrl+C 的真实输出（`--no-ansi`；测试端口 19501/19502、`HTTP_WORKER_NUM=2`，pid/log 文件为测试用的 `*-demo.*`）：

```text
$ ./start start --only=http,rpc --no-ansi
 Loongs dev-main@4a2d8cd  ·  loongs

  Framework  Loongs dev-main@4a2d8cd (loongs/framework)
  App        loongs  env=local  debug=on
  Runtime    PHP 8.4.25  ·  Swoole 6.2.2
  io_uring   on  network=uring_socket  coverage=file+network  mode=auto
  Base path  /www/wwwroot/loong-swoole/server
  Pid file   runtime/loong-swoole-demo.pid
  Log        stdout (runtime/loong-swoole-demo.log in daemon mode)
  Mode       foreground (Ctrl+C or ./start stop)

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
$ ./start start --only=http,rpc -d

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

 Running in the background. Logs: runtime/loong-swoole-demo.log · ./start status · ./start stop
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
$ ./start stop --no-ansi
 [WARNING] Master is gone; stopping 2 orphaned process tree(s) (4 processes)
           with SIGTERM: pid 429268 loong-swoole: http, pid 429270 loong-swoole:
           rpc
 [ERROR] Orphans ignored SIGTERM; sent SIGKILL to 429270 (10.09s).
$ echo $?
1
```

```bash
./start stop
```

### 3.3 `restart` — 重启

`stop`（SIGTERM，等待）后用相同选项 `start`。如只需平滑重载 worker，用 `reload`。

```
restart [options]
```

选项与 `start` 相同：`--only=ONLY`、`-d, --daemon`。

退出码：stop 失败则返回 stop 的退出码，否则返回 start 的退出码。

```bash
./start restart -d
./start restart --only=http,rpc
```

### 3.4 `reload` — 平滑重载

```
reload
```

向 master 发 SIGUSR1，子进程重载各自的 worker。是否在运行与 `stop`/`status` 同样按实例锁判断，不会向复用了 pid 的无关进程发信号。

退出码：已发送为 `0`；未运行（`Not running.`）为 `1`。

```bash
./start reload
```

### 3.5 `status` — 状态

显示 master 状态、pid 文件，以及合并后（全局 + 各 app）的进程表。表列为 process / type / app / listen / count / pid / state，state 取值为 running、stopped、not running、orphaned、disabled。master 状态按实例锁判断（pid 文件 + 锁被持有 + 该 pid 是锁持有者）；陈旧 pid 文件只在确认无人持锁时才删除。master 已不在但仍有本实例进程时显示 `orphaned` 及 `→ run ./start stop to clean them up`。只读操作，不影响运行中的服务。

```
status [options]
```

| 选项 | 说明 |
|---|---|
| `--only=ONLY` | 只把这些进程标记为选中（语法同 `start --only`，可重复） |

退出码：运行中 `0`；未运行或孤儿残留 `1`。

```bash
./start status
./start status -v          # 额外显示 log file / daemonize
./start status --no-ansi   # 纯文本（脚本解析用）
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
  orphaned: the master is gone but 2 process(es) are still alive: pid 429268 loong-swoole: http, pid 429270 loong-swoole: rpc
  → run ./start stop to clean them up
  ...
  http              http        -         127.0.0.1:19501   1 × 2w   429268   orphaned
  rpc               rpc         -         127.0.0.1:19502   1 × 1w   429270   orphaned
```

### 3.6 实例锁、孤儿进程与强制退出

**实例锁**：pid 文件旁有锁文件 `<pid 文件去掉 .pid>.lock`（默认 `runtime/loong-swoole.lock`，永不删除）。`start` 在任何 fork / daemonize **之前**以 `flock(LOCK_EX|LOCK_NB)` 取锁（最多重试 1 秒），取到后立刻写 pid 文件，daemonize 后再以 daemon 的 pid 覆盖。锁的打开文件描述会被所有子进程继承，因此只要本实例还有任何进程活着，锁就一直被持有：

- 同时执行多个 `start`（实测 10 个并发）只有一个成功，其余报 `Already running (pid N)`（或 `Another start is in progress`），退出码 1。
- 平滑停止期间（RPC 在排空请求）再 `start` 会被拒绝：`Already running (pid N)`。
- `status` / `stop` / `reload` / `start` 都以"锁被持有且 pid 文件里的 pid 是锁持有者"判断是否在运行，不再只看 `posix_kill(pid, 0)`；pid 被复用给无关进程时视为未运行，也不会给它发信号。

**端口预检**：取锁后、fork 之前逐个 bind 配置端口（**不**设 SO_REUSEPORT，因此即使对方用 SO_REUSEPORT 监听、而 RPC 本身使用 reuse_port 也能发现冲突），被占用时从 `/proc/net/tcp*` 找出占用者 pid 与命令行并拒绝启动（退出码 1，pid 文件被清除、锁被释放）。

**master 意外死亡（kill -9、终端关闭）**：每个子进程在启动角色前 fork 一个看门狗（进程名 `loong-swoole: watchdog <tag>`），每 200ms 检查 master 是否仍在；master 消失后看门狗对子进程发 SIGTERM（平滑退出），15 秒后仍未退出则 SIGKILL 其整棵进程树。实测 kill -9 master 后 0.38s 内所有进程退出，端口释放，下次 `start` 正常。若仍有残留（看门狗也被杀、子进程卡死），`status` 显示 `orphaned`，`start` 拒绝并提示运行 `./start stop`，`stop` 负责清理（见 3.2）。

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
| `./start stop` 等待 master + 锁释放 | 30 秒 | SIGKILL master 及所有锁持有者 |
| `./start stop` 清理孤儿 | 10 秒 | SIGKILL |
| 看门狗：master 消失后等待子进程（`ORPHAN_GRACE`） | 15 秒 | SIGKILL 子进程树 |
| 第二次 Ctrl+C / SIGTERM | 立即 | SIGKILL 全部，退出码 1 |

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
./start rpc:show
./start rpc:show user
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
./start rpc:switch user loopback
./start rpc:switch user remote http://10.0.0.12:9502
./start rpc:switch user local
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
./start rpc:set user '{"transport":"remote","instances":[{"endpoint":"http://10.0.0.1:9502","weight":1},{"endpoint":"http://10.0.0.2:9502","weight":3}]}'
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
./start rpc:reset user
./start rpc:reset --all
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
./start list
./start list rpc
./start list --format=json
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
./start help rpc:switch
./start rpc:switch --help        # 等价
./start help --format=md start
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
./start completion bash | sudo tee /etc/bash_completion.d/start
# 或写到本地文件后 source
./start completion bash > completion.sh && source completion.sh
# 动态安装：加到 ~/.bashrc 末尾
eval "$(/www/wwwroot/loong-swoole/server/start completion bash)"
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
- 注册后会出现在 `./start list` 中。

---

## 7. 相关 .env 键（均已在 config 中使用）

**进程管理**

| 键 | 作用 |
|---|---|
| `PROCESS_PID_FILE` | master pid 文件（默认 `runtime/loong-swoole.pid`） |
| `PROCESS_LOG_FILE` | daemon 模式下 master + 子进程 stdout/stderr 的去向（默认 `runtime/loong-swoole.log`，追加写，纯文本）；前台模式输出到终端 |
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

**部署 / 使用者**：依赖来自 Packagist（`loongs/framework`、`loongs/cache`、`symfony/console` 等）。

```bash
cd server
composer install
# 如果 repo.packagist.org 连不上，可改用腾讯镜像：
composer config -g repos.packagist composer https://mirrors.cloud.tencent.com/composer/
```

**本地框架开发**：`composer.dev.json` 只在本地使用（不入库），以 path 仓库软链到 `../composer/framework`、`../composer/cache`。

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
