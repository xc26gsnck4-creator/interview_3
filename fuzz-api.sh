#!/usr/bin/env bash
#
# Fuzzes the API with Schemathesis against the committed OpenAPI document
# (api.json), with the settings in schemathesis.toml. Run it on the host with
# Sail up (it needs Docker, so it cannot run inside Sail):
#
#   ./fuzz-api.sh [seed]
#
# Why a second server: the app Sail serves on port 80 runs with APP_DEBUG=true
# on database/database.sqlite. Its debug error bodies break the document's
# closed response schemas, and the fuzzer must not write to that database. So
# the script migrates a throwaway SQLite file in the app container's /tmp and
# starts a second `artisan serve` on 127.0.0.1:8001 in its own process group,
# with APP_DEBUG=false and logs on stderr. The pinned Schemathesis image runs in
# the app container's network namespace, so port 8001 is never published.
#
# Two runs, both always: the config's fixed seed, whose report must show at
# least one send 200 and one webhook 204 (the coverage guard: without them the
# send endpoint and the webhook were only ever fuzzed into a 404), then a random
# seed. The optional argument, a non-negative integer, replaces only that random
# seed, to replay a run: the fixed run and its guard still go first, so the
# replayed run follows the same fixed run on a fresh database, as the original
# did. The summary prints the replay command. The NDJSON reports and the
# server's log stay in a temporary directory whose path is printed. On every
# exit, Ctrl+C included, the server's process group is killed and its files are
# deleted.
#
# Exit codes: 0 both runs passed and the guard held; 1 a run failed (a check
# failed or a case errored) or the guard failed; 2 the argument is not a single
# non-negative integer, the setup failed (app container down, port 8001 taken,
# cached configuration, server not starting), Schemathesis could not run, the
# guard could not read the report, or the server could not be stopped (when
# nothing else failed); 128 + the signal number when interrupted (130 for
# Ctrl+C).

set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

readonly image='schemathesis/schemathesis:4.32.5'
# The port schemathesis.toml's base-url points at.
readonly port=8001

run_id="$(date +%Y%m%d%H%M%S)-$$"
readonly run_id
# The per-run SQLite file, process group id, log and lock in the app container
# are named "$container_files".{sqlite,pid,log,lock}.
readonly container_files="/tmp/fuzz-api-${run_id}"

say() {
    printf 'fuzz-api: %s\n' "$*"
}

fail_setup() {
    printf 'fuzz-api: %s\n' "$*" >&2
    exit 2
}

