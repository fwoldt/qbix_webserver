# Changelog

Every change worth a reader's time, newest first. A published release links
here, so this file is where a release note goes — not into the release note
itself, where it would be written once and never found again.

## How this is kept

**A tag is permanent, so both the tag and the release are deliberate.**

This repository is a Composer package, and Packagist reads every tag. It
currently knows 43 versions — including `v0.0.4.26`, which has no GitHub
release at all. Publishing nothing did not make that version number free; it
made it a version of this package that exists, forever, describing itself with
whatever the tree held at the time.

A tag therefore cannot be withdrawn, moved or re-cut. Packagist caches the
version it found on first read, so a "corrected" tag yields two different
packages wearing one version number, which is worse than the original mistake
and impossible to diagnose from outside. **The only correct response to a bad
version is to publish the next one** and say plainly in its notes what was
wrong with the one before.

So the rule is not "tag freely, release rarely" — it is *accumulate* freely and
tag rarely. Work lands on the branch as ordinary commits and collects under
`## Unreleased`. A tag is made when that accumulation is worth a version
number.

The workflow adds the second half: **it publishes a release only for a tag that
has a section in this file.** Writing the section is the act of deciding to
release, which is why a version and its notes can no longer describe different
things. A tag without a section still builds and tests — useful for proving a
commit before it is released — but it is still a permanent Packagist version,
so it is not free either.

At release time:

1. Check what is already published. Never guess the next number:
   ```bash
   git fetch --tags
   git tag -l 'v*' --sort=version:refname | tail -5   # never plain `tail -1`
   gh release list --limit 10
   ```
   A lexical sort puts `0.0.4.10` *before* `0.0.4.8`, and a tag can exist with
   no release, so check both lists.
2. Confirm the tree is clean, the suite passes, and the committed phar matches
   its sources.
3. Move everything under `## Unreleased` into a new `## vX.Y.Z.N` heading.
4. Write the one-line summary on that heading. It becomes the release title.
5. Commit, then tag that commit.

Only the last position increments: `0.0.4.9` → `0.0.4.10` → `0.0.4.11`, never
`0.0.5.0`. The last position is an integer and keeps counting; moving anything
above it is a decision about what the release *means*, not a consequence of
reaching nine.

Entries use the same four prefixes as commit messages — `Added`, `Fixed`,
`Updated`, `Removed` — so a section can be assembled from `git log` and then
edited down to what a reader actually needs.

---

## Unreleased

### Fixed

- **Every request left an output buffer behind, so persistent workers grew
  without limit.** The response was captured in a buffer opened per request
  with `ob_start(null, 0, 0)`; flags `0` make a buffer impossible to remove
  *and* impossible to clean, so each request's buffer -- with its whole page in
  it -- stayed on the stack for the life of the worker. Measured on an
  Exponential install at ~2 MB a request: one worker at 1.1 GB after 600
  requests. The same happened in the in-process server. Responses looked
  right, because the body was read from whichever buffer was on top -- except
  when a script left a buffer of its own open, when only that buffer's content
  was sent. There is now one capture buffer per process, reused and emptied
  each request (`Q_WebServer_Capture`), and buffers a script leaves open are
  part of its response.
- **Every request left its error handler behind.** PHP keeps each handler
  that `set_error_handler()` replaces on an internal stack no PHP code can
  see, and the between-request reset "restored" the boot handler by setting
  it -- one more push. Exponential installs a method of its eZDebug instance,
  which holds everything the request logged, so every request's debug log
  stayed in the worker: ~1 MB a request, 4 MB for a search page. The reset
  now pops handlers until the boot one is current, compared by identity.
- **The file wrapper leaked a resource on every filesystem call.** To reach
  the disk, the compat `file://` wrapper unregistered and re-registered
  itself, and each registration is a resource PHP frees only when a request
  ends -- never, in a worker. Exponential makes up to 14 000 such calls a
  request. Missing paths and directory listings are now answered with
  `glob()`, which bypasses the wrappers; `fstat()` on an open file no longer
  unwraps; the transform sends `file_exists()`, `is_dir()` and `is_file()`
  to shims that ask the OS directly; and repeated stats of a path within a
  request are remembered (forgotten on any write through the wrapper and at
  the end of the request). Per-request calls went from thousands to
  hundreds. A transformed file's stat now carries its mtime, without which
  the opcode cache would not store it, so transformed scripts are no longer
  recompiled on every include.
- `is_link()` was false for every link under the compat wrapper, which
  answered link queries with `stat()` instead of `lstat()`.
