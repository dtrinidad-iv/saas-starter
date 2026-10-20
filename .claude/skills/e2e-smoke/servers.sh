#!/usr/bin/env bash
# Runs the API and the built web app on spare ports for end-to-end checks.
# Never uses :8000/:3000 (the developer's own servers, or other projects).
#
# Usage: servers.sh start | stop | status | cleanup
#   start    build web (skip with SKIP_BUILD=1), start API :8011 and web :3011, wait until both answer
#   stop     stop both
#   cleanup  delete e2e-*@example.com users, their tokens and organizations only they belong to
set -uo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
state="${TMPDIR:-/tmp}/saas-starter-e2e"
API_PORT=8011
WEB_PORT=3011
mkdir -p "$state"

find_php() {
  local candidate
  for candidate in php /opt/homebrew/opt/php/bin/php /usr/local/opt/php/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
      echo "$candidate"; return 0
    fi
  done
  echo "PHP 8.4+ not found" >&2; return 1
}

use_node() {
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$NVM_DIR/nvm.sh"
    cd "$root/web" && nvm use --silent >/dev/null
  fi
}

port_busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

wait_for() {
  local url="$1" i
  for i in $(seq 1 60); do
    curl -s -o /dev/null "$url" && return 0
    sleep 0.5
  done
  echo "Timed out waiting for $url" >&2; return 1
}

# Stops whatever php/node process listens on our ports ($! points at wrappers, not the servers).
# The ( ... & ) wrappers get /dev/null as stdio so a caller piping our output (| tail) isn't held open.
stop() {
  local port pid
  for port in $API_PORT $WEB_PORT; do
    for pid in $(lsof -nP -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null); do
      case "$(ps -o comm= -p "$pid")" in
        *php*|*node*) kill "$pid" && echo "stopped :$port ($pid)" ;;
        *) echo "left :$port alone: pid $pid is not php/node" >&2 ;;
      esac
    done
  done
}

start() {
  local php
  php="$(find_php)" || exit 1
  for port in $API_PORT $WEB_PORT; do
    if port_busy "$port"; then
      echo "Port $port is in use. Run '$0 stop' or check what is listening (lsof -iTCP:$port)." >&2
      exit 1
    fi
  done

  use_node
  if [ "${SKIP_BUILD:-0}" != 1 ]; then
    echo "building web..."
    (cd "$root/web" && npm run build --silent >"$state/build.log" 2>&1) || { echo "web build failed, see $state/build.log" >&2; exit 1; }
  fi

  (cd "$root/api/public" && FRONTEND_URL="http://localhost:$WEB_PORT" nohup "$php" -S "127.0.0.1:$API_PORT" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >"$state/api.log" 2>&1 &) </dev/null >/dev/null 2>&1
  (cd "$root/web" && PORT=$WEB_PORT NUXT_PUBLIC_API_BASE="http://localhost:$API_PORT" nohup node .output/server/index.mjs \
    >"$state/web.log" 2>&1 &) </dev/null >/dev/null 2>&1

  wait_for "http://127.0.0.1:$API_PORT/up" && wait_for "http://127.0.0.1:$WEB_PORT/login" || { stop; exit 1; }
  echo "API  http://localhost:$API_PORT   (log: $state/api.log)"
  echo "Web  http://localhost:$WEB_PORT   (log: $state/web.log)"
}

cleanup() {
  local php
  php="$(find_php)" || exit 1
  cd "$root/api" && "$php" artisan tinker --execute='
    $users = App\Models\User::where("email", "like", "e2e-%@example.com")->get();
    $ids = $users->pluck("id");
    $orgIds = DB::table("organization_user")->whereIn("user_id", $ids)->pluck("organization_id")->unique();
    $shared = DB::table("organization_user")->whereIn("organization_id", $orgIds)->whereNotIn("user_id", $ids)->pluck("organization_id");
    $users->each(fn ($user) => $user->tokens()->delete());
    App\Models\User::whereIn("id", $ids)->delete();
    $orgs = App\Models\Organization::whereIn("id", $orgIds->diff($shared))->delete();
    echo "deleted {$ids->count()} e2e users and {$orgs} organizations\n";
  '
}

case "${1:-}" in
  start) start ;;
  stop) stop ;;
  status) for p in $API_PORT $WEB_PORT; do port_busy "$p" && echo ":$p up" || echo ":$p down"; done ;;
  cleanup) cleanup ;;
  *) echo "Usage: $0 start|stop|status|cleanup" >&2; exit 1 ;;
esac
