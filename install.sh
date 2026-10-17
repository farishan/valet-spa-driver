#!/usr/bin/env bash
#
# Installs SpaValetDriver into a Valet Linux installation.
#
#   ./install.sh                 install the driver
#   ./install.sh --mime-types    also install the .webmanifest MIME mapping
#   ./install.sh --uninstall     remove both
#
# Options:
#   --valet-home DIR   Valet home directory (default: $VALET_HOME or ~/.valet)
#   --prefix PATH      path to a valet-linux checkout, used to locate the
#                      bundled driver classes when running the test suite

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VALET_HOME_DIR="${VALET_HOME:-$HOME/.valet}"
DO_MIME_TYPES=0
UNINSTALL=0

while [ $# -gt 0 ]; do
    case "$1" in
        --mime-types) DO_MIME_TYPES=1; shift ;;
        --uninstall)  UNINSTALL=1; shift ;;
        --valet-home) VALET_HOME_DIR="$2"; shift 2 ;;
        --prefix)     export VALET_PATH="$2"; shift 2 ;;
        -h|--help)    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)            echo "unknown option: $1" >&2; exit 1 ;;
    esac
done

DRIVERS_DIR="$VALET_HOME_DIR/Drivers"
NGINX_DIR="$VALET_HOME_DIR/Nginx"
MIME_FILE="$NGINX_DIR/zz-webmanifest-mime-types.conf"

remove() {
    local target="$1" label="$2"
    if [ -e "$target" ] || [ -L "$target" ]; then
        rm -f "$target"
        echo "removed  $label"
    else
        echo "absent   $label"
    fi
}

if [ "$UNINSTALL" -eq 1 ]; then
    echo "Uninstalling from $VALET_HOME_DIR"
    remove "$DRIVERS_DIR/SpaValetDriver.php" "driver"
    remove "$MIME_FILE" "MIME types"
    echo
    echo "Done. Reload nginx for the MIME change to take effect:"
    echo "  sudo nginx -s reload"
    exit 0
fi

if [ ! -d "$VALET_HOME_DIR" ]; then
    echo "error: $VALET_HOME_DIR does not exist -- is Valet Linux installed?" >&2
    exit 1
fi

mkdir -p "$DRIVERS_DIR"
install -m 644 "$REPO_ROOT/src/SpaValetDriver.php" "$DRIVERS_DIR/SpaValetDriver.php"
echo "installed  $DRIVERS_DIR/SpaValetDriver.php"

if [ "$DO_MIME_TYPES" -eq 1 ]; then
    mkdir -p "$NGINX_DIR"
    # Included at http{} level by nginx.conf. A types block at the same level
    # merges with `include mime.types`; moving it into a server{} block would
    # REPLACE the inherited set and strip MIME types from every site.
    cat > "$MIME_FILE" <<'CONF'
# Added by valet-spa-driver's install.sh.
#
# nginx has no built-in mapping for .webmanifest, so PWA manifests are served
# as application/octet-stream and browsers may refuse to install the app.
#
# Keep this at http{} level: a types directive in a server{} block replaces the
# inherited set rather than merging with it.
types {
    application/manifest+json webmanifest;
}
CONF
    echo "installed  $MIME_FILE"
fi

echo
echo "Verifying:"
if php "$REPO_ROOT/tests/run.php" > /tmp/valet-spa-driver-test.log 2>&1; then
    tail -1 /tmp/valet-spa-driver-test.log | sed 's/^/  /'
else
    echo "  TESTS FAILED -- see /tmp/valet-spa-driver-test.log" >&2
    tail -20 /tmp/valet-spa-driver-test.log | sed 's/^/  /' >&2
    exit 1
fi

cat <<'NEXT'

Next:
  1. Build your app, then link the output directory from its location:
       cd path/to/dist && valet link my-app
     Link the build output, not the source directory.

  2. If you used --mime-types, reload nginx:
       sudo nginx -s reload

  3. Confirm the root is not 404ing:
       curl -sI https://my-app.test | head -1

If the root 404s while /some/route works, the installed file is an older copy:
  re-run ./install.sh
NEXT