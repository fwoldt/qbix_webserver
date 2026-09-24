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

Password-protected admin panel at `/Q/panel`. The first visit from this machine sets the password, or set it with `qbixctl panel:password` (below).

**Apps tab** — discovers sibling app directories (any folder with `web/` or `config/app.json`). Create new apps, serve them (hot-switches the document root), open in VS Code, run configure scripts. Editable apps directory path.

**Scripts tab** — list and run PHP scripts from `scripts/Q/` (configure, install, translate, etc.)

**Plugins tab** — reads the app's `config/app.json` for declared plugins, `local/plugins.json` for installed versions, and scans the Platform's `plugins/` directory. Shows version, dependencies, and DB connections for each.

**Playground tab** — PHP REPL with all Q classes preloaded. Write code, hit Run (or Ctrl+Enter), see output. Sandboxed in a forked process with disabled filesystem writes, no network, 32MB memory limit, 5 second timeout.

**System tab** — PHP version, OS, extensions, memory limit. One-click Platform install: clones `github.com/Qbix/Platform`, runs `git submodule update --recursive`, sets up `local/paths.json`.

### Who can reach the panel

The same rule on HTTP/1.1 and HTTP/2, for `/Q/panel` and everything under `/Q/api/`:

| From | Allowed when |
|---|---|
| This machine | always |
| Anywhere else | a panel password is set (the page shows its login form, and every API call but `auth/login` needs the session it gives), **or** the request carries the dashboard token or a panel session, **or** `Q.panel.remote` or `Q.dashboard.remote` is `true` |

Anything else gets the server's own 403 page, which says how to set a password.

The **first** password can be set in the page only from this machine, with the
dashboard token, or where `Q.panel.remote` is `true` -- never by whoever happens to
reach the page first. A remote visitor who reaches a panel with no password yet is
told how to set it instead of being offered the form.

### Setting the password from the command line

```sh
qbixctl panel:password --root=/path/to/web          # asks twice, without echo
echo 'a new password' | qbixctl panel:password --root=/path/to/web
qbixctl panel:password --root=/path/to/web --password='a new password'
```

Pass the same `--root` the server runs with (or `--app=DIR` for a server run with
`--app`): the password goes where the server keeps it, `local/panel.json` in the
directory above the document root, stored as the page stores it. Changing it signs
out every existing session. A running server uses it on the next request; nothing
needs restarting. `qbixconsole panel:password` is the same command.

---

---
[← Back to README](../README.md)

