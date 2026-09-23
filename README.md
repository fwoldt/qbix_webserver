# ⚡ Exponential Velocity — A Qbix based webserver package

### Run your existing PHP codebase 10–100× faster than nginx + php-fpm

A pure PHP web server. No nginx, no Apache, no php-fpm. One process serves static files, PHP scripts, WebSocket connections, and a live dashboard.

### The problem with php-fpm

Whether opcache is enabled or not, the vast majority of production PHP code is I/O-bound. Workers wait for the database, the filesystem, an API call, a cache server. During that wait, the worker is doing nothing — but it's still holding 30–60MB of RAM. That's the bottleneck. On a 4GB server, php-fpm gets maybe 80 workers. Each one blocks on a 200ms query, so you get ~400 req/s. That's the ceiling.

### The problem with Swoole, RoadRunner, and FrankenPHP

They try to solve this by making PHP evented, like Node.js. Swoole's coroutines can multiplex I/O within a single worker — but only if you rewrite your code to use `Swoole\Coroutine\MySQL`, `Swoole\Coroutine\Http\Client`, and so on. Every `PDO::query()`, every `file_get_contents()`, every `curl_exec()` in every WordPress plugin, Laravel package, and Drupal module uses blocking I/O. It doesn't yield. Swoole can't help with code that doesn't cooperate. RoadRunner and FrankenPHP don't even try coroutines — they use the same worker-count-limited model as fpm.

### How Qbix solves it

Instead of making each worker do more, Qbix runs more workers. The server loads your entire framework into a parent process, then calls `pcntl_fork()`. The kernel marks every page copy-on-write. Each worker shares the parent's loaded classes and only pays for pages it actually writes to during the request. A WordPress-like request dirties 30 pages = 120KB. So the same 4GB that gives fpm 80 workers gives Qbix thousands.

Your code runs unmodified, in two modes:

**Persistent workers (default)** — workers stay alive across requests. Between each request, a Reflection-based snapshot restores all static properties in 0.03ms. 28 PHP functions (`header()`, `session_start()`, `ini_set()`, `set_error_handler()`, etc.) are shimmed via source transformation so they reset correctly. This is how you get 2,294 req/s on CPU-bound work and 1,060 req/s under I/O.

**Fork-per-request** — if persistent mode doesn't work for your code (functions with internal static variables, plugins that register global state in ways the shim can't track), set `forkPerRequest: true`. Each request gets a fresh fork. It's slower than persistent mode, but each forked worker still costs only 120KB instead of 50MB, so you can run 100× more of them than fpm on the same hardware. That's the whole point — blocking I/O doesn't matter when you have enough workers, and COW makes "enough workers" nearly free.

### What it replaces

| | nginx + php-fpm | Qbix Server |
|---|---|---|
| 💾 **Memory per worker** | 30–60MB (duplicated) | ~200KB (COW, measured) |
| 👥 **Concurrent PHP** (1GB) | ~24 workers | **~5,000** (typical) |
| 🔒 **Isolation** | Statics leak between requests | Snapshot reset — no leaks |
| 🚀 **Throughput** (CPU-bound) | ~400 req/s (Swoole 4w) | **2,294 req/s** (100w) |
| 🚀 **Throughput** (I/O, same RAM) | 78 req/s (fpm/Swoole 4w) | **1,060 req/s** (100w) |
| 🌐 **WebSocket** | Needs a separate server | Built in |
| 🧩 **Cache invalidation** | Whole-page only | `X-Cache-Tree` — per-component |
| ⚙️ **Setup** | nginx + fpm pools + sockets | `php qbixserver.php` |

See [BENCHMARKS.md](docs/BENCHMARKS.md) for full methodology and [reset.md](docs/reset.md) for what gets restored between requests.

### What a "real-time PHP app" used to require

**nginx** for reverse proxy and static files. **php-fpm** to run PHP. **Node.js** for a Socket.IO server. **Redis** for pub/sub between fpm and Node. **supervisor** to keep it all running. **Docker** to make it deployable. Six processes, three languages, two runtimes.

Qbix Server replaces all six with one process. HTTP, WebSocket (with Socket.IO protocol), SSE, sessions, uploads, static files, .htaccess — same port, same file. No Redis, no Node, no pub/sub glue. Download a 4.5MB binary, run it, done. Pure PHP.

