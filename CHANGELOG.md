# Changelog

Every change worth a reader's time, newest first. A published release links
here, so this file is where a release note goes — not into the release note
itself, where it would be written once and never found again.

## How this is kept

**A tag is cheap. A release is not.** Tags are made whenever a build is worth
having; a *published release* is a deliberate batch, and it happens when there
is enough here to be worth somebody's attention.

The release workflow enforces exactly that: **it publishes a release only for a
tag that has a section in this file.** Tag whatever you like — a tag with no
section below builds and tests and then stops, leaving no release behind. That
is the whole mechanism, and it is the reason a version number and its notes can
no longer drift apart.

So the order of work at release time is:

1. Move everything under `## Unreleased` into a new `## vX.Y.Z.N` heading.
2. Write the one-line summary under the heading. It becomes the release title.
3. Commit, then tag that commit.

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
