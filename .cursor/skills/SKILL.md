---
name: LOONGS 框架应用与 RPC
description: >-
  在 loongs 应用项目根下新建或修改 HTTP 业务 app（apps/<Name>、Modules、路由/控制器/视图/SPA/中间件/api-version）
  或纯 RPC 服务 app（rpc.php、Handler、跨 app RpcClient），以及 config/rpc.php 登记与 rpc:switch 热切换。
  项目根即安装目录本身（composer create-project 后不是嵌套的 server/ 子目录）。不要用本技能改前端仓库或框架内核源码（除非任务明确要求）。
---

# LOONGS：框架应用与 RPC 应用

作用范围：**当前 loongs 应用项目根**（`composer create-project loongs/loongs` 得到的目录：含 `./loongs`、`apps/`、`config/`、`routes/`、`composer.json`）。

> 不要写死 `/www/wwwroot/loong-swoole/server`。本机 monorepo 里可能碰巧叫 `server/`，但他人克隆/创建后**没有外层 server 包装**——**当前工作目录就是根**。下文路径均相对项目根（如 `apps/Website/`，不是 `server/apps/Website/`）。

冒烟用独立测试端口；**不要动**用户已在跑的生产/开发监听端口，也不要动其 `production/` 目录（若存在）。

## 何时用

| 场景 | 用本技能 |
|------|----------|
| 新建/改 `apps/<Name>/`（Website 类 HTTP app） | ✓ |
| 新建/改纯 RPC app（参考 `apps/User`） | ✓ |
| 跨 app 调用、改 `config/rpc.php`、`./loongs rpc:*` | ✓ |
| App 内 Modules / 中间件 / Template / ServeAppPublic | ✓ |
| 改框架内核包、独立前端仓库 | ✗ |

## 产品要点

| 项 | 值 |
|----|-----|
| PHP | `^8.4`，CLI 以本机为准（常见 `/www/server/php/84/bin/php`） |
| 扩展 | `ext-swoole ^6` |
| 入口 | 项目根 `./loongs`（无 `.php` 后缀） |
| 业务 NS | `App\<Name>\` → `apps/<Name>/` |
| 默认端口 | 以本项目 `.env` 为准（示例常为 HTTP 9501、RPC 9502） |
| Health | `GET /health`（进程级 `routes/*.php`，**不是** app） |

## 硬约束

1. **`apps/` 只放真实 app 目录**（示例：`Website/`、`User/`），禁止扁平非 app 代码。
2. **禁止 app A `use` / `new` app B**；跨 app 只能 `RpcClient::call`。
3. **禁止**在 `routes/*.php`（进程级）里 `require` 某个 `apps/*/routes.php` 或 `rpc.php`。
4. 路由 / RPC handler 由 **`AppDiscovery`** 启动扫描：app 目录排序 → 加载 `routes.php` → 一层 `Modules/*`（禁止嵌套）→ boot 时加载各 app `rpc.php`。
5. Local / Loopback / Remote **业务调用写法相同**，只改 transport；Local **禁止**短路成直接调对端类。
6. `loadRoutes` 加载进程级 `routes/` 下**全部** `.php`，不要写死单个 `route.php` 文件名。

## A. HTTP 框架应用（参考 `apps/Website`）

### 目录骨架

```text
apps/Foo/
├── routes.php                 # 必须
├── middleware.php             # 可选；返回 list<class-string>
├── Controller/                # 可选 V2/ 等版本目录
├── Middleware/
├── views/
├── public/                    # 静态 + SPA index.html / h5/index.html
├── config/                    # 可选；含 processes.php（进程名加 app 前缀）
└── Modules/                   # 可选；仅一层
    └── Bar/
        ├── routes.php
        ├── middleware.php
        ├── Controller/
        ├── views/
        └── public/
```

命名空间：`App\Foo\...`，模块：`App\Foo\Modules\Bar\...`。

### 路由

```php
<?php
declare(strict_types=1);

use App\Foo\Controller\HomeController;
use Loongs\Routing\Router;

/** @var Router $router */

