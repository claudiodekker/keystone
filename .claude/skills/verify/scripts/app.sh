#!/usr/bin/env bash
set -euo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
runs="$root/.verify/runs"
evidence="$root/.verify/evidence"
cd "$root"

usage() {
  cat >&2 <<'EOF'
usage: app.sh start [port]          build assets, create a run with its own database, serve it
       app.sh doctor <run>          is this run's server ours, up, and serving the sign-in page?
       app.sh second-factor <run>   give jane@example.com a TOTP authenticator and 8 recovery codes
       app.sh artisan <run> ...     run a testbench (artisan) command against the run's database
       app.sh mail <run>            send the run's queued security alerts and print the mails they write
       app.sh sql <run> "<query>"   query the run's SQLite database
       app.sh stop <run>            stop the run's server and delete its state, keeping its evidence
       app.sh list                  list runs and whether their servers are up
EOF
  exit 2
}

run_env() {
  local dir="$runs/$1"
  [ -d "$dir" ] || { echo "no run $1 under $runs" >&2; exit 1; }
  export DB_CONNECTION=sqlite DB_DATABASE="$dir/database.sqlite" CACHE_STORE=database APP_URL="$(cat "$dir/url")"
}

free_port() {
  local port=${1:-8100}
  while lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; do port=$((port + 1)); done
  echo "$port"
}

alive() {
  local pid
  pid="$(cat "$runs/$1/server.pid" 2>/dev/null)" || return 1
  kill -0 "$pid" 2>/dev/null
}

cmd="${1:-}"
[ -n "$cmd" ] || usage
shift

case "$cmd" in
  start)
    [ -d vendor ] && [ -d node_modules ] || { echo "run composer install and npm install first" >&2; exit 1; }
    run="$(date +%Y%m%d-%H%M%S)-$$"
    dir="$runs/$run"
    port="$(free_port "${1:-8100}")"
    mkdir -p "$dir" "$evidence/$run"
    echo "http://127.0.0.1:$port" > "$dir/url"
    touch "$dir/database.sqlite"
    run_env "$run"

    npm run build > "$dir/build.log" 2>&1 || { echo "asset build failed, see $dir/build.log" >&2; exit 1; }
    php vendor/bin/testbench migrate:fresh --seed --seeder='Workbench\Database\Seeders\DatabaseSeeder' --no-interaction > "$dir/migrate.log" 2>&1 \
      || { echo "migrate failed, see $dir/migrate.log" >&2; exit 1; }

    php vendor/bin/testbench serve --port="$port" --no-reload > "$dir/server.log" 2>&1 &
    echo $! > "$dir/server.pid"

    for _ in $(seq 1 40); do
      [ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/auth/login")" = 200 ] && break
      sleep 0.25
    done

    "$0" doctor "$run" >/dev/null || { echo "server did not come up, see $dir/server.log" >&2; "$0" stop "$run" >&2; exit 1; }
    echo "run=$run url=http://127.0.0.1:$port evidence=$evidence/$run"
    ;;

  doctor)
    run="${1:?run id}"
    run_env "$run"
    url="$APP_URL"
    port="${url##*:}"
    pid="$(cat "$runs/$run/server.pid")"
    ok=1
    alive "$run" && echo "ok   server process $pid is running" || { echo "FAIL server process $pid is not running"; ok=0; }
    listener="$(lsof -nP -tiTCP:"$port" -sTCP:LISTEN 2>/dev/null | head -1 || true)"
    if [ -n "$listener" ] && [ "$(ps -o ppid= -p "$listener" | tr -d ' ')" = "$pid" ]; then
      echo "ok   port $port is served by our php -S ($listener)"
    else
      echo "FAIL port $port is not served by a child of $pid (listener: ${listener:-none})"; ok=0
    fi
    body="$(curl -s "$url/auth/login" || true)"
    case "$body" in
      *'"component":"auth\/Login"'*) echo "ok   GET /auth/login renders auth/Login" ;;
      *) echo "FAIL GET /auth/login does not render auth/Login"; ok=0 ;;
    esac
    manifest="vendor/orchestra/testbench-core/laravel/public/build/manifest.json"
    if [ -f "$manifest" ] && ! [ workbench/public/build/manifest.json -nt "$manifest" ]; then
      echo "ok   served assets match the latest build"
    else
      echo "FAIL served assets are older than workbench/public/build; restart the run"; ok=0
    fi
    accounts="$(sqlite3 "$DB_DATABASE" "select count(*) from user_emails where address = 'jane@example.com'" 2>/dev/null || echo 0)"
    [ "$accounts" = 1 ] && echo "ok   jane@example.com is seeded in $DB_DATABASE" || { echo "FAIL jane@example.com is missing"; ok=0; }
    [ "$ok" = 1 ]
    ;;

  second-factor)
    run="${1:?run id}"
    run_env "$run"
    php vendor/bin/testbench tinker --execute "require '$root/.claude/skills/verify/scripts/second-factor.php';" \
      | grep '^{' > "$runs/$run/second-factor.json"
    cat "$runs/$run/second-factor.json"
    ;;

  artisan)
    run="${1:?run id}"
    shift
    run_env "$run"
    php vendor/bin/testbench "$@"
    ;;

  mail)
    run="${1:?run id}"
    run_env "$run"
    log="vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log"
    touch "$log"
    offset="$(wc -c < "$log" | tr -d ' ')"
    php vendor/bin/testbench queue:work --stop-when-empty --no-interaction > "$runs/$run/queue.log" 2>&1
    tail -c +"$((offset + 1))" "$log" | grep -v 'keystone.security_event' | tee -a "$evidence/$run/mail.log" || true
    ;;

  sql)
    run="${1:?run id}"
    run_env "$run"
    sqlite3 -header -column "$DB_DATABASE" "${2:?query}"
    ;;

  stop)
    run="${1:?run id}"
    dir="$runs/$run"
    [ -d "$dir" ] || { echo "no run $run" >&2; exit 1; }
    if alive "$run"; then
      pid="$(cat "$dir/server.pid")"
      pkill -TERM -P "$pid" 2>/dev/null || true
      kill -TERM "$pid" 2>/dev/null || true
    fi
    cp "$dir/server.log" "$evidence/$run/server.log" 2>/dev/null || true
    rm -rf "$dir"
    echo "stopped $run; evidence kept in $evidence/$run"
    ;;

  list)
    for dir in "$runs"/*/; do
      [ -d "$dir" ] || continue
      run="$(basename "$dir")"
      alive "$run" && state=up || state=down
      echo "$run $state $(cat "$dir/url")"
    done
    ;;

  *) usage ;;
esac
