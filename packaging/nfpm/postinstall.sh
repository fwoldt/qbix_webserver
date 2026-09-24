#!/usr/bin/env sh
# After install and upgrade: the service user, the default site enabled, and
# systemd told about the unit. Nothing is started: `systemctl enable --now
# qbix-webserver` does that when you are ready (docs/packages.md).
set -e
if ! getent passwd qbix >/dev/null 2>&1; then
  nologin=/usr/sbin/nologin; [ -x "$nologin" ] || nologin=/sbin/nologin
  if command -v useradd >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/qbix-webserver --no-create-home --shell "$nologin" --user-group qbix
  fi
fi
mkdir -p /var/lib/qbix-webserver
chown qbix:qbix /var/lib/qbix-webserver 2>/dev/null || true
chgrp qbix /etc/qbix/ssl 2>/dev/null || true
if [ ! -e /etc/qbix/sites-enabled/default.conf ] && [ ! -L /etc/qbix/sites-enabled/default.conf ]; then
  ln -s ../sites-available/default.conf /etc/qbix/sites-enabled/default.conf
fi
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload >/dev/null 2>&1 || true
fi
echo "qbix-webserver: start it with  systemctl enable --now qbix-webserver"
echo "qbix-webserver: check PHP's extensions with  qbixctl ext:check"
exit 0