$router->get('/foo', [HomeController::class, 'index']);
```

- 作用域有 `$router`、`$app`、`$container`。
- 路径避开与其他 app、`/health`、`/rpc` 冲突。
- 参考：`apps/Website/routes.php`（可经 RPC 调 User，无 `use App\User`）。

### 控制器与视图

- 注入 `Loongs\Template\Template`：`view($app, $tpl, $data)` / `spa($app, $variant?)`。
- 静态：`GET /{appSlug}/...` → `apps/<App>/public/...`；模块：`GET /{appSlug}/{moduleSlug}/...`。
- `declare(strict_types=1)`、PSR-12、控制器宜 `final`。

### 中间件顺序（外→内）

1. Global（Kernel：RequestId → ServeAppPublic）
2. App `middleware.php`
3. Module `middleware.php`（仅模块路由）
4. Controller 属性 / `middleware()`
5. Route 第三个参数列表

### api-version

- 路由注册默认控制器；头 `api-version: v2`（或 `Api-Version` / `2`）→ `Controller\V2\...`；不存在则回退默认。

### App 级进程

`apps/Foo/config/processes.php` 可声明附加进程；进程名带 app 前缀。参考 `apps/User/config/processes.php`。

## B. RPC 服务应用（参考 `apps/User`）

```text
apps/User/
├── routes.php           # 必须存在（可空）
├── rpc.php              # 必须；注册 service/method
├── Service/
├── Repository/          # 优先 db() 连接池
├── Dto/
├── config/              # 可选
└── database/*.sql
```

### `rpc.php`

```php
<?php
declare(strict_types=1);

use App\User\Service\UserService;
use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Server\HandlerRegistry;

/** @var HandlerRegistry $handlers */
/** @var \Loongs\Http\Application $app */
/** @var \Loongs\Container\Container $container */

$service = $container->make(UserService::class);

$handlers->register('user', 'User.get', static function (RpcRequest $request) use ($service): RpcResponse {
    return $service->get($request->payload);
});
```

- 由 `AppDiscovery::registerRpcHandlers()` 加载；**禁止**从进程级 `routes/*.php` require。
- 返回 plain array 或 `RpcResponse`；失败用 `RpcResponse::fail($code, $msg)`。

### 在 `config/rpc.php` 登记

```php
'services' => [
    'user' => [
        'transport' => 'local',   // local | loopback | remote
        'timeout_ms' => 3000,
        // loopback/remote 的 endpoint 指向 **RPC 端口**（.env 的 RPC_PORT），不是 HTTP 端口
        // 'endpoint' => 'http://127.0.0.1:9502',
        // 'instances' => [['endpoint' => 'http://…', 'weight' => 1], …]
    ],
],
```

### 跨 app 调用

```php
$result = $rpcClient->call('user', 'User.get', ['id' => 1]);
```

示例：`apps/Website` 的 User 代理控制器只依赖 RpcClient。

## C. RPC 热切换（不重启）

```bash
# 在项目根执行
./loongs rpc:show [service]
./loongs rpc:switch user loopback http://127.0.0.1:9502
./loongs rpc:switch user remote   http://10.0.0.12:9502
./loongs rpc:switch user local
./loongs rpc:set user '{"transport":"remote","instances":[{"endpoint":"http://10.0.0.1:9502","weight":1}]}'
./loongs rpc:reset user   # 或 --all
```

- 覆盖：`runtime/rpc_services.json`（gitignored）；非法配置拒绝并保留旧表。
- 代码：`rpc_services()->switch/set/reset/show/reload`；管理接口必须鉴权。
- 只热加载 **`services`**；`retry` / io_uring / `node` 需重启。

## D. 进程与命令

```bash
# 项目根
./loongs start|stop|restart|reload|status [--only=http,rpc] [-d]
./loongs list
./loongs help rpc:switch
```

- 配置：`config/process.php`。
- `APP_NAME`：仅 `[A-Za-z0-9_]+`；同机多实例用不同 APP_NAME + 端口。
- 详表：项目内 `COMMANDS.md`（以 `./loongs help` 为准）。
- 扩展：`config/console.php` 或 `apps/<App>/config/console.php`。

## E. 提交前自检

- [ ] 改动在 `apps/<Name>/` 与必要的 `config/rpc.php`（或热切换 CLI）
- [ ] 有 `routes.php`；RPC app 另有 `rpc.php`；均未被进程级 `routes/` require
- [ ] 无 `use App\OtherApp\...`
- [ ] HTTP：Template / 静态路径正确；模块仅一层；版本在 `Controller\V{n}`
- [ ] RPC：`services.<name>` 已登记；Loopback/Remote endpoint 是 **RPC_PORT**
- [ ] `php -l`；测试端口验证，勿占用用户现网端口

## 参考（相对项目根）

- HTTP 示例：`apps/Website/`
- RPC 示例：`apps/User/`
- RPC 配置：`config/rpc.php`
- 命令：`COMMANDS.md`、`./loongs`
- 进程级路由：`routes/*.php`