You can also package your entire app — code, assets, SQLite database — into that binary and distribute it as a single file. Double-click on Windows, `./myapp --open` on Mac or Linux, the browser opens and the app is there. No PHP to install, no web server to configure, no database to set up. 5 MB, not 200 — because we open the browser that's already there instead of shipping Chromium like Electron does. [How it works →](#single-binary-distribution)

---


## Documentation

| | Topic | What it covers |
|---|---|---|
| 🏎️ | [Why Not php-fpm?](docs/why.md) | COW memory model, comparison with Swoole and FrankenPHP |
| 🔒 | [Server Headers](docs/headers.md) | Cache-Control, X-Cache-Tree, X-Accel-Redirect, ETag |
| 🌐 | [HTTP](docs/http.md) | Fork-per-request mode, request lifecycle |
| 🔌 | [WebSocket & Rooms](docs/websocket.md) | Process per connection, rooms, Socket.IO, SSE, chat example |
| 🛤️ | [Routing](docs/routing.md) | Clean URLs, .htaccess, DirectoryIndex |
| 📂 | [PHP Framework](docs/framework.md) | The micro-framework: handlers, events, Q classes |
| ⚙️ | [Configuration](docs/configuration.md) | JSON config, CLI options, presets |
| 📦 | [Running & Building](docs/running.md) | Source, phar, binary. Building static binaries. Requirements |
| 📀 | [Binaries & Signing](docs/binaries.md) | Pack apps, manage like zip, ECDSA M-of-N signing, Rekor, platform signing |
| 🏗️ | [Architecture](docs/architecture.md) | Persistent workers, COW, execution model, mental model, benchmarks |
| 📊 | [Dashboard & Panel](docs/dashboard.md) | Live stats, control panel tabs |
| 🚀 | [Deploy & Federation](docs/deploy.md) | Rsync deploy, cluster replication, inter-server trust |
| 🔍 | [API Discovery](docs/api-discovery.md) | OpenAPI, MCP, qbix.json, HTTP/2 |
| 🧩 | [Compatibility](docs/compatibility.md) | SAPI emulation, 28 shimmed functions, class ownership, tests |
| 📈 | [Benchmarks](docs/BENCHMARKS.md) | Full methodology and numbers |
| 🔄 | [State Reset](docs/reset.md) | What gets restored between requests |
| 🔀 | [Migrate from nginx](docs/migrate-nginx.md) | Server blocks, try_files, proxy_pass, gzip |
| 🔀 | [Migrate from Apache](docs/migrate-apache.md) | .htaccess unchanged, VirtualHost mapping |
| 🔀 | [Migrate from Caddy](docs/migrate-caddy.md) | Automatic HTTPS, on-demand TLS → autohost |
| ✅ | [Test Results](docs/TestResults.md) | 140 end-to-end tests |
| 🗺️ | [Roadmap](docs/roadmap.md) | What's next |
| 📄 | [License](docs/license.md) | MIT |

## Quick Start

```bash
git clone https://github.com/Qbix/webserver
cd webserver
php qbixserver.php
```

### Download a binary

Self-contained: PHP is inside the binary, so there is nothing to install and
no version of PHP on the machine to conflict with.

```bash
# Linux x86_64
curl -LO https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-linux-x86_64
chmod +x qbixserver-linux-x86_64 && ./qbixserver-linux-x86_64

# Linux aarch64 -- also what a Raspberry Pi 4 or 5 runs
curl -LO https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-linux-aarch64
chmod +x qbixserver-linux-aarch64 && ./qbixserver-linux-aarch64

# Windows x64
curl -LO https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-windows-x64.exe
qbixserver-windows-x64.exe
```