usage="usage: ./fuzz-api.sh [seed], where seed is a non-negative integer that replaces the random run's drawn seed (to replay that run)"
given_seed=''
if (($# > 1)); then
    fail_setup "$usage"
elif (($# == 1)); then
    if [[ ! $1 =~ ^[0-9]+$ ]]; then
        fail_setup "$usage"
    fi
    given_seed=$1
fi

if [[ -f bootstrap/cache/config.php ]]; then
    fail_setup "the configuration is cached, so the second server would ignore its APP_DEBUG and DB_DATABASE and write to database/database.sqlite. Clear it first: vendor/bin/sail artisan config:clear"
fi

app_container="$(docker compose ps -q app 2>/dev/null || true)"
if [[ -z "$app_container" ]]; then
    fail_setup "the app container is not running. Start Sail first: vendor/bin/sail up -d"
fi

fixed_seed=''
while IFS= read -r line; do
    if [[ $line =~ ^seed[[:space:]]*=[[:space:]]*([0-9]+) ]]; then
        fixed_seed="${BASH_REMATCH[1]}"
        break
    fi
done <schemathesis.toml
if [[ -z "$fixed_seed" ]]; then
    fail_setup "schemathesis.toml sets no seed for the fixed run"
fi

if [[ -n "$given_seed" ]]; then
    random_seed="$given_seed"
    random_run="seed $random_seed from the command line"
else
    random_seed="$(((RANDOM << 15) | RANDOM))"
    while [[ "$random_seed" == "$fixed_seed" ]]; do
        random_seed="$(((RANDOM << 15) | RANDOM))"
    done
    random_run="random seed $random_seed"
fi

tmp_root="${TMPDIR:-/tmp}"
reports="$(mktemp -d "${tmp_root%/}/fuzz-api.XXXXXX")"
readonly reports

server_requested=false
fixed_result='not run'
random_result='not run'
guard_result='not checked'
guard_hint=''

# Stops the server and deletes its files inside the app container. It takes the
# start's lock first: a Ctrl+C on the host does not stop a command already
# running in the container, so a start still in progress finishes before the
# stop reads the process group id. Prints the server's log on stdout.
stop_server() {
    vendor/bin/sail bash -s -- "$container_files" <<'CONTAINER'
set -uo pipefail
files=${1:?}
status=0
exec 9>"$files.lock"
if ! flock -w 60 9; then
    echo "fuzz-api: the server start did not finish within 60 s; stopping what it left" >&2
fi
pgid=''
if [[ -s "$files.pid" ]]; then
    pgid=$(<"$files.pid")
fi
if [[ $pgid =~ ^[1-9][0-9]*$ ]]; then
    kill -TERM -- "-$pgid" 2>/dev/null
    for _ in $(seq 50); do
        kill -0 -- "-$pgid" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 -- "-$pgid" 2>/dev/null; then
        kill -KILL -- "-$pgid" 2>/dev/null
        sleep 0.5
    fi
    if kill -0 -- "-$pgid" 2>/dev/null; then
        echo "fuzz-api: process group $pgid in the app container is still running" >&2
        status=1
    fi
fi
if [[ -f "$files.log" ]]; then
    cat -- "$files.log"
fi
rm -f -- "$files".*
exit "$status"
CONTAINER
}

print_summary() {
    say "fixed seed $fixed_seed: $fixed_result; coverage guard: $guard_result"
    if [[ -n "$guard_hint" ]]; then
        say "$guard_hint"
    fi
    say "$random_run: $random_result"
    if [[ "$random_result" != 'not run' ]]; then
        say "to replay that run: ./fuzz-api.sh $random_seed"
    fi
    say "reports and the server log: $reports"
}

cleanup() {
    local status=$?
    trap '' INT TERM HUP
    set +e
    docker rm -f "fuzz-api-${run_id}-fixed-seed" "fuzz-api-${run_id}-random-seed" >/dev/null 2>&1
    if [[ "$server_requested" == true ]]; then
        say "cleaning up the app container: stopping the server, deleting the per-run files"
        # A failed redirection would skip the stop, so the log is dropped when
        # the reports directory is gone.
        local server_log="$reports/server.log"
        if ! touch "$server_log" 2>/dev/null; then
            server_log=/dev/null
        fi
        if ! stop_server >"$server_log"; then
            printf 'fuzz-api: %s\n' "could not stop the server cleanly; see the messages above" >&2
            if ((status == 0)); then
                status=2
            fi
        fi
    fi
    print_summary
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

# Takes the lock, checks the port, migrates the throwaway database, starts the
# server in a new session (its process group id lands in the .pid file) and
# waits until it accepts connections. Exit 3: the port is taken; 4: no answer
# within 20 s; any other: the migration failed or the server exited at start.
start_server() {
    vendor/bin/sail bash -s -- "$container_files" "$port" <<'CONTAINER'
set -euo pipefail
files=${1:?}
port=$2
exec 9>"$files.lock"
flock 9
if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
    exit 3
fi
touch "$files.sqlite"
if ! output=$(DB_DATABASE="$files.sqlite" php artisan migrate --force --no-interaction 2>&1); then
    printf '%s\n' "$output" >&2
    exit 1
fi
# --no-reload keeps the environment set here: without it,
# ServeCommand::startProcess() sets every $_ENV key outside its
# $passthroughVariables to false (unset) for the php -S workers, and with
# Sail's php.ini (variables_order = EGPCS) $_ENV holds APP_DEBUG, DB_DATABASE
# and LOG_CHANNEL, so the workers would read .env again: debug on, and writes
# to database/database.sqlite.
APP_DEBUG=false DB_DATABASE="$files.sqlite" LOG_CHANNEL=stderr \
    setsid -f sh -c 'echo "$$" >"$1"; shift; exec "$@"' sh "$files.pid" \
    php artisan serve --host=127.0.0.1 --port="$port" --no-reload \
    </dev/null >"$files.log" 2>&1 9>&-
for _ in $(seq 200); do
    if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
        exit 0
    fi
    # The server's process group is gone: it exited at start, so waiting longer
    # could only end in an answer from another server.
    if [[ -s "$files.pid" ]] && ! kill -0 -- -"$(<"$files.pid")" 2>/dev/null; then
        exit 1
    fi
    sleep 0.1
done
exit 4
CONTAINER
}

say "reports and the server log go to $reports"
say "starting the debug-off server on 127.0.0.1:$port in the app container (database $container_files.sqlite)"
server_requested=true
start_status=0
start_server || start_status=$?
case "$start_status" in
    0) ;;
    3) fail_setup "port $port is taken inside the app container; stop what listens there (a previous run's server?) and retry" ;;
    4) fail_setup "the server did not answer on port $port within 20 s; its log is $reports/server.log" ;;
    *) fail_setup "could not migrate the throwaway database or start the server (exit $start_status)" ;;
esac

# Runs Schemathesis with the config; $1 names the run and its report, the rest
# goes after `run`. Prints nothing itself; returns Schemathesis's exit code.
# --init: Schemathesis is not PID 1, so a SIGTERM or SIGHUP the Docker client
# passes on stops it (PID 1 without a handler ignores them) and the cleanup
# need not wait for the run to end.
fuzz() {
    local name=$1
    shift
    docker run --rm --init --name "fuzz-api-${run_id}-${name}" \
        --network "container:${app_container}" \
        -v "$PWD/api.json:/schema/api.json:ro" \
        -v "$PWD/schemathesis.toml:/config/schemathesis.toml:ro" \
        -v "$reports:/reports" \
        "$image" --config-file /config/schemathesis.toml run /schema/api.json \
        --report ndjson --report-ndjson-path "/reports/${name}.ndjson" \
        --coverage-report-html-path "/reports/${name}-coverage.html" "$@"
}

# Prints "<send 200 count> <webhook 204 count>" for the NDJSON report $1. Every
# request the run sent is recorded once, under the ScenarioFinished event of its
# scenario, keyed by test case id. It only reads the report, so it gets no
# network.
count_guard_responses() {
    docker run --rm -i --init --network none --entrypoint python -v "$reports:/reports:ro" "$image" -I - "/reports/$1" <<'PYTHON'
import json
import sys
from urllib.parse import urlsplit

send_ok = 0
webhook_ok = 0
with open(sys.argv[1], encoding="utf-8") as report:
    for line in report:
        if not line.strip():
            continue
        scenario = json.loads(line).get("ScenarioFinished")
        if scenario is None:
            continue
        for interaction in scenario["recorder"].get("interactions", {}).values():
            request = interaction["request"]
            response = interaction.get("response")
            if response is None:
                continue
            segments = urlsplit(request["uri"]).path.strip("/").split("/")
            method = request["method"]
            status = response["status_code"]
            if method == "POST" and status == 200 and len(segments) == 4 and segments[:2] == ["api", "invoices"] and segments[3] == "send":
                send_ok += 1
            if method == "GET" and status == 204 and len(segments) == 5 and segments[:4] == ["api", "notification", "hook", "delivered"]:
                webhook_ok += 1
print(send_ok, webhook_ok)
PYTHON
}

# Schemathesis exits 0 when every check passed, 1 when a check failed or a case
# errored and 2 when it could not run; anything else (a Docker error) counts as
# could not run.
describe_run() {
    case "$1" in
        0) echo 'passed (exit 0)' ;;
        1) echo 'FAILED, a check failed or a case errored (exit 1)' ;;
        *) echo "FAILED, Schemathesis could not run (exit $1)" ;;
    esac
}

