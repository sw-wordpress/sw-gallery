#!/usr/bin/env bash
# Private, throw-away MariaDB instance for the WordPress integration tests. Never touches other databases.
#   bin/test-db.sh start    new empty instance on 127.0.0.1 and a random free port (kept in $DIR/port)
#   bin/test-db.sh stop     stop it and delete the data directory
#   bin/test-db.sh status   running? (exit code 0/1)
#   bin/test-db.sh env      print the SWG_TEST_DB_* settings (eval "$(bin/test-db.sh env)")
# Data directory: $SWG_TEST_DB_DIR (default /tmp/swg-test-db). tests/wp reads the same settings (env first, then $DIR/port).
set -euo pipefail

DIR="${SWG_TEST_DB_DIR:-/tmp/swg-test-db}"
MARKER="$DIR/.swg-test-db"
SOCKET="$DIR/mysql.sock"
USER_NAME="swg"
PASSWORD="swg"
DB_NAME="swg_wp_tests"

running() {
  [ -S "$SOCKET" ] && mariadb-admin --no-defaults --socket="$SOCKET" ping >/dev/null 2>&1
}

free_port() {
  local port
  for _ in $(seq 1 50); do
    port=$((20000 + RANDOM % 40000))
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
      echo "$port"
      return 0
    fi
  done
  echo "no free port found" >&2
  return 1
}

# Only ever delete a directory this script created.
reset_dir() {
  if [ -e "$DIR" ] && [ ! -f "$MARKER" ] && [ -n "$(ls -A "$DIR" 2>/dev/null)" ]; then
    echo "refusing to use non-empty $DIR that is not a test database directory" >&2
    exit 1
  fi
  rm -rf "$DIR"
  mkdir -p "$DIR/data"
  touch "$MARKER"
}

case "${1:-}" in
  start)
    if running; then echo "already running on 127.0.0.1:$(cat "$DIR/port")"; exit 0; fi
    reset_dir
    PORT="${SWG_TEST_DB_PORT:-$(free_port)}"
    echo "$PORT" > "$DIR/port"
    mariadb-install-db --no-defaults --datadir="$DIR/data" --auth-root-authentication-method=normal >/dev/null
    nohup mariadbd --no-defaults --datadir="$DIR/data" --socket="$SOCKET" --port="$PORT" --bind-address=127.0.0.1 \
      --pid-file="$DIR/mysql.pid" --skip-name-resolve --innodb-flush-log-at-trx-commit=0 \
      --character-set-server=utf8mb4 >"$DIR/mysqld.log" 2>&1 &
    for _ in $(seq 1 120); do
      running && break
      sleep 0.5
    done
    running || { echo "MariaDB did not start, see $DIR/mysqld.log" >&2; exit 1; }
    mariadb --no-defaults --socket="$SOCKET" -uroot -e "
      CREATE USER '$USER_NAME'@'%' IDENTIFIED BY '$PASSWORD';
      CREATE USER '$USER_NAME'@'localhost' IDENTIFIED BY '$PASSWORD';
      GRANT ALL ON \`swg\\_%\`.* TO '$USER_NAME'@'%';
      GRANT ALL ON \`swg\\_%\`.* TO '$USER_NAME'@'localhost';
      CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4;
      FLUSH PRIVILEGES;"
    echo "started on 127.0.0.1:$PORT (user $USER_NAME, database $DB_NAME)"
    ;;
  stop)
    if [ -f "$DIR/mysql.pid" ]; then
      pid="$(cat "$DIR/mysql.pid")"
      kill "$pid" 2>/dev/null || true
      for _ in $(seq 1 60); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
    fi
    if [ -f "$MARKER" ]; then rm -rf "$DIR"; fi
    echo stopped
    ;;
  status)
    if running; then echo "running on 127.0.0.1:$(cat "$DIR/port")"; else echo "not running"; exit 1; fi
    ;;
  env)
    [ -f "$DIR/port" ] || { echo "not started" >&2; exit 1; }
    echo "export SWG_TEST_DB_HOST=127.0.0.1 SWG_TEST_DB_PORT=$(cat "$DIR/port") SWG_TEST_DB_USER=$USER_NAME SWG_TEST_DB_PASSWORD=$PASSWORD"
    ;;
  *)
    echo "usage: $0 start|stop|status|env" >&2
    exit 1
    ;;
esac
