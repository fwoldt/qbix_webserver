#!/usr/bin/env sh
# Before removal: stop the service. Configuration in /etc/qbix, the state in
# /var/lib/qbix-webserver and the qbix user are left for the administrator.
set -e
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
  systemctl stop qbix-webserver >/dev/null 2>&1 || true
  systemctl disable qbix-webserver >/dev/null 2>&1 || true
fi
exit 0
