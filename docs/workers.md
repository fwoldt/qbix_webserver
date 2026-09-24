## 🏭 Workers and the Pool

The server runs PHP in a pool of persistent workers: processes forked from a parent
that has already loaded your application, each answering one request after another.
Between requests a worker is put back into the state it was forked in, so a request
never sees what the one before it left behind. This page covers how the pool is
sized, how requests reach a worker, and when a worker is replaced.
[architecture.md](architecture.md) explains the model; [reset.md](reset.md) lists
exactly what is reset.

- [How many workers](#how-many-workers)
- [Static and dynamic pools](#static-and-dynamic-pools)
- [How a request reaches a worker](#how-a-request-reaches-a-worker)
- [When a worker is replaced](#when-a-worker-is-replaced)
- [Reload and stop](#reload-and-stop)
- [Settings reference](#settings-reference)
- [Watching the pool](#watching-the-pool)

---

### How many workers

`--workers=N` sets the pool size. Without it, the server sizes the pool from the
machine and from what a worker costs:

- a worker is assumed to hold at least what the parent holds after loading the
  application (never less than 8 MB);
- the count is what fits in the memory left after 1 GB is set aside, no more than
  eight per core, and no more than 64;
- it is never fewer than 4.

The server then checks the count against the file descriptor and process limits
and lowers it when they are too tight; on a terminal it asks before starting.
Anything beyond the automatic ceiling is a deliberate choice, made with
`--workers`.

```sh
php qbixserver.php --root=web --workers=32
```

---

### Static and dynamic pools

By default the pool is static: every worker is forked at start and stays.

Set `spareWorkers` and the pool is dynamic. Only the spare workers are forked at
start; when every worker is busy, one more is forked, up to the pool size; and a
worker beyond the spare count that has been idle for `idleWorkerTimeout` seconds is
retired, longest idle first. A busy worker is never retired, and the pool never
falls below the spare count.

```json
{ "Q": { "webserver": { "spareWorkers": 8, "idleWorkerTimeout": 60 } } }
```

A dynamic pool costs less memory on a quiet machine; a static one never forks
while it serves.

---

### How a request reaches a worker

The parent accepts every connection and reads every request. Static files, cache
hits and the server's own pages are answered there, and only a request that runs
PHP goes to a worker: the first idle one, checked to be alive before it is used.

When every worker is busy, the request waits in the parent until one is free; it is
never refused for lack of a worker. The limit on load is `maxConnections`, checked
when a connection is accepted, beyond which the server answers `503`.

If a request cannot be handed to a worker, it goes back to the front of the queue,
up to three times, before the client is answered `502`.

---

### When a worker is replaced

A worker answers the request it has, and is then replaced by a fresh fork, when:

| Reason | Setting |
|---|---|
| It has served `maxRequests` requests. | `maxRequests` (`1000`; `0` for no limit) |
| Its heap has grown past the memory ceiling. | `workerMemoryCeiling` (see [reset.md](reset.md#a-worker-that-grows-is-replaced)) |
| Its output buffers were left unbalanced. | — |
| The application asked for it with `Q_WebServer_Pool::retireAfterResponse($reason)`. | — (see [reset.md](reset.md#code-that-can-run-only-once-per-process)) |

Each replacement is logged with its reason.

A worker that dies while serving is replaced as well. When it died before writing
anything and the request was a `GET`, `HEAD` or `OPTIONS`, the request is tried
once more on another worker; otherwise the client is answered `502`. An idle worker
that exits is noticed within two seconds, removed and replaced. Every exited worker
is reaped, so none is left behind as a zombie.

With `forkPerRequest`, each worker serves one request and exits, and the parent
forks its replacement: the isolation of a fresh process, at the cost of a fork per
request.

---

### Reload and stop

`qbixconsole server:reload` (`qbixctl graceful`) re-executes the server. The
listening sockets are closed first, open connections are given up to five seconds
to finish, and the workers are asked to stop and given three seconds before they are
ended. The new server forks a new pool from the new code and configuration.

The control panel's Workers tab can recycle one worker or all of them without a
reload: an idle worker is replaced at once, a busy one after its current request.

---

### Settings reference

Every setting, with its default. All are under `Q.webserver`.

| Setting | Default | Meaning |
|---|---|---|
| `spareWorkers` | `0` | Workers kept when idle; above `0` makes the pool dynamic. |
| `idleWorkerTimeout` | `60` | Seconds a worker beyond the spare count may stay idle before it is retired. |
| `maxRequests` | `1000` | Requests a worker serves before it is replaced. `0` means no limit. |
| `workerMemoryCeiling` | `256`, or ¾ of `memory_limit` if lower | Heap size in MB past which a worker is replaced. `0` turns it off. |
| `forkPerRequest` | `false` | One request per worker, then a fresh fork. |
| `warmup` | — | A script run once in the parent before the workers are forked. See [reset.md](reset.md#warming-the-pool-in-the-parent-and-the-one-trap-in-it). |
| `keepGlobals` | `[]` | Globals a worker keeps between requests. `--keep-globals` sets it too. |
| `maxConnections` | `1024` | Connections open at once; beyond it the server answers `503`. |

The pool size itself is given with `--workers`.

---

### Watching the pool

| Where | What it shows |
|---|---|
| `/Q/dashboard` | The Workers card (idle and busy, and the maximum of a dynamic pool) and the Worker Memory card, which reports proportional memory so shared pages are counted once. See [dashboard.md](dashboard.md). |
| `/Q/health` | The same figures as JSON for an admin: `workers`, `workersMax`, `workersSpare`, `workerStats`. |
| `/Q/panel`, Workers tab | Every worker with its pid, state and requests served, and the queue length. |
| The console log | One line for each worker replaced, retried or found dead, with the reason. |

---
[← Back to README](../README.md)
