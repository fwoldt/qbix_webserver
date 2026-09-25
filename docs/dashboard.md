## 📊 Live Dashboard

Open `http://localhost/Q/dashboard` in your browser for a real-time server dashboard. Updates live via WebSocket — no polling, no page refreshes.

**What it shows:**

| Panel | Metrics |
|---|---|
| **Overview cards** | Total requests, current RPS (5-sec window), avg response time, slowest request, memory usage + peak, worker status, WebSocket connections, active rooms, data transferred, open connections |
| **Throughput sparkline** | Per-second request rate for the last 60 seconds — see traffic patterns at a glance |
| **Top paths** | Most-requested URLs with hit count and average response time — find your hot paths |
| **Active rooms** | WebSocket room workers with member count — monitor real-time features |
| **Live request log** | Scrolling feed of every request: timestamp, status code (color-coded), method, URI, response time in ms |

**Endpoints:**

| URL | Format | Use case |
|---|---|---|
| `/Q/dashboard` | HTML | Browser — the visual dashboard |
| `/Q/health` | JSON | Load balancers, uptime monitors (lightweight) |
| `/Q/stats` | JSON | Monitoring systems — full stats payload |
| `/Q/metrics` | Text | Prometheus scrapers — request, latency, worker and memory figures |
| `/Q/phpinfo` | HTML | PHP's own report: version, extensions, ini settings |

The dashboard, the control panel and the documentation each carry a toolbar near
the top — Dashboard, Control Panel, Documentation, PHP Info, Health and Metrics —
with the current view marked, so each view is a click from the others.

The dashboard, `/Q/stats`, `/Q/metrics` and `/Q/phpinfo` are for an admin: they
answer requests from this machine, and from elsewhere only with the dashboard token
or a control panel session (or when `Q.dashboard.remote` is set). `/Q/phpinfo`
shows the server process's environment, so from elsewhere it always needs the
credential. `/Q/health` answers anyone with
`{"status":"ok"}` and gives the figures to an admin only.

The `/Q/stats` JSON includes everything the dashboard shows, plus `sparkline` (60 data points), `topPaths`, `activeRooms`, `statusCodes` breakdown, and `cache` stats. Feed it to Grafana, Datadog, or your own monitoring.

**Reading the memory cards — they report what is true, not what is easy:**

- **Worker Memory (COW)** is **PSS** (proportional set size), summed over the
  parent and its workers, not each worker's RSS added up. Workers are forked, so
  RSS counts every page shared after the fork once per worker — at a few hundred
  workers that reads as *ten times* the real memory and does not fall on a
  restart. PSS divides each shared page by the number sharing it, so the sum is
  the actual resident memory. To keep it cheap the card **samples** a bounded set
  of workers rather than reading `/proc` for every one, which at scale would
  stall the event loop that serves the dashboard.
- **System RAM** is used = Total − MemAvailable (reclaimable cache counts as
  free, as `free` reports it), and it **also shows swap when any is in use** —
  and tints red then, however low the RAM percentage looks. A box can sit at a
  comfortable 42% while it has pushed gigabytes to disk under earlier pressure,
  which the percentage alone hides.
- **Durations** — the slowest-request figure and every row in the live log — are
  rounded to one decimal; a `microtime()` difference is otherwise thirteen.
- **The live log** carries column headings, and the **status filter** lists every
  code the server has recorded since start, not only those that streamed past
  after the page opened.

---

## ⚙️ Control Panel

Password-protected admin panel at `/Q/panel`. Until a password is chosen it signs in with the default key `panel` and asks for a new one straight away; or set one with `qbixctl panel:password` (below).

**Apps tab** — two lists:

- **Installations**: every PHP application the server can see, whatever it is
  built on, with the one it is serving first ("serving on this port"). Each
  card shows the name and release (for example *Laravel 11.9.2*), where it
  lives, its web root, and what it says about itself.
- **Your Apps**: the apps directory's Qbix apps, which can be created, served
  (hot-switches the document root), configured and opened from here. The apps
  directory path is editable.

**Frameworks tab** — the same detections, minus plain PHP sites, each with the
tools its own command line offers (clear caches, migrations, route lists, …)
and its installed packages. A command marked `…` changes the running
installation and asks before it runs; the server refuses it without that
confirmation too.

### What is detected, and how

One registry (`Q_WebServer_Framework`) answers the Apps tab, the Frameworks
tab and the autohost, so the three always agree. It looks in:

1. the document root being served, and its parent (a `public/` or `web/` root
   inside a project counts as that project);
2. the panel's apps directory and each directory directly inside it;
3. every directory in `Q.panel.appRoots`, and each directory directly inside.

It never walks deeper, and stops after 400 directories in one scan.

| Recognised | By | Release read from |
|---|---|---|
| Laravel | `artisan` | `vendor/laravel/framework/…/Application.php` (`VERSION`), else `composer.lock` |
| Symfony | `bin/console` + Symfony in `composer.json` | `vendor/symfony/http-kernel/Kernel.php` (`VERSION`) |
| WordPress | `wp-config.php` or `wp-includes/version.php` | `$wp_version` in `wp-includes/version.php` |
| Drupal | `core/lib/Drupal.php` (in the root or `web/`) | `Drupal::VERSION` |
| Joomla | `administrator/` + `configuration.php` | `libraries/src/Version.php` |
| Magento, TYPO3, Craft CMS, Moodle, MediaWiki, Nextcloud, PrestaShop, Laminas, FuelPHP | each one's own layout | each one's own version file or `composer.lock` |
| Qbix | `config/app.json` or `web/Q.php` | — |
| Composer app | `composer.json` + an `index.php` to serve | `composer.json` |
| PHP site | `index.php` | — |

