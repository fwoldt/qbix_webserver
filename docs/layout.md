## 🗂️ Configuration Layout

The server can keep its configuration in a directory laid out like Debian's
`/etc/apache2`, because that is the structure most administrators already know.
The standard place is `/etc/qbix`. Sites, shared snippets and engine modules each
have an `-available` directory holding the files and an `-enabled` directory
holding symlinks to the ones in use; enabling or disabling one is a symlink,
made or removed by the console.

- [The tree](#the-tree)
- [How the directory is chosen](#how-the-directory-is-chosen)
- [Load order](#load-order)
- [Enabling and disabling](#enabling-and-disabling)
- [Overlays and distributions](#overlays-and-distributions)
- [Checking what is used](#checking-what-is-used)

---

### The tree

```
/etc/qbix/
  qbix.conf               base settings                  (apache2.conf)
  ports.conf              listen ports                   (ports.conf)
  envvars                 environment for the process    (envvars)
  mods-available/*.conf   engine modules                 (mods-available)
  mods-enabled/           symlinks to the modules in use
  conf-available/*.conf   shared snippets                (conf-available)
  conf-enabled/           symlinks to the snippets in use
  sites-available/*.conf  one file per installation      (sites-available)
  sites-enabled/          symlinks to the sites in use
  designs/                the server's own page designs  (see designs.md)
  ssl/                    certificates                   (see https.md)
```

Every file holds a JSON object in the engine's usual configuration format, under
Apache's file names. `envvars` holds `export NAME=value` lines, as Apache's does;
the server reads it, and never runs it as a script.

Only `*.conf` and `*.json` files in an `-enabled` directory are loaded, as Apache
includes only `*.conf`, so an editor's backup file is never read as a setting. A
symlink whose target has gone is treated as disabled, not as an error.

The base file is named after its directory — `qbix.conf` in `/etc/qbix` — and
`qbix.conf` is accepted in any tree, so a tree moved elsewhere keeps working.

---

### How the directory is chosen

The directory is used only when something asks for it. The server never picks up
`/etc/qbix` just because it exists, so a machine's configuration cannot change how
an unrelated server, or a test suite, behaves.

| Asked for by | Meaning |
|---|---|
| `--conf-dir=DIR` | Use `DIR`. |
| `--conf-dir=auto` | Use the first standard place that holds a configuration. |
| `--conf-dir=none` | Use no configuration directory. |
| `QBIX_CONF_DIR` | The same, from the environment, when `--conf-dir` is not given. |
| `--config=DIR/sites-enabled/SITE.conf` | A site file inside a `sites-enabled` or `sites-available` directory names its tree. |

A directory counts as a configuration directory when it has a base file or any of
the `-available` and `-enabled` directories.

---

### Load order

Files are merged in the order `apache2.conf` includes them, and a later file wins:

1. the base file (`qbix.conf`),
2. `ports.conf`,
3. `mods-enabled/`, in name order,
4. `conf-enabled/`, in name order,
5. the `--config` file, which is normally one of `sites-enabled/`.

The site file is given with `--config`: the server loads the site it is started
for, not every enabled site at once.

```sh
php qbixserver.php --config=/etc/qbix/sites-enabled/example.com.conf
```

---

### Enabling and disabling

The console does what `a2ensite` and its family do: it makes or removes a relative
symlink in the `-enabled` directory, pointing at the file of the same name in
`-available`.

```sh
qbixconsole site:enable example.com      # a2ensite example.com
qbixconsole site:disable example.com     # a2dissite example.com
qbixconsole conf:enable logging          # a2enconf logging
qbixconsole mod:disable http2            # a2dismod http2
qbixconsole server:reload                # apply it
```

`qbixctl ensite`, `dissite`, `enconf`, `disconf`, `enmod` and `dismod` are the same
commands. A file in an `-enabled` directory that is not a symlink is left alone.
The change takes effect on the next reload. See [console.md](console.md).

---

### Overlays and distributions

Other trees laid out the same way can be stacked on top of `/etc/qbix` as
overlays. The base is loaded first and each overlay after it, so an overlay holds
only what it changes, and the files in `/etc/qbix` keep working unchanged beneath
it. The console's enable and disable commands change the top tree of the stack.

A distribution of the engine that keeps its own tree registers it as an overlay.
The distribution is named by `--distribution=NAME`, then `QBIX_DISTRIBUTION`, then
a `DISTRIBUTION` file in the engine's source directory; the server loads the class
`Q_WebServer_Distribution_<Name>` from `src/Q/WebServer/Distribution/` and asks it
to register what it adds. With no distribution, `none`, or no such class, nothing
changes. An overlay may have its own environment variable that moves it, as
`QBIX_CONF_DIR` moves the base.

Only the base and its registered overlays stack. A directory that is neither is
used on its own, so a server pointed at a tree of its own does not also pick up
the machine's.

Designs and certificates are looked up the same way: the top overlay's
`designs/` and `ssl/` first, then the base's.

---

### Checking what is used

```sh
php qbixserver.php --layout --conf-dir=auto     # the files that would be loaded, as JSON, then exit
qbixconsole layout:show                          # the stack, and what is available and enabled
qbixconsole server:configtest                    # every file parses (qbixctl -t)
```

`layout:show` marks each enabled file with `*` and prints the stack in load order,
later winning. `server:configtest` prints `OK` or `BAD` for every file of every
tree and exits non-zero when one does not parse; a file that does not parse is
skipped by the server with a line on the console, never half-read.

---
[← Back to README](../README.md)
