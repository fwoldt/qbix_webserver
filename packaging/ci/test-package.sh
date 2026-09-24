#!/usr/bin/env bash
# Installs a package in a clean container of its distribution and checks it:
# dependencies resolve, `qbixctl ext:check --variant=lite` finds everything
# the platform requires, and the server serves a page as the service user.
#
#   bash packaging/ci/test-package.sh <distro> <package file>
set -euo pipefail
cd "$(dirname "$0")/../.."
distro="${1:?distro}"; pkg="${2:?package file}"
case "$distro" in
  debian-12) image=debian:12 ;;           debian-13) image=debian:13 ;;
  ubuntu-22.04) image=ubuntu:22.04 ;;     ubuntu-24.04) image=ubuntu:24.04 ;;
  el-9) image=almalinux:9 ;;              el-10) image=almalinux:10 ;;
  *) echo "unknown distribution $distro" >&2; exit 2 ;;
esac
file="$(basename "$pkg")"
docker run --rm -v "$PWD/$(dirname "$pkg"):/pkgs:ro" "$image" sh -ec "
  case '$distro' in
    debian-*|ubuntu-*)
      export DEBIAN_FRONTEND=noninteractive
      for try in 1 2 3; do
        apt-get update -qq && apt-get install -y -qq curl /pkgs/'$file' >/dev/null 2>&1 && break
        echo \"apt failed, retry \$try of 3\"; sleep 10
      done
      dpkg -s qbix-webserver >/dev/null
      ;;
    el-*)
      # Mirrors fail now and then ('Cannot download, all mirrors'): retried.
      [ '$distro' = el-9 ] && dnf -y -q module enable php:8.2
      # util-linux for runuser: the minimal EL 10 image has neither su nor runuser.
      for try in 1 2 3; do
        dnf -y -q install util-linux /pkgs/'$file' && break
        echo \"dnf failed, retry \$try of 3\"; dnf clean all >/dev/null; sleep 10
      done
      rpm -q qbix-webserver >/dev/null
      ;;
  esac
  echo '== installed'
  qbixctl ext:check --variant=lite
  echo '== serving as the service user'
  cd /var/lib/qbix-webserver
  # runuser where there is one (util-linux), else su.
  serve='qbixserver --root=/usr/share/qbix-webserver/web --port=18080 >/tmp/qbix.log 2>&1 &'
  if command -v runuser >/dev/null 2>&1; then runuser -u qbix -- sh -c \"\$serve\"
  else su -s /bin/sh qbix -c \"\$serve\"; fi
  for i in 1 2 3 4 5 6 7 8 9 10; do
    code=\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/ || true)
    [ \"\$code\" = 200 ] && break; sleep 1
  done
  echo \"page: \$code\"; [ \"\$code\" = 200 ] || { cat /tmp/qbix.log; exit 1; }
  curl -s http://127.0.0.1:18080/Q/health | head -c 80; echo
  echo PASS
" 2>&1 | sed "s/^/[$distro] /"
