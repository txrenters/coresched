#!/usr/bin/env bash
# Zero-downtime deployment for the containerised tx-bookings.
#
# Docker Compose has no rolling update of its own: `up -d` stops a container
# before starting its replacement, which is a visible outage. This script does
# what Swarm/Kubernetes would -- start replacements alongside the old
# containers, wait for them to report healthy, and only then retire the old
# ones. nginx picks up the change from Docker's DNS, so traffic moves over
# without a gap.
#
# Rolled this way:   app       (user-facing)
# Plainly recreated: web, queue, scheduler
#   Two schedulers running at once would double-fire scheduled commands, and
#   bookings:send-reminders runs every minute -- invitees would get the
#   same reminder twice. A few seconds without a background worker is not
#   user-visible.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.yml"

# Set BACKGROUND_SERVICES= (empty) for a web-only rollout.
#
# Read into a separate name: this script re-execs itself after pulling, and
# assigning an array over the exported string would pass something mangled
# through that exec.
read -ra _BACKGROUND_SERVICES <<< "${BACKGROUND_SERVICES-queue scheduler}"

# Only `app` can be rolled by scaling. `web` publishes a fixed host port, and a
# second replica cannot bind a port the first one already holds -- Docker fails
# with "port is already allocated". Instead there are two web services on
# separate ports (8084 and 8085), both in the VPS nginx's upstream, recreated
# one after the other: nginx fails over to whichever is up, so the recreate
# costs no 502s. Order matters -- see the recreate loop below.
ROLLING_SERVICES=(app)
RECREATE_SERVICES=(web web-standby)
HEALTH_TIMEOUT=120

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# How many replicas of a service are ready right now.
#
# Counts rather than tracking specific container ids. Compose removes and
# renames containers during scaling, so an id captured a moment ago may already
# be gone -- `docker inspect` then fails with "no such object" and, under
# `set -e`, kills the deploy mid-roll. A count cannot go stale.
ready_count() {
    local service=$1 id state n=0

    for id in $($COMPOSE ps -q "$service" 2>/dev/null); do
        state=$(docker inspect -f \
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
            "$id" 2>/dev/null) || continue
        [[ "$state" == "healthy" || "$state" == "running" ]] && n=$((n + 1))
    done

    echo "$n"
}

# Wait until a service has at least $2 ready replicas.
wait_for_ready() {
    local service=$1 want=$2
    local deadline=$((SECONDS + HEALTH_TIMEOUT))

    while (( SECONDS < deadline )); do
        (( $(ready_count "$service") >= want )) && return 0
        sleep 2
    done

    echo "Timed out after ${HEALTH_TIMEOUT}s waiting for $want ready $service replica(s)." >&2
    return 1
}

log "Pulling latest changes"

# SKIP_PULL=1 deploys the checkout already in place, which is what a CI job
# that has staged the target commit itself wants -- and what an rsync-based
# deploy from a workstation needs, since the server holds no GitHub credential
# and an unguarded pull would fail and, under `set -e`, abort the deploy.
#
# Nothing above this point may touch git, for the same reason. Asking it even
# for the branch name aborted the deploy when run by hand as root: the files
# are rsynced in as the runner's user, so git refuses the directory as
# "dubious ownership" for anyone else -- a credential-free deploy failing on
# git is exactly what SKIP_PULL exists to avoid.
#
# The re-exec below is skipped with it: its only purpose is to pick up a newer
# copy of this script from the pull that did not happen.
if [[ "${SKIP_PULL:-0}" == "1" ]]; then
    echo "SKIP_PULL=1: deploying the checkout already in place"
else
    # Deploys whichever branch this checkout is on, rather than assuming main.
    BRANCH=$(git rev-parse --abbrev-ref HEAD)
    echo "branch: $BRANCH"

    git pull --ff-only origin "$BRANCH"

    # This script deploys itself, so the running process may be an older version
    # than the one just fetched. Configuration above the pull was already read into
    # memory from the old file, so a fix to this script would silently not take
    # effect until the deploy after next. Re-exec once so a deploy always runs the
    # code it just pulled.
    if [[ "${DEPLOY_REEXECED:-0}" != "1" ]]; then
        export DEPLOY_REEXECED=1
        log "Re-executing with the freshly pulled script"
        exec "$0" "$@"
    fi
fi

log "Building images"
$COMPOSE build

# Migrations run once, before the new code is serving, and must be
# backward-compatible so the still-running old containers keep working
# (expand/contract).
log "Running migrations"
$COMPOSE run --rm migrate

for service in "${ROLLING_SERVICES[@]}"; do
    log "Rolling $service"

    # Converge to a single replica first. A deploy that failed part way through
    # leaves extra containers behind; without this, the doubling below computes
    # from the wrong base and the sprawl compounds with every failed run.
    $COMPOSE up -d --no-deps --no-recreate --scale "$service=1" "$service"

    old_ids=$($COMPOSE ps -q "$service" || true)

    if [[ -z "$old_ids" ]]; then
        log "$service is not running yet; starting it"
        $COMPOSE up -d --no-deps "$service"
        wait_for_ready "$service" 1
        continue
    fi

    # --no-recreate leaves the existing container alone, so scaling to two adds
    # one built from the new image while the old one keeps serving.
    $COMPOSE up -d --no-deps --no-recreate --scale "$service=2" "$service"

    echo "Waiting for the replacement to become healthy..."
    wait_for_ready "$service" 2

    # Wait out nginx's DNS cache before killing the old container. The
    # container nginx resolves the app upstream with `valid=2s`, so for up to
    # that long it can still be routing to an address about to disappear.
    echo "Draining (waiting out the upstream DNS TTL)..."
    sleep 5

    echo "Retiring the old container..."
    # Tolerant of already-gone ids: compose may have reaped one during scaling,
    # and a failure here must not abort a deploy that has already succeeded.
    # shellcheck disable=SC2086
    docker stop $old_ids >/dev/null 2>&1 || true
    # shellcheck disable=SC2086
    docker rm $old_ids >/dev/null 2>&1 || true

    $COMPOSE up -d --no-deps --no-recreate --scale "$service=1" "$service"
    echo "$service: $(ready_count "$service") ready"
done

for service in "${RECREATE_SERVICES[@]}"; do
    log "Recreating $service"
    # Done immediately after the app roll: until this completes, the new app
    # renders HTML referencing asset hashes only the new web image contains.
    $COMPOSE up -d --no-deps --force-recreate "$service"

    # Must be healthy before the next one goes down, or both web services are
    # out at once and nginx has nowhere to fail over to. Fails the deploy on
    # timeout rather than carrying on regardless.
    wait_for_ready "$service" 1
    echo "$service: $(ready_count "$service") ready"
done

# The queue worker drains its in-flight job before exiting, bounded by
# stop_grace_period (120s) in docker-compose.yml. Compose prints nothing while
# it waits, so a normal drain and a wedged container look identical.
if (( ${#_BACKGROUND_SERVICES[@]} )); then
    log "Recreating background services"
    echo "(the queue worker drains its current job first; up to 120s)"
    $COMPOSE up -d --no-deps --force-recreate "${_BACKGROUND_SERVICES[@]}"
fi

log "Deployed"
$COMPOSE ps