say "run 1 of 2: fixed seed $fixed_seed from schemathesis.toml"
fixed_result='interrupted'
fixed_status=0
fuzz fixed-seed || fixed_status=$?
fixed_result="$(describe_run "$fixed_status")"

guard_status=0
counts=''
if [[ ! -s "$reports/fixed-seed.ndjson" ]]; then
    guard_status=1
    guard_result='FAILED, the fixed run wrote no report'
elif ! counts="$(count_guard_responses fixed-seed.ndjson)" || [[ ! $counts =~ ^([0-9]+)\ ([0-9]+)$ ]]; then
    guard_status=2
    guard_result='FAILED, the report could not be read'
else
    send_ok="${BASH_REMATCH[1]}"
    webhook_ok="${BASH_REMATCH[2]}"
    if ((send_ok > 0 && webhook_ok > 0)); then
        guard_result="passed (send 200 x$send_ok, webhook 204 x$webhook_ok)"
    else
        guard_status=1
        guard_result="FAILED (send 200 x$send_ok, webhook 204 x$webhook_ok; each must be at least 1)"
        guard_hint="likely cause: the links in api.json no longer lead to a send or the webhook (fix them), or api.json changed and seed $fixed_seed no longer reaches them (pick a new seed in schemathesis.toml: try 1, 3, 6, 8, then upwards, and keep the first clean run that passes the guard)"
    fi
fi
say "coverage guard: $guard_result"
if [[ -n "$guard_hint" ]]; then
    say "$guard_hint"
fi

say "run 2 of 2: $random_run"
random_result='interrupted'
random_status=0
fuzz random-seed --seed "$random_seed" || random_status=$?
random_result="$(describe_run "$random_status")"

status="$guard_status"
for run_status in "$fixed_status" "$random_status"; do
    if ((run_status > 1)); then
        run_status=2
    fi
    if ((run_status > status)); then
        status="$run_status"
    fi
done
exit "$status"
