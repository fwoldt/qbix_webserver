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