- **Includes no longer cost a leaked resource each.** A template engine
  includes the same compiled templates over and over (~1 900 includes of a
  few dozen files on one search page), and each was a real open through the
  wrapper. Included files are now read once and their bytes kept (at most
  4 MB per process), served while a real stat taken in the same request
  still matches their mtime and size -- so what runs is always the file as
  it is on disk. Mixed traffic now grows a worker ~0.1 MB a request.
- **`$db or die(...)`, `else exit;` and `$ok || exit(1)` ended the worker.**
  The source transform's member test was written `$isMember = is_array($prev)
  and (...)`; `=` binds tighter than `and`, so it assigned `is_array($prev)`
  alone, and any `exit` or `die` after a keyword or operator counted as a
  method call and stayed a real exit. Every such exit in an application killed
  the worker serving it.
- **A worker that exited deleted the server's pid file and killed its
  watchdog.** The cleanup is a shutdown function registered in the parent,
  and every forked worker inherited it -- so a worker replaced at its memory
  ceiling, retired at the application's request, or crashed, ran it on the
  way out. The server kept serving, but `status` and `stop` could no longer
  find it. It now acts only in the process that registered it.
- **Cyclic garbage piled up in workers.** PHP runs its cycle collector only
  when the root buffer fills, and raises that threshold whenever a run finds
  little, so in a process that never ends a request, one request's
  self-referencing objects outlived it: ~47 KB a request on an Exponential
  install. The between-request reset now collects cycles; the buffer only
  ever holds one request's candidates, so it is cheap.
- **Fewer wrapper registrations still.** With an engine archive in use the
  wrapper also swapped out `phar://` -- one more registration -- around every
  operation, even on plain files; it now does so only for phar paths. And
  `filemtime()` and `filesize()`, which return one number each, are sent by
  the transform to shims that ask libcurl's `file://` (which bypasses PHP's
  stream wrappers) and never put a partial stat in PHP's stat cache; includes
  use the same. A worker now makes ~12 registrations a request where it made
  12 600.
- **Edited and regenerated files are picked up without a restart.** The
  opcode cache reads its clock only at request startup, which a persistent
  worker never repeats, so it never revalidated a cached script: a
  regenerated template or an edited class ran its old compile until the
  server restarted. The wrapper now invalidates a script's compile when it
  sees the file's mtime move. The transform cache had the same fault -- an
  edited file that needs the transform kept its old transformed source --
  and now re-transforms on a changed mtime.

Together: a worker that grew ~2 MB a request (1.1 GB after 600 on an
Exponential install) now holds a flat heap -- ~2 KB a request, the few
registrations left -- bounded in any case by the worker memory ceiling below.

- **The "Worker Memory (COW)" card reported several times the real memory.** It
  summed each worker's RSS, and RSS counts a shared copy-on-write page in full
  against every process mapping it -- so the warmed baseline shared across 380
  workers showed as ~15 GB, the opposite of what copy-on-write does, and it did
  not fall when the server restarted because every worker inherits that shared
  baseline at birth. The card now reports PSS (proportional set size) summed
  over the parent and workers, which is the actual physical memory: ~2.9 GB
  where RSS claimed ~15 GB.
- The live request log printed raw millisecond floats
  (`502.26688385009766ms`); durations are rounded to one decimal, at the source
  and in the render.
- The status-code filter offered only codes seen live after the page loaded;
  it now lists every code the server has recorded, from the stats it already
  sends.
- **Reading the worker-memory card wedged the server at scale.** It read
  `/proc/<pid>/smaps_rollup` for every worker to sum PSS, inside the single
  event loop, every couple of seconds while a dashboard was open. That read
  walks all of a process's mappings, so at hundreds of workers it stalled the
  loop long enough that no connection could be accepted -- the front page timed
  out, not just the dashboard. It now samples a bounded set of workers and
  scales the average, at a fixed cost regardless of pool size.

### Added

- `Q_WebServer_Pool::retireAfterResponse($reason)`, for application code that
  can run only once per process -- one that defines constants from the
  request, say. The request is answered normally; the parent then replaces
  the worker and logs the reason. Outside a pool worker it does nothing.
- **A per-request health check that replaces a worker instead of letting it
  grow.** After each request a worker checks that its output stack is back to
  the one empty capture buffer and that its heap is under
  `Q.webserver.workerMemoryCeiling` (MB; default 256, or three quarters of
  `memory_limit` if lower). A worker that fails still answers, and the parent
  replaces it before its next request and logs the reason, so a leak anywhere
  costs a re-fork and a named log line rather than the machine's memory.
