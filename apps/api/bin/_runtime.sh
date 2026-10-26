#!/usr/bin/env bash
# Shared setup for the two runtime proofs. Sourced, never run.
#
# ONE SOURCE, BECAUSE THE PRECONDITION IS THE THING THAT WAS WRONG. Both proofs need the
# same four steps in the same order, and when each carried its own copy one of them had
# no bootstrap at all and the other had it in the wrong place. A precondition duplicated
# is a precondition that drifts.
#
# Each proof calls runtime_start() itself. Neither may depend on the other having run:
# they are separate CI steps, they are run individually during development, and a proof
# that only passes second is not a proof.
set -uo pipefail

RUNTIME_COMPOSE="${COMPOSE:-docker compose -f compose.yaml}"
RUNTIME_SERVICE=frankenphp
RUNTIME_PORT="${DRAIN_PORT:-8099}"

say() { printf '\n== %s\n' "$1"; }

runtime_logs() {
  $RUNTIME_COMPOSE --profile app logs --no-color --tail 40 "$RUNTIME_SERVICE" >&2 || true
}

runtime_remove() {
  $RUNTIME_COMPOSE --profile app rm -fsv "$RUNTIME_SERVICE" >/dev/null 2>&1 || true
}

# Create the database roles, from a process that is NOT the server.
#
# BEFORE THE FIRST `up`, and that ordering is the whole point of this file rather than a
# tidiness preference. RefuseUnsafeBoot runs on Octane's WorkerStarting, and
# BootChecks::runtimeRoleIsSafe() connects as qrpager_app: with the roles absent it
# cannot verify the role, which it reports as unsafe because "cannot tell" must never
# read as "it is fine". The worker then exits 1, FrankenPHP restarts it, and after five
# consecutive failures the process dies with "worker has not reached
# frankenphp_handle_request()". So there is no window in which a bootstrap run after the
# `up` could rescue the container -- measured against a fresh volume, which is what CI
# starts from every run:
#
#   local.CRITICAL: boot refused: unsafe-runtime-role: the runtime role on connection
#   [pgsql] could not be verified (Illuminate\Database\QueryException)
#
# compose's `depends_on: postgres: condition: service_healthy` cannot express this.
# pg_isready answers "the cluster accepts connections"; role existence is an APPLICATION
# precondition, and no container-level health check knows about it. Task 13 inherits the
# same constraint: ECS container and task dependencies have the same gap, so the roles
# have to be created by a separate task that completes before the service task starts.
#
# A one-off container, with root credentials passed only here. The serving container
# deliberately carries none -- D9's boot assertion refuses a process that can resolve
# them -- so this is §2.1's model rather than a workaround for it. It works because the
# boot checks are deliberately NOT in a service provider: db:bootstrap and migrate boot
# the same providers and run before the roles and schema exist, which is why
# RefuseUnsafeBoot is registered on WorkerStarting and nowhere else.
runtime_bootstrap_roles() {
  say "creating the database roles from a separate process (the server has none)"

  $RUNTIME_COMPOSE --profile app run --rm \
    -e DB_ROOT_USERNAME=qrpager_root -e DB_ROOT_PASSWORD=local_dev_only \
    -e DB_OWNER_USERNAME=qrpager_owner -e DB_OWNER_PASSWORD=local_dev_only \
    -e DB_WORKER_USERNAME=qrpager_worker -e DB_WORKER_PASSWORD=local_dev_only \
    -e DB_MAINTENANCE_DATABASE=postgres \
    "$RUNTIME_SERVICE" php artisan db:bootstrap
}

runtime_wait_for_health() {
  for i in $(seq 1 60); do
    curl -fsS --max-time 2 "http://127.0.0.1:${RUNTIME_PORT}/healthz" >/dev/null 2>&1 && return 0
    sleep 1
  done

  return 1
}

# Build, establish the precondition, start, wait. In that order, every time.
runtime_start() {
  # --pull, so the pinned tag is re-resolved from the registry rather than trusted from
  # whatever layer this machine happens to hold. The tag names a PHP version but not a
  # digest, so a local base image can be an older build of the same tag.
  say "building the pinned runtime"
  $RUNTIME_COMPOSE --profile app build --pull "$RUNTIME_SERVICE"

  runtime_bootstrap_roles || return 1

  say "starting the pinned runtime"
  $RUNTIME_COMPOSE --profile app up -d --force-recreate "$RUNTIME_SERVICE" || return 1

  runtime_wait_for_health
}