[**All downloads &rarr;**](https://github.com/se7enxweb/qbix-webserver/releases/latest)

### Or run the phar, anywhere PHP runs

The binaries exist for convenience, not necessity. `qbixserver.phar` is the
same server and needs nothing but a PHP 8.1 or later interpreter, which is why
it runs on far more than the handful of platforms we can build binaries for.

```bash
curl -LO https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver.phar
php qbixserver.phar --root=./web
```

## Use With Your Existing Codebase

If you already have a PHP app running on nginx + php-fpm, switching is one command. The server reads your `.htaccess`, rewrites URLs to your front controller, and runs your code with 28 functions shimmed so static variables, sessions, and headers work correctly between requests.

**Laravel:**

```bash
cd my-laravel-app
php /path/to/qbixserver.php --root=public --preset=laravel --port=8080
```

**Symfony:**

```bash
cd my-symfony-app
php /path/to/qbixserver.php --root=public --preset=symfony --port=8080
```

**WordPress:**

```bash
cd my-wordpress-site
php /path/to/qbixserver.php --root=. --preset=wordpress --port=8080
```

**Drupal:**

```bash
cd my-drupal-site
php /path/to/qbixserver.php --root=web --preset=drupal --port=8080
```

**Any PHP app with a front controller:**

```bash
php /path/to/qbixserver.php --root=public --port=8080
```

If the root directory has an `index.php`, all clean URLs automatically route to it (the same behavior as `try_files $uri $uri/ /index.php` in nginx). If there's a `.htaccess`, its `RewriteRule` and `RewriteCond` directives are applied.

### What `--preset` does

Each preset sets framework-appropriate defaults: the front controller path, upload limits, memory limits, and session GC settings. You can override any of these in a JSON config file. The preset is a convenience — without it, the server still works if your `.htaccess` handles routing.

### What gets shimmed

The server intercepts 28 PHP functions (`header()`, `session_start()`, `setcookie()`, `ini_set()`, etc.) via source transformation at include time. Your code calls `header()` and it works — the server captures it. Between requests, all static properties are restored from a snapshot in 0.03ms. See [Compatibility](docs/compatibility.md) for the full list.

### What to watch for

Most apps work immediately. A few things to be aware of:

- **`define()` constants** persist between requests in persistent workers. If a plugin defines a constant conditionally, the second request sees it already defined. Rare in practice.
- **`stream_wrapper_register()`** persists. Uncommon outside testing frameworks.
- **Long-running scripts** (migrations, imports) should use `--workers=1` or run via CLI directly.
- **Extensions that store C-level state** (e.g. some custom PECL modules) won't reset between requests. Standard extensions (PDO, curl, mbstring) are fine.

## Platform Support

There are two ways to run Exponential Velocity, and they reach different
numbers of platforms.

### Binaries — nothing to install

A static build with PHP inside it. We ship these for the targets the build
toolchain supports; PHP is C with a great many statically linked dependencies
and does not cross-compile the way a Go program does, so this list is short by
nature rather than by neglect.

| Platform | Download | State |
|---|---|---|
| **Linux** x86_64 | [`qbixserver-linux-x86_64`](https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-linux-x86_64) | shipped |
| **Linux** aarch64 &middot; Raspberry Pi 4 / 5 | [`qbixserver-linux-aarch64`](https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-linux-aarch64) | shipped |
| **macOS** arm64 &middot; Apple Silicon | [`qbixserver-macos-arm64`](https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver-macos-arm64) | shipped |
| **Windows** x64 | — | does not build |

**macOS took a while to arrive, and was never actually broken.** Its test could
not run on the macOS runner for several releases -- it looked for a PHP that was
not on PATH -- and once that was fixed it failed with an empty body on every
request, which reads exactly like a server returning nothing. It was not. The
harness starts the server with `setsid`, which is util-linux and absent on
macOS, so the server was never started at all. With that fixed the binary passes
every assertion, and it ships from the next release on.

**Windows** does not currently produce a binary. The static PHP build fails
fetching the `icu` library that `intl` needs, and the packaging step then finds
no `php.exe` and skips. Six steps on that path were marked to continue on
error, among them the PHP build and the smoke test, so the job reported success
and shipped only a stray `.dll`. The job now checks that a binary exists before
uploading anything, which is the check that would have caught it at the time.

Until both are settled, **macOS and Windows users should run the phar**, which
works on all four platforms. It needs a PHP 8.1+ interpreter, which on those
two is the easier thing to obtain anyway.

### The phar — anywhere PHP 8.1+ runs

[`qbixserver.phar`](https://github.com/se7enxweb/qbix-webserver/releases/latest/download/qbixserver.phar)
is the whole server in one file. It has no architecture and no libc of its own,
so the only question is whether the platform has a PHP, and a great many do.

The [Platforms workflow](../../actions/workflows/platforms.yml) boots each of
these and watches for a page to come back. The badge is the authority for
whether they currently pass -- it cannot go stale the way a hand-written tick
in a table can, and a platform listed here is one we test, not one we promise.

[![Platforms](../../actions/workflows/platforms.yml/badge.svg)](../../actions/workflows/platforms.yml)

| | Platforms exercised |
|---|---|
| **BSD** | FreeBSD 14, OpenBSD 7.6, NetBSD 10, DragonFly BSD |
| **illumos** | OmniOS |
| **Linux, glibc** | x86_64, aarch64, armv5, armv7, i386, ppc64le, riscv64, s390x |
| **Linux, musl** | Alpine on x86_64, aarch64, i386 |

`s390x` is there because it is big-endian, and `armv7` because it is what a
32-bit Raspberry Pi runs. Alpine is there because musl is a different C library
rather than a different build of the same one, and assumptions about DNS,
threads and locales break there first.

**Haiku** is wanted and not yet done. It carries PHP in HaikuPorts, so there is
every reason to think the phar runs, but nothing has watched it happen and the
job says so rather than passing vacuously.

**What cannot work:** anything without a PHP 8.1+ interpreter. That rules out
the 8-bit machines regardless of how the brands are doing — a Commodore 64 or
an Apple II is a 6502 with 64KB of memory, and PHP needs an MMU, a 32- or
64-bit CPU and tens of megabytes. The nearest thing in that lineage with any
path at all is the Amiga family (AmigaOS 4, MorphOS, AROS), which are real
multitasking systems, and even there someone has to produce a current PHP first.

### How workers behave, per platform

| Platform | Workers | Copy-on-write | Mode |
|---|---|---|---|
| **Linux** x86_64, aarch64 | `pcntl_fork` | Yes — 120KB per worker | Persistent or fork-per-request |
| **macOS** Intel, Apple Silicon | `pcntl_fork` | Yes | Persistent or fork-per-request |
| **BSD**, **illumos** | `pcntl_fork` | Yes | Persistent or fork-per-request |
| **Windows** x64 (via phar) | `php-cgi` subprocess | No | Persistent workers, source-transform shimming |

On Linux, the BSDs and macOS the server runs COW-forked workers at ~120KB each.
Windows has no `pcntl`, so it spawns `php-cgi` subprocesses for isolation:
workers are still persistent and still shimmed, you simply do not get the COW
memory saving. The 28-function source transform still clears state between
requests.

**PHP 8.6+ (epoll/kqueue):** the server detects PHP 8.6's native `Io\Poll` API
and uses `epoll` on Linux or `kqueue` on the BSDs and macOS, with no PECL
extensions. On older PHP it uses `stream_select`, which works fine — it is just
O(n) per tick instead of O(1). Revolt is used if installed.

## Examples

Six example apps are included in `examples/`:

| App | What it demonstrates |
|---|---|
| [todo](examples/todo) | SQLite CRUD, REST API, static HTML |
| [counter](examples/counter) | SQLite persistence, GET/POST |
| [chat](examples/chat) | WebSocket rooms, Socket.IO, 8 handler files |
| [stream](examples/stream) | Server-Sent Events, AI token streaming |
| [swarm](examples/swarm) | Q::event() dispatch, cluster replication |
| [collab](examples/collab) | Collaborative editing |

```bash
php qbixserver.php --root=examples/todo/web --port=8080
```

## Migrating from another server

Already running nginx, Apache, or Caddy? These guides show the config mapping:

- [Migrating from nginx](docs/migrate-nginx.md) — server blocks, try_files, proxy_pass, gzip
- [Migrating from Apache](docs/migrate-apache.md) — .htaccess works unchanged, VirtualHost → domains config
- [Migrating from Caddy](docs/migrate-caddy.md) — automatic HTTPS, on-demand TLS → autohost

## Single-Binary Distribution

Package your app into one executable file — PHP runtime, web server, and all your code. The binary includes SQLite auto-provisioning: if your app bundles a `.sqlite` file, the server copies it to the data directory on first run and writes the framework config to point at it. No external database needed.

Supported out of the box: Qbix (detects plugins, writes `local/app.json` with per-plugin prefixes), Laravel (`.env`), Symfony (`.env`), WordPress (`wp-config.php` + wp-sqlite-db), Craft CMS, and Drupal.

Sign binaries with ECDSA P-256 keys (M-of-N threshold), publish to Sigstore Rekor for independent verification, and customize by editing the binary as a zip file.

- [Building and distributing binaries](docs/binaries.md) — pack, sign, verify, customize, platform code signing


## Configuration

Everything below has a default that works, so a server with no configuration at
all still runs. Configuration is JSON, read into `Q_Config`, and every key can
also be set by a host application that builds the file itself.

```json
{
  "Q": {
    "web": {
      "https": { "mode": "manual",
                 "cert": "/etc/certs/fullchain.pem",
                 "key":  "/etc/certs/privkey.pem" },
      "http2":  { "enabled": true },
      "static": { "maxAge": 31536000 },
      "cache":  {
        "enabled": true,
        "dir": "files/cache/reverse",
        "defaultTtl": 0,
        "staleWhileRevalidate": 60,
        "revalidateLockSeconds": 30,
        "apcu": { "enabled": true, "maxSize": 65536 },
        "skip": { "cookies": ["PHPSESSID", "Q_sid"] }
      }
    },
    "webserver": {
      "backlog": 1024,
      "workers": 16,
      "precompress": { "enabled": true, "dir": "var/tmp/precompress" }
    }
  }
}
```

### The keys that matter most

| Key | Default | What it decides |
|---|---|---|
| `Q.webserver.backlog` | `1024` | How many established connections may wait to be accepted. See below — the old default of 32 was a cliff, not a queue. |
| `Q.webserver.workers` | auto | Process count. Sized from measured per-worker cost rather than a guess. |
| `Q.web.cache.enabled` | `false` | The reverse cache. Off unless asked for. |
| `Q.web.cache.staleWhileRevalidate` | `0` | Seconds past expiry an entry may still be served while one request renders the replacement. `0` keeps the pre-2026-09 behaviour exactly. |
| `Q.web.cache.skip.cookies` | `PHPSESSID`, `Q_sid` | Cookies that mean "this response is personal". **Set this to your framework's session cookie** — see the warning below. |
| `Q.web.static.maxAge` | `0` | Seconds a client may keep a static file without asking again. `0` means `must-revalidate`, which is a conditional request per file per page view. |

> **`skip.cookies` is the setting most likely to cost you.** It names the
> cookies that disable caching. If it does not name *your* session cookie, a
> signed-in visitor sails straight through the cache and is served someone
> else's page — and if it names a cookie your visitors happen to carry for
> unrelated reasons, every one of them bypasses the cache instead. On one
> installation the second failure made the same front page cost 1300 ms
> rendered instead of 74 ms cached, because a stale cookie from an unrelated
> app on the same host matched the default.

---

## Caching, and what a reload actually costs

The reverse cache sits in the parent process, so a hit never reaches a worker
and never forks. That is the difference between the two numbers this server
lives between:

```
cache hit      0.6 ms
full render    1382 ms
```

Almost everything below is about keeping requests on the left-hand side.

### Conditional requests

A returning browser sends the validators it holds. When they still stand, the
answer is `304` and no body at all:

```bash
$ curl -sk --http2 --compressed -D- -o/dev/null https://example.test/
HTTP/2 200
etag: "ae368d8780e9a15dd53c6656ce2-gzip"
last-modified: Tue, 22 Sep 2026 20:48:18 GMT
content-length: 9358

$ curl -sk --http2 -H 'If-None-Match: "ae368d8780e9a15dd53c6656ce2-gzip"' \
       -o/dev/null -w '%{http_code} %{size_download}B body\n' https://example.test/
304 0B body
```

202 bytes of headers instead of 9.4 KB, whatever the page weighs.

### The ETag is derived from the page, not from the clock

An entry that carried only `Last-Modified` recorded when the *entry was stored*.
Rebuild it and the timestamp moves even if the HTML is byte-identical, so the
next reload revalidates, fails, and transfers the whole document — a page
nobody edited costs full price once per lifetime, forever. The tag is a hash of
the body as the application produced it, before compression, so:

```
rebuild 1  etag="ae368d…-gzip"  last-modified=20:48:29
rebuild 2  etag="ae368d…-gzip"  last-modified=20:48:30
rebuild 3  etag="ae368d…-gzip"  last-modified=20:48:31
```

The identity and gzip forms get different tags, because RFC 9110 scopes a
validator to the selected representation and they are two representations.

### Expiry does not stop the site

An entry expires at a moment, not gradually, so every request in flight misses
at the same instant — and without protection each of them renders the page.
With `staleWhileRevalidate` set, exactly one renders and the rest are handed
the copy that already exists:

```
                    before              after
8 simultaneous      8 rendered          1 rendered
requests at the     1815–2131 ms each   1893 ms  (the one)
moment of expiry                        ~62 ms   (the other seven)
```

The claim is a directory, because `mkdir` is atomic and needs no cleanup
protocol to be correct. A claim older than `revalidateLockSeconds` may be taken
from whoever holds it, so a worker that dies mid-render cannot freeze a page at
its last version.

A stale response is labelled honestly — `X-Cache: STALE` and an `Age` header
with the age it actually has — because a cache downstream deserves to know.

### Forcing a refresh

`X-Cache-Refresh: 1` reads past the stored copy so the response is rendered and
stored again. It is deliberately not `Cache-Control: no-cache`: browsers send
that on a plain reload, and honouring it would let any client or crawler make
the server render on demand.

```bash
curl -H 'X-Cache-Refresh: 1' https://example.test/   # warm, don't just read
```

---

## Measuring it yourself

Do not take any number here on trust. The benchmark ships with the server and
needs nothing installed — PHP's curl does HTTP/2 and `curl_multi` does the
concurrency:

```bash
php tests/bench-load.php https://localhost:8443/
php tests/bench-load.php http://127.0.0.1:8088/ --levels=1,4,16,64 --requests=500
php tests/bench-load.php https://host/ --http1        # compare protocols
php tests/bench-load.php https://host/ --json         # for a pipeline
```

It sweeps the concurrency until throughput stops improving, reports where the
knee is, and writes a CSV. It also says what the machine was holding at the
time — cores, memory, load, worker count, resident size, open handles — because
a throughput number without those is not reproducible.

**What it measures and what it cannot:** closed loop, like `ab` and `wrk`. N
requests in flight, a new one as each finishes. That measures capacity honestly
and understates latency under saturation, because a slow response delays the
request that would have followed it — the requests never sent are the ones that
would have been slowest. This is coordinated omission, and it is why the
percentiles read as "how it served what it accepted" rather than "what a user
would have seen".

### A worked example: the backlog

PHP's `stream_socket_server` defaults its backlog to 32. A burst larger than
that does not queue and does not fail — the kernel drops the SYN and the client
retransmits a second later:

```
                  backlog 32                backlog 1024
concurrency  16    2961 req/s  p99    4.7 ms   2358 req/s  p99  9.6 ms
concurrency  32    2899 req/s  p99   13.5 ms   2872 req/s  p99 14.4 ms
concurrency  64     745 req/s  p99 1067.0 ms   2703 req/s  p99 19.5 ms
concurrency 128     603 req/s  p99 1317.0 ms   2773 req/s  p99 37.9 ms
```

Throughput did not degrade at 64, it collapsed to a quarter, and the p99 became
a round thousand milliseconds — the retransmit timer, while the server sat
mostly idle. A server that is fast until exactly 32 concurrent connections and
then appears to hang is very hard to diagnose from outside, because nothing in
it is slow.

### A worked example: TLS is the ceiling

Same page, same server, same moment:

```
                    with TLS          without TLS
concurrency   8     p99  108.1 ms     p99  4.9 ms
concurrency  16     p99  207.8 ms     p99  6.8 ms
peak                1481 req/s        2961 req/s
```

Broken down over six fresh connections:

```
dns 7.99 ms    tcp 0.32 ms    tls 20.96 ms    server 1.46 ms
```

The accept path is fine. The handshake is 21 ms on a link whose round trip is
0.3 ms, so it is CPU and scheduling rather than round trips — and session
resumption is not currently working: `openssl s_client -reconnect` reports
`New` every time and `Reused` never. **This is the largest known open item.**
If you are benchmarking this server against nginx, benchmark both over plain
HTTP as well, or you are mostly measuring OpenSSL.

---

## Tests

```bash
php tests/run-unit.php              # everything self-contained, ~3s
php tests/run-unit.php hpack        # only tests whose name matches
bash tests/run.sh                   # the above, then real sockets and TLS
```

The unit suite needs no server, no network, no certificate and no fixture
directory: each file drives a class directly and asserts on what it returns.
That is the half that can run in CI on a machine with nothing installed but
PHP, and the half that fails fast enough to run before a commit. It exits
non-zero on any failure, so it can gate a merge.

Current coverage includes frame codec, HPACK (including malformed input),
cache keys, cache entry format, cache wire form, conditional revalidation,
stale-while-revalidate, HTTP/1.1 response head construction, path safety,
source transformation, HTTP/2 limits, cookies, flow control, and full-socket
behaviour.

---

## Security posture

The server is written on the assumption that every byte from a peer is hostile
and that a bug here is a bug in everybody's site.

- **Path traversal** — one function decides whether a resolved path escapes the
  root, covering `..`, encoded forms, symlinks and the mixed separators
  Windows accepts. 34 cases pin it.
- **Header injection** — no header name or value containing CR or LF is ever
  written. Values arrive from application output and from stored cache
  entries, so this is not theoretical.
- **Response framing** — `Content-Length` is computed from the body actually
  being sent, and any supplied one is dropped. Two lengths on one message is
  how a parser is taught to find the next response inside this one.
- **HPACK** — bounds-checked integer and string decoding, throwing rather than
  reading past the buffer. 20 malformed-input cases.
- **HTTP/2 resource limits** — eight of them, covering concurrent streams,
  header list size, CONTINUATION frames (CVE-2024-27316) and stream
  creation/reset rate (rapid reset, CVE-2023-44487), answered with
  `ENHANCE_YOUR_CALM` rather than by falling over.
- **Connection-specific headers** are dropped from HTTP/2 responses, where
  they are a protocol error.

Reporting something: please do **not** open a public issue for a
vulnerability. See `SECURITY.md` if present, otherwise contact the maintainers
privately and give them time to release a fix before disclosure.

---

## Contributing

Contributions are welcome, and the bar is deliberately specific rather than
high.

### What a good change looks like

1. **It comes with a test that fails without it.** This is the one firm rule.
   A test that passes before and after the change is not testing the change.
   Check it by reintroducing the bug and watching the test fail — the suite
   here has been wrong that way before, and it cost a day.
2. **It explains why, not what.** The diff says what. A comment earns its place
   by saying what went wrong, what it measured, or what will break if someone
   undoes it. Numbers from a real run are worth more than adjectives.
3. **It changes one thing.** A fix and a refactor in one commit is two reviews
   pretending to be one.
4. **Its default is the old behaviour.** A new capability that alters how an
   existing installation behaves without being asked is a regression to
   somebody. `staleWhileRevalidate` defaults to `0` for exactly this reason.

### Practically

```bash
git clone https://github.com/Qbix/Server.git
cd Server
php tests/run-unit.php          # should be green before you start
# ... make your change, with its test ...
php -l src/Q/WebServer.php      # lint every file you touched
php tests/run-unit.php          # green again, with your test in it
```

- Branch from the current release tag for a fix, or from `main` for a feature.
- One logical change per pull request; say what you measured and how.
- Performance claims want the command that produced them, so a reviewer can
  run it. `tests/bench-load.php` prints everything needed to reproduce a run.
- Please do not reformat code you are not otherwise changing.

### Good first contributions

- Make TLS session resumption work (see *TLS is the ceiling* above) — the
  single highest-value open item.
- An ECDSA certificate path alongside RSA.
- Move the TLS handshake off the event loop so concurrent handshakes do not
  serialize.
- More `bench-load.php` output formats, or an open-loop generator to sit
  beside the closed-loop one.

---

## License

**MIT** — see [LICENSE](LICENSE) for the full text.
Copyright (c) 2024–2026 Qbix, Inc.

In plain terms: you may use, copy, modify, merge, publish, distribute,
sublicense and sell this software, including in closed-source and commercial
products, for free. The two conditions are that the copyright notice and the
licence text travel with any substantial portion of the software, and that the
software comes with no warranty and no liability — if it breaks your site, that
is your risk, not the authors'.

There is no contributor licence agreement. By opening a pull request you are
offering your contribution under the same MIT licence as the rest of the
project, which is the ordinary arrangement for a repository of this kind.

Distributing a single-file binary built with this server still distributes this
software, so the licence text goes in the binary too. `docs/binaries.md` covers
how that is packed.

We [proposed `switch_global_context()` for PHP core](https://discourse.thephp.foundation/t/php-dev-three-proposals-for-php-9/2113). While that works its way through the RFC process, the server does it in userland today.