- **A parent warm-up, `Q.webserver.warmup`.** A script the pool runs once in the
  parent, after the source-code transform is installed and before it forks --
  typically one rendering a representative page. The arena that render grows is
  inherited copy-on-write, so a warm worker holds ~21 MB private against ~209
  MB without it on an Exponential install. It has its own key because the
  existing `Q.webserver.preload` is required before the transform exists: a
  script run there compiled the whole application untransformed, and every
  worker inherited a real `exit` (answering `502 Worker died` wherever the app
  finishes a request with `exit`) and a `header()` that does nothing under the
  CLI SAPI. The server now warns at startup when `preload` is set with the
  transform on.
- Column headings on the live request log (Time, Sts, Verb, Path, ms, Mem).
- The dashboard's Workers card says what its numbers are. It showed
  "590/590" (idle of total, unlabelled) over "reqs: 5 PHP / 7 static", which
  counts requests served and was read as "only 5 PHP workers". It now shows
  the worker count, then idle and busy workers, then PHP requests and static
  files served, each on its own labelled row.
- The dashboard reads at a glance: the status-code and worker-memory cards
  list one item per line, the header reads "Linux · PHP x · Live · Up 15s",
  and "Documentation · Powered by the Qbix engine" has its own centred line
  in the footer.
- The dashboard's own heading links to the dashboard; the footer's product name
  keeps its link to the repository.
- Swap usage on the System RAM card. A box can read a comfortable RAM
  percentage while it has pushed gigabytes to disk under earlier pressure; the
  card now shows swap when any is in use and tints red then, so the reading is
  not falsely reassuring.
- An `exponential` framework preset (`--preset=exponential`), for the eZ
  Publish 4 legacy line. Alongside the front controller and ini limits it keeps
  the source-code transform on (the kernel calls `header()`/`setcookie()` the
  SAPI-coupled way) and preserves the type registries across requests -- both
  settings a modern framework does not need and would otherwise be found the
  hard way. A preset can now carry a `_webserver` block that merges under
  `Q.webserver`, which is how the preset reaches `keepGlobals`.
- The product name across the served views is a parameter (`Q.webserver.brand`),
  with optional links for it and a maintainer credit, and the shown version is
  the fork's own release from `git describe`, stamped at build time, with the
  upstream number kept as the engine it is built on.


### Added

- The brand and maintainer labels can now carry links. `Q.webserver.brandUrl`
  links the product name (to its repository), and `Q.webserver.maintainer` /
  `maintainerUrl` add a "Maintained by <name>" credit that links where you say.
  All are empty by default, so upstream shows plain text and links nothing it
  was not given. A url key that is not http/https is dropped rather than
  linked, so a malformed setting cannot inject a `javascript:` link.