A distribution of the server can add detectors for applications it supports
(`Q_WebServer_Framework::register()`), and they are tried before the generic
ones.

Version files are **parsed, never included**: including one would declare its
class or constants inside the server, where a second installation of the same
application would then collide. Only literal values are taken — class
constants, `define()`s, top-level variables, and functions that return a
literal or a constant.

Commands run as argument lists (no shell) in the application's own
directory, with a 120-second limit and 1 MB of output; only the commands the
detector lists can run. Composer is not run from the panel for an application
whose detector marks it as not composer-managed (its packages may be live
checkouts), unless `Q.panel.allowComposerWrite` is set.

### Bookmarkable tabs

Every tab has its own address: `/Q/panel/(tab)/logs`, `/Q/panel/(tab)/system`
and so on, `/(name)/value` pairs after `/Q/panel`. Opening one lands on that
tab; changing tab adds a history entry, so Back and Forward move between tabs.
The Logs tab keeps its filters in the address too, for example
`/Q/panel/(tab)/logs/(type)/access/(status)/5xx/(filter)/checkout`, and its
**Copy link** button copies exactly that.

### Logs tab

The server's access and error logs, newest last, as a table for the access
log (time, method, path, status coloured by class, size, duration; the user
agent on hover) and as lines for the error log.

- **Filters**: free text, HTTP method, and status: an exact code (`404`) or
  a class (`5` or `5xx`). A bad method or status is refused with the reason,
  not ignored.
- **How far back**: without a filter the last 50–500 lines are read; with one,
  the last 2 MB of the file are searched (`Q.panel.logScanBytes`) and the
  newest matches shown, with how many matched and how much was searched. The
  whole file is never read into memory.
- **Tail** refreshes every two seconds while the tab is open; **Download**
  saves the lines shown.

The log API (`/Q/api/logs?type=access|error&lines=&filter=&method=&status=`)
needs a signed-in panel session like every other panel API.

### The other tabs

**Scripts tab** — list and run PHP scripts from `scripts/Q/` (configure, install, translate, etc.)

**Plugins tab** — reads the app's `config/app.json` for declared plugins, `local/plugins.json` for installed versions, and scans the Platform's `plugins/` directory. Shows version, dependencies, and DB connections for each.

**Playground tab** — PHP REPL with all Q classes preloaded. Write code, hit Run (or Ctrl+Enter), see output. Sandboxed in a forked process with disabled filesystem writes, no network, 32MB memory limit, 5 second timeout.

**System tab** — PHP version, OS, extensions, memory limit. One-click Platform install: clones `github.com/Qbix/Platform`, runs `git submodule update --recursive`, sets up `local/paths.json`.

### Who can reach the panel

The same rule on HTTP/1.1 and HTTP/2, for `/Q/panel` and everything under `/Q/api/`:

| From | Allowed when |
|---|---|
| This machine | always |
| Anywhere else | a password is set, or the default key is in force (the page shows its login form; every API call but `auth/login` needs the session it gives), **or** the request carries the dashboard token or a panel session, **or** `Q.panel.remote` or `Q.dashboard.remote` is `true` |

Anything else gets the server's own 403 page, which says how to set a password.

Until a password is chosen, the **default key `panel`** signs in -- from anywhere,
so a server can be set up from outside -- and the session it gives can only change
it: the page shows nothing but a change form until a password that passes the rules
in [passwords.md](passwords.md) is set. While the default is unchanged, anyone who
knows it can sign in first; set `Q.panel.defaultLocalOnly` to accept it only from
this machine (or with the dashboard token), or set `Q.panel.defaultPassword` to
`null` to switch it off, in which case the first password is set from this machine,
with the dashboard token, or on the command line.

With the default switched off, the **first** password can be set in the page only from this machine, with the
dashboard token, or where `Q.panel.remote` is `true` -- never by whoever happens to
reach the page first. A remote visitor who reaches a panel with no password yet is
told how to set it instead of being offered the form.

### Setting the password from the command line

```sh
qbixctl panel:password --root=/path/to/web          # asks twice, without echo
echo 'a new password' | qbixctl panel:password --root=/path/to/web
qbixctl panel:password --root=/path/to/web --password='a new password'
qbixctl panel:password --root=/path/to/web --generate     # makes a strong one, prints it once
```

Pass the same `--root` the server runs with (or `--app=DIR` for a server run with
`--app`): the password goes where the server keeps it, `local/panel.json` in the
directory above the document root -- for `--root=/srv/site/web`, that is
`/srv/site/local/panel.json` -- stored as the page stores it (bcrypt). It must pass
the rules in [passwords.md](passwords.md); `--generate` makes one that does, sets it
and prints it once. Changing it signs
out every existing session. A running server uses it on the next request; nothing
needs restarting. `qbixconsole panel:password` is the same command.

---

---
[← Back to README](../README.md)