- The version shown is now the fork's own -- `v0.0.4.26`, the nearest release
  tag on this branch -- not upstream's `1.5.0`, which stays defined as the
  engine this is built on and is named in the footer ("powered by the Qbix
  engine"). The ship version is stamped from `git describe` at build time, so
  reading it costs nothing at runtime. The dashboard footer also carries a
  "Maintained by 7x" line.
- The served views now carry a footer, and the version display carries the
  build: the short commit and the datetime the phar was built, e.g.
  `Exponential Velocity v1.5.0+a34150c (2026-09-23 17:16 UTC)`. `build-phar.php`
  stamps both into the phar and a file on disk beside `qbixserver.php`, because
  the server is often run as a plain vendored file rather than through the phar
  stub — and a vendor directory is frequently its own checkout on an unrelated
  commit, so asking git at runtime reported the wrong thing. The stamp is
  semver build metadata after a `+`, which every version comparator ignores.
- The product name shown across the served `/Q/` views is now a parameter,
  `Q.webserver.brand`, default `Qbix Server`. `Q_WebServer::brand()` is the one
  accessor; the dashboard heading and title, the docs chrome, the panel title
  and the manifest's human-readable fields all ask it rather than hardcoding a
  name. A fork can name itself without editing a dozen views or diverging from
  upstream at every occurrence. The wire identifiers — the `.well-known/qbix`
  path, `qbix.json`, the `qbix` framework key, `isQbixApp`, the provider name —
  are protocol, not brand, and are deliberately left as they are: renaming them
  would break federation and app detection for a cosmetic gain.

### Fixed

- **The dashboard's live WebSocket never connected over HTTPS.** It hardcoded
  `ws://`, and a browser blocks an insecure socket opened from an `https://`
  page as mixed content — so the status sat on a red "connecting" that never
  resolved, and the page only updated on reload. The scheme and host are now
  taken from `location` in the browser, which is authoritative for both, so it
  is `wss://` on a secure page and the port is always right.
- The HTTP/2 route did not answer the server's own URLs. `/Q/dashboard`,
  `/Q/health` and `/Q/metrics` were handled on HTTP/1.1 only, so every browser
  — which negotiates HTTP/2 — got the application's 404 from the dashboard
  while `curl --http1.1` got 200. The delegation now sits *behind* every
  refusal the HTTP/1.1 path makes, because `route()` can fall through to
  serving a static file and in front of the guards it would serve files around
  them.
- HTTP/2 served files that HTTP/1.1 refused, including `/settings/site.ini`
  — 74KB of configuration with database credentials in it — and `/.git/config`.
  The blocked list and the extension allow-list were consulted on one path and
  not the other, so everything they protected was protected only from clients
  old enough to ask in the older protocol.
- **Durations were printed to thirteen decimal places.** The dashboard card
  read `Avg response 258.4ms` above `slowest: 1541.8879985809326ms` — two
  numbers side by side disagreeing about how precisely this server measures
  anything, with the second long enough to break the width of the card holding
  it. The same raw value went out in `/Q/health`, and the console access log
  had it too (`GET /slow.php (125.39982795715ms)`) while the *file* access log
  had always used one decimal. Rounded where the numbers are produced rather
  than where they are shown, so every consumer benefits.
- **The log filled with `ReflectionProperty::setAccessible() is deprecated`.**
  Once per static property, every time a snapshot was taken. The call has had
  no effect since PHP 8.1 — this package's own minimum — and PHP 8.5 deprecates
  it, so the only thing it still did was write the notice.
- **The dashboard showed `\u00B7` and `\u2014` as text.** Thirteen JavaScript
  escapes were written directly into the dashboard's HTML, where nothing
  interprets them — PHP reads only `\u{00B7}`, with braces, and JavaScript
  never saw these. So the status line read `546 ok \u00B7 28 redir` instead of
  `546 ok · 28 redir`; the Workers, System RAM and Worker Memory cards showed
  `\u2014` where a value belongs; and the pause and close buttons were
  labelled `\u23F8` and `\u2715`. Nothing failed, and nothing could have: the
  page rendered perfectly, reading wrongly. It was found by somebody looking at
  it. They are now HTML entities, and the identical escapes inside `<script>`
  — where they *are* interpreted — were left alone.
- **The dashboard could not see most of HTTP/2.** A script is handed to the
  worker pool, which records it when it answers, so PHP requests were counted
  on both protocols and the numbers looked right. Everything the HTTP/2 route
  answered itself — static files, and *every refusal* — was counted nowhere.
  Measured: five requests for `/.git/config` over HTTP/1.1 moved the 4xx
  counter from 2 to 7; five identical requests over HTTP/2 moved it from 7 to
  7. A browser negotiates HTTP/2, so anyone probing the server with a modern
  client produced a dashboard showing that nothing had happened.
- A signed-in visitor's pages were cached and served to everybody else. The
  cache skips a request carrying a session cookie, but the match required the
  configured name followed immediately by `=`. Exponential's cookie is
  `eZSESSID<digest>`, so the skip never fired.
- Two tests left a server running every time they ran — `proc_open` with shell
  redirection reports the shell's pid, so terminating it orphaned the server.
  Thirty-nine had accumulated on one machine.
- The Windows build had no archiver available to extract php-src with, and
  before that failed on a line continuation written for the wrong shell and
  asked spc for a library it cannot build on that platform.
- OpenBSD installed nothing at all; NetBSD was missing a dependency by name.
- The Windows build, properly this time. `windows-latest` had migrated to the
  `windows-2025-vs2026` image, which installs Visual Studio 2026 at
  `...\Microsoft Visual Studio\18\Enterprise` — VS 2026 is version *18*, not a
  `2022` directory. spc finds Visual Studio by testing six hardcoded paths for
  2022 and 2019, so it found none, returned `false`, and the caller read
  `['version']` off it. The build therefore died twenty minutes in with
  `Current VS version  is not supported yet!` — an empty version, and no
  mention of the one thing that was wrong. **Nothing in this repository
  changed; the runner did.** The job is now pinned to `windows-2022`, the
  toolchain spc actually targets, and a two-second precondition step reports
  the real cause by name if an image ever moves again.

### Updated

- The platform documentation now advertises what this actually runs on, in
  three honest tiers — proven, expected, and not today — rather than implying
  uniform support.
- The Amiga answer is now a map for someone who might attempt the port, naming
  the PHP 5 to PHP 8 library gaps that stand in the way, rather than a refusal.
- A release title carries its summary instead of repeating the tag.

### Added

- This changelog, and a release process built on it. A published release is now
  a deliberate batch rather than a side effect of tagging: the workflow
  publishes a release **only** for a tag with a section here, so tags stay
  cheap and releases stay meaningful. Eight releases went out in one day before
  this, three with no title at all. Each release links back to the full
  changelog and to every commit since the release before it.
- `tests/unit-http2-route-order.php`, asserting that every refusal in
  `http2Route()` is present *and* in an order where it can do its job. Each
  assertion was checked against a deliberately broken copy of the source, so
  the test fails when the guards move rather than passing regardless.

---

## v0.0.4.25 — three security fixes, and macOS ships for the first time

2026-09-23

### Fixed

- **A symbolic link inside the document root served, and executed, files
  outside it.** A link named `*.php` turned the ability to create one file into
  the ability to run code from anywhere the server user can read. Containment
  is now checked on the resolved path at all four dispatch points; closing
  three of them was not enough, because with a worker pool configured the
  dispatch reaches `handlePhp()` by a different road.
- **Contradictory request framing was resolved rather than refused**, which is
  request smuggling (RFC 9112 §6.1). Bare LF line endings were also accepted,
  and a request using them never completed at all — it held a connection slot
  until the read timeout, from one short write.
- **Six HTTP/2 frames the RFC says must be refused were accepted instead**,
  including frames larger than the size the server itself advertised.
- A worker answered from its own stat cache when deciding whether a
  revalidation claim had been abandoned.
- The last writes that could go out short without anyone noticing, on the IPC
  pipe and the session file.
- Workers exited after one request, from a write that handed the socket back
  non-blocking.
- `php-cgi` mode answered every request with "Class Q_WebServer not found".
- The server died on FreeBSD after printing its banner.
- riscv64 segfaulted under emulation until PCRE's JIT was turned off.

### Added

- The macOS binary. **It was never broken** — the test that condemned it never
  started the server, and the claim has been withdrawn from the README.
- `docs/security.md`, recording what the server refuses and why, for anyone
  maintaining this or a fork of it.

## v0.0.4.24 — the phar is published as a release asset for the first time

2026-09-23

### Added

- A platform matrix that watches the phar serve a page on systems we ship no
  binary for, covering musl, DragonFly, illumos, RISC-V and ARMv5.

### Fixed

- `--stop` and `--reload` exited 0 without ever sending the signal.
- Every platform job was failing, on two unrelated causes.
- The README offered Windows and macOS downloads that did not exist.
- The BSD install commands were mangled into one line, and the BSD jobs died at
  startup on a missing tokenizer.

## v0.0.4.23 — the control commands work, and the release pipeline produces a release

2026-09-23

### Fixed

- **The server ignored SIGTERM**, so `stop` and `restart` timed out instead of
  working.
- Header values could write headers of their own. Every response now goes
  through one serialiser; the guard had existed in one of seven places that
  wrote headers.
- WebSocket frames and worker packets were written without checking they went
  out whole, and the worker pool counted a short write of a request as success.
- A hostname ending in a newline or a hyphen passed validation before reaching
  certbot, a resolver and the log.
- Every release carried one frozen name, and a broken macOS binary blocked the
  other three platforms.

### Added

- A pre-warm cache that survives a restart, taking startup from 4.7s to 0.6s.

## v0.0.4.20 — the cache-filling request is served what was stored

2026-09-23

### Fixed

- The request that filled the cache was served different bytes from every
  request after it. The caller now gets the response back as it was stored.

## v0.0.4.19 and earlier

See the [release history](https://github.com/se7enxweb/qbix-webserver/releases)
and `git log`. Entries before this file existed were not written up.

**`v0.0.4.21`, `v0.0.4.22` and `v0.0.4.26` are tags with no release.** Their
builds did not produce a usable artifact, and the gaps are left in place rather
than backfilled — a version number that never shipped anything is more honest
as a hole than as a release with nothing behind it.

They are not, however, absent. Packagist read each of those tags and they are
installable versions of this package; there is simply no release page and no
binary. If you have pinned one, move to the next version above it. They are
left alone rather than deleted because withdrawing a published version breaks
anything that already resolved it, and because re-cutting a tag is the one
repair that makes things worse.
