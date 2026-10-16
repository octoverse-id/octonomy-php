#!/bin/sh
# Reusable Octonomy container harness.
#
# Boots a real Octonomy server (Postgres + the published GHCR image), applies
# migrations, mints service tokens, asserts the environment is genuinely usable,
# seeds a known corpus of rows -- then writes the resulting credentials to an
# env file that both SDK version lines source.
#
# `docker run` alone is not enough. Every step below exists because omitting it
# produces an environment that looks healthy and silently fails, or worse,
# passes tests vacuously. The comments name which one.
#
# Usage:
#   scripts/octonomy-harness.sh up      # boot, verify, seed, write the env file
#   scripts/octonomy-harness.sh down    # tear down containers, network, env file
#   scripts/octonomy-harness.sh logs    # dump container logs
#   scripts/octonomy-harness.sh env     # print the env file path
#
# POSIX sh on purpose: the PHP 8.2+ line on main and the PHP 7.4 compat line
# both invoke this, as do the test suite and (#13) the developer-facing
# dev-server bin, and none of them may grow a bootstrap of its own.
#
# PROVENANCE. Ported from octonomy-go/scripts/octonomy-harness.sh at 6770494
# (2026-09-17). The fork is deliberate: one copy here serves both this repo's
# tests and the Composer bin, which cannot reach into a sibling repository. The
# cost is drift, so changes to the shared parts (boot, gates, grants) should be
# offered upstream. What is NOT upstream, and should not be mistaken for drift:
#   * the image is pinned by digest, and an unpinned override is refused
#   * the container prefix defaults to octonomy-php-harness, so this harness
#     never removes a Go harness running on the same machine
#   * seed_corpus, which writes the rows the suites and fixture capture read

set -eu

# --- Configuration -------------------------------------------------------------
# Every value is overridable so a caller can run two harnesses side by side, but
# the defaults are what CI uses.

# Pinned by DIGEST, not by tag. A tag is a mutable pointer: `:latest` and
# `:edge` both exist in this registry and move on every release, and even a
# version tag like `:3.2.1` can be re-pushed. A digest names exactly one image,
# so a green run stays reproducible. The `:3.2.1` in the reference is for the
# reader only -- when a reference carries both, Docker resolves the digest and
# ignores the tag.
#
# To move the pin: pull the new release, read its digest with
#   docker inspect --format '{{index .RepoDigests 0}}' ghcr.io/octoverse-id/octonomy:<version>
# and replace both halves below together.
#
# 3.2.1 is a FLOOR, not just a refresh. It is the first release in which
# GET /tags carries an ORDER BY (upstream octonomy#162): before it, the list
# annotates usage_count, which makes the query a GROUP BY, and Django drops
# Meta.ordering from aggregate queries -- so LIMIT/OFFSET over it was undefined
# and a tags walk could repeat or miss rows with no concurrent writes at all.
# octonomy-go's TestIntegration_TagsListPagesInATotalOrder asserts the fix, and
# is built so a pre-3.2.1 server is expected to fail it rather than pass
# vacuously (an unordered query is undefined, not adversarial, so no fixture can
# force that on every run -- 3.1.0 was verified to fail it). Any paged read this
# repo's suites or fixture capture make inherits the same floor.
HARNESS_IMAGE="${OCTONOMY_HARNESS_IMAGE:-ghcr.io/octoverse-id/octonomy:3.2.1@sha256:776dca5f1a5a71e7eee4b389ade7b6c53e4db46bc5f9713384463853a281f137}"
# Postgres is pinned the same way, for the same reason: `postgres:16` moves on
# every 16.x patch, and a database that changes underneath a green run is still
# a run nobody can reproduce. Both pins are enforced by require_pinned_images.
POSTGRES_IMAGE="${OCTONOMY_HARNESS_POSTGRES_IMAGE:-postgres:16@sha256:ca0bd484cb98bf4b24eb1010e73fb3fcbd6714d240fbc1a10eea5b7dbecb641d}"

# Not upstream's `octonomy-harness`: `up` begins by force-removing every
# container under its prefix, so sharing one would make booting this harness
# silently destroy a Go harness someone is using. The port still defaults to the
# same 8000, so running both at once needs OCTONOMY_HARNESS_PORT -- and a clash
# there fails loudly at `docker run -p` rather than removing anything.
PREFIX="${OCTONOMY_HARNESS_PREFIX:-octonomy-php-harness}"
NET="${PREFIX}-net"
DB="${PREFIX}-db"
APP="${PREFIX}-app"

PORT="${OCTONOMY_HARNESS_PORT:-8000}"
BASE_URL="http://127.0.0.1:${PORT}"

ENV_FILE="${OCTONOMY_HARNESS_ENV_FILE:-.octonomy-harness.env}"

# Bounded readiness, in two parts. Both are needed for the ceiling to be real.
#
# READY_TIMEOUT is a true wall-clock ceiling per gate: the loops below run
# against a deadline, not an attempt count, so a slow probe eats into the budget
# instead of silently extending it. (An attempt-based loop advertising
# ATTEMPTS*INTERVAL is wrong the moment a probe takes longer than the interval.)
# Each probe and each inter-probe sleep is clamped to the remaining budget, so
# the gate cannot overshoot by a trailing probe either.
#
# PROBE_TIMEOUT bounds each individual probe. Without it a single request can
# block forever and the deadline is never re-evaluated -- a server that accepts
# the TCP connection but never completes the response does exactly that (a
# wedged gunicorn worker, or a stalled cursor inside /health/ready, which is all
# that view does). Verified: an unbounded `curl` against such a socket was still
# waiting when killed, while `--max-time` returns exit 28 on schedule. Note the
# ordinary startup window is NOT this case -- Docker's published port resets the
# connection until the app binds, so the loop advances normally.
#
# The poll interval is a sleep *between* probes; no gate is ever a blind
# `sleep N && assume up`.
READY_TIMEOUT="${OCTONOMY_HARNESS_READY_TIMEOUT:-120}"
READY_INTERVAL="${OCTONOMY_HARNESS_READY_INTERVAL:-2}"
PROBE_TIMEOUT="${OCTONOMY_HARNESS_PROBE_TIMEOUT:-5}"

# Bound for the real API calls below. Larger than a probe: a namespaced create
# also writes an audit row and an outbox event.
REQUEST_TIMEOUT="${OCTONOMY_HARNESS_REQUEST_TIMEOUT:-30}"

# Real values, not placeholders. config/settings.py:49 and :352 refuse to boot
# when DJANGO_DEBUG=false (the image default) and either the secret key or the
# token pepper is empty or still the documented local-dev default.
#
# The pepper must be IDENTICAL in the mint container and the app container:
# it salts the token hash, so a mismatch yields a token that authenticates
# nowhere, surfacing as a blanket 401 that looks like a bad token rather than a
# bad harness.
SECRET_KEY="${OCTONOMY_HARNESS_SECRET_KEY:-harness-secret-not-a-real-secret}"
TOKEN_PEPPER="${OCTONOMY_HARNESS_TOKEN_PEPPER:-harness-pepper-not-a-real-pepper}"

# The identity the SDK test suites authenticate as.
TENANT_ID="${OCTONOMY_HARNESS_TENANT_ID:-harness-tenant}"
APPLICATION_ID="${OCTONOMY_HARNESS_APPLICATION_ID:-harness-app}"
NAMESPACE_TYPE="${OCTONOMY_HARNESS_NAMESPACE_TYPE:-merchant}"
NAMESPACE_ID="${OCTONOMY_HARNESS_NAMESPACE_ID:-harness-merchant}"

# Two further merchant namespaces, each with a token holding an EXACT grant for
# it and nothing else. The wildcard token above cannot stand in for these, and
# the difference is the whole point of an isolation suite (octonomy-go#17):
#
#   * A wildcard grant matches every partition, global included
#     (grant_matches_namespace, octonomy/core/auth.py:206-213). So a suite
#     driven by it can only ever assert what the SERVER's namespace FILTER does
#     -- never what AUTHORIZATION does, because authorization never says no.
#   * An exact grant matches its own partition alone, so a merchant-A token
#     asking for merchant-B is refused rather than filtered. That is the
#     cross-merchant boundary a caller actually relies on.
#   * include_global is fail-closed against exactly this shape
#     (request_include_global, auth.py:53-67): asking for global rows widens the
#     REQUESTED set, and a token with no global authority still sees none. With a
#     wildcard token the opt-in always succeeds, so the fail-closed branch is
#     unreachable and would be tested vacuously.
#
# An exact namespace grant requires an application (create_service_token refuses
# otherwise), so both are bound to APPLICATION_ID above.
NAMESPACE_A_ID="${OCTONOMY_HARNESS_NAMESPACE_A_ID:-harness-merchant-a}"
NAMESPACE_B_ID="${OCTONOMY_HARNESS_NAMESPACE_B_ID:-harness-merchant-b}"

PG_DB=octonomy
PG_USER=octonomy
PG_PASSWORD=octonomy
DATABASE_URL="postgres://${PG_USER}:${PG_PASSWORD}@${DB}:5432/${PG_DB}"

# --- Output helpers ------------------------------------------------------------

log() { printf 'harness: %s\n' "$*" >&2; }

fail() {
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
        printf '::error::harness: %s\n' "$*" >&2
    else
        printf 'harness: ERROR: %s\n' "$*" >&2
    fi
    exit 1
}

# --- Docker plumbing -----------------------------------------------------------

require_docker() {
    command -v docker >/dev/null 2>&1 || fail "docker is not installed or not on PATH"
    docker info >/dev/null 2>&1 || fail "the docker daemon is not reachable"
}

# Refuse an image reference that is not pinned by digest. The defaults are; an
# override is where a moving tag creeps back in, because
# OCTONOMY_HARNESS_IMAGE=...:latest boots, goes green today, and means something
# else tomorrow. A locally built image has no registry digest at all, so
# OCTONOMY_HARNESS_ALLOW_UNPINNED=1 is the explicit, per-run way to boot one.
require_pinned_images() {
    for _image in "$HARNESS_IMAGE" "$POSTGRES_IMAGE"; do
        if printf '%s\n' "$_image" | grep -Eq '@sha256:[0-9a-f]{64}$'; then
            continue
        fi
        [ "${OCTONOMY_HARNESS_ALLOW_UNPINNED:-}" = "1" ] ||
            fail "${_image} is not pinned by digest: use <image>@sha256:<digest>, or set OCTONOMY_HARNESS_ALLOW_UNPINNED=1 to boot it anyway"
        log "WARNING: ${_image} is not pinned by digest, so this run is not reproducible"
    done
}

container_running() {
    [ -n "$(docker ps --filter "name=^${1}$" --filter status=running --format '{{.Names}}')" ]
}

# Every Octonomy container -- the two one-shots and the long-running app -- gets
# the same settings environment. Divergence here is how a `manage.py check` that
# passes in the mint container fails in the app container.
octonomy_env_args() {
    printf '%s\n' \
        "-e" "DJANGO_SECRET_KEY=${SECRET_KEY}" \
        "-e" "SERVICE_TOKEN_PEPPER=${TOKEN_PEPPER}" \
        "-e" "DATABASE_URL=${DATABASE_URL}" \
        "-e" "ALLOWED_HOSTS=localhost,127.0.0.1,0.0.0.0" \
        "-e" "OCTONOMY_NAMESPACE_WRITE_ENABLED=true" \
        "-e" "LOG_LEVEL=WARNING"
}

# Run a one-shot management command inside the harness network.
oneshot() {
    # shellcheck disable=SC2046 # deliberate word splitting of the env flag list
    docker run --rm --network "$NET" $(octonomy_env_args) "$HARNESS_IMAGE" "$@"
}

# Mint one service token and print it on stdout. $1 is the client name; every
# remaining argument is passed to create_service_token verbatim, which is how the
# grant shape (wildcard vs exact) varies between callers.
#
# The scopes are fixed here rather than per-caller: every token this harness
# mints exists to drive the same SDK suites, and a token quietly missing
# audit:read surfaces as a 403 inside an audit assertion rather than as a
# harness fault. Narrowing one token's scopes is a deliberate change to make when
# a suite needs to assert a scope denial, not a knob to leave open.
#
# Called as `TOK="$(mint_token ...)"`: `fail` inside the command substitution
# exits the subshell non-zero, `set -e` then ends the script, and the message
# still reaches the terminal because fail writes to stderr, which is not
# captured. Logging goes to stderr for the same reason -- a log line on stdout
# would be parsed as part of the token.
mint_token() {
    _mint_name="$1"
    shift
    _mint_output="$(
        oneshot python manage.py create_service_token \
            --name "$_mint_name" \
            --tenant "$TENANT_ID" \
            --scope tags:read \
            --scope tags:write \
            --scope audit:read \
            "$@"
    )" || fail "could not mint the service token ${_mint_name}"
    _mint_token="$(printf '%s\n' "$_mint_output" | sed -n 's/^Token: //p')"
    [ -n "$_mint_token" ] ||
        fail "could not parse a token out of create_service_token output for ${_mint_name}: ${_mint_output}"
    printf '%s\n' "$_mint_token"
}

dump_logs() {
    for name in "$DB" "$APP"; do
        if docker ps -a --filter "name=^${name}$" --format '{{.Names}}' | grep -q .; then
            printf '\n===== docker logs %s =====\n' "$name" >&2
            docker logs --tail "${OCTONOMY_HARNESS_LOG_TAIL:-100}" "$name" >&2 2>&1 || true
        fi
    done
}

# --- Bounded gates -------------------------------------------------------------

# Seconds left before $1 (an epoch deadline). Negative once it has passed.
budget_until() { echo $(($1 - $(date +%s))); }

# The smaller of two integers.
min_int() {
    if [ "$1" -lt "$2" ]; then echo "$1"; else echo "$2"; fi
}

# Sleep at most $2 seconds, and never past the deadline in $1. Keeps the poll
# interval from being the thing that overshoots the ceiling.
sleep_within() {
    _left="$(budget_until "$1")"
    [ "$_left" -gt 0 ] || return 0
    sleep "$(min_int "$2" "$_left")"
}

wait_for_postgres() {
    log "waiting for Postgres (bound: ${READY_TIMEOUT}s)"
    deadline=$(($(date +%s) + READY_TIMEOUT))
    while :; do
        container_running "$DB" || fail "the Postgres container exited during startup"
        # Clamp the probe to the budget that is actually left. A fixed probe
        # timeout started just inside the deadline would overshoot the ceiling
        # this function just advertised. Also keeps the value >= 1: `pg_isready
        # -t 0` means wait forever, which is the bug being fixed.
        left="$(budget_until "$deadline")"
        [ "$left" -gt 0 ] || break
        if docker exec "$DB" pg_isready -U "$PG_USER" -d "$PG_DB" \
            -t "$(min_int "$PROBE_TIMEOUT" "$left")" >/dev/null 2>&1; then
            log "Postgres accepting connections"
            return 0
        fi
        sleep_within "$deadline" "$READY_INTERVAL"
    done
    fail "Postgres did not become ready within ${READY_TIMEOUT}s"
}

wait_for_ready() {
    log "waiting for /health/ready (bound: ${READY_TIMEOUT}s)"
    deadline=$(($(date +%s) + READY_TIMEOUT))
    while :; do
        container_running "$APP" || fail "the Octonomy container exited during startup"
        # Same clamp as above, and for the same reason: `curl --max-time 0`
        # also means no limit at all.
        left="$(budget_until "$deadline")"
        [ "$left" -gt 0 ] || break
        if curl -fsS --max-time "$(min_int "$PROBE_TIMEOUT" "$left")" \
            "${BASE_URL}/health/ready" >/dev/null 2>&1; then
            log "Octonomy reports ready"
            return 0
        fi
        sleep_within "$deadline" "$READY_INTERVAL"
    done
    fail "/health/ready did not return 200 within ${READY_TIMEOUT}s"
}

# --- The assertion that makes the harness worth having -------------------------

# /health/ready only opens a database cursor (octonomy/core/views.py:22) -- it
# reports ready on an EMPTY, unmigrated database. It is a liveness gate, not a
# proof the environment works. So prove it: perform the single most
# failure-prone operation the SDK suites depend on and require a real 201.
#
# Two ways this silently degrades if the harness is wrong:
#
#   1. OCTONOMY_NAMESPACE_WRITE_ENABLED defaults to "false"
#      (config/settings.py:393) and is parsed strictly. Without it every
#      namespaced write returns 403 namespaced_writes_disabled -- and a test
#      suite that only asserts "no transport error" passes vacuously while
#      testing nothing about the namespace axis.
#   2. The service token needs a grant that REACHES the namespace partition.
#      A plain grant (the shape `manage.py seed_demo` mints) has
#      namespace_type=NULL and namespace_wildcard=False, and
#      grant_matches_namespace (octonomy/core/auth.py:206-213) then matches
#      global scope ONLY -- every namespaced request 403s. Hence
#      --namespace-wildcard when minting.
#
# Note the request must also carry application_id: octonomy/core/auth.py:197-198
# rejects a namespaced request that does not name its parent application, even
# when the grant itself is tenant-wide.
assert_namespaced_write() {
    log "asserting a namespaced write genuinely succeeds"
    body_file="$(mktemp)"
    code="$(
        curl -sS -o "$body_file" -w '%{http_code}' \
            --max-time "$REQUEST_TIMEOUT" \
            -X POST "${BASE_URL}/api/v2/vocabularies" \
            -H "Authorization: Bearer ${SERVICE_TOKEN}" \
            -H "X-Tenant-ID: ${TENANT_ID}" \
            -H "X-Namespace-Type: ${NAMESPACE_TYPE}" \
            -H "X-Namespace-ID: ${NAMESPACE_ID}" \
            -H "Content-Type: application/json" \
            -d "{\"application_id\":\"${APPLICATION_ID}\",\"name\":\"Harness Probe\",\"slug\":\"harness-probe\"}"
    )"
    payload="$(cat "$body_file")"
    rm -f "$body_file"

    if [ "$code" != "201" ]; then
        log "expected 201, got ${code}: ${payload}"
        if [ "$code" = "000" ]; then
            fail "the namespaced write probe got no response within ${REQUEST_TIMEOUT}s"
        fi
        case "$payload" in
            *namespaced_writes_disabled*)
                fail "namespaced writes are disabled -- OCTONOMY_NAMESPACE_WRITE_ENABLED did not reach the container"
                ;;
            *)
                fail "the namespaced write probe failed with HTTP ${code}"
                ;;
        esac
    fi

    # A 201 is necessary but not sufficient: the row must actually carry the
    # namespace. A server that accepted the write but persisted it globally
    # would return 201 with null namespace fields, and every namespace
    # assertion downstream would be testing global behaviour under a
    # namespaced name.
    case "$payload" in
        *"\"namespace_type\":\"${NAMESPACE_TYPE}\""*) ;;
        *) fail "the write returned 201 but did not persist namespace_type=${NAMESPACE_TYPE}: ${payload}" ;;
    esac
    case "$payload" in
        *"\"namespace_id\":\"${NAMESPACE_ID}\""*) ;;
        *) fail "the write returned 201 but did not persist namespace_id=${NAMESPACE_ID}: ${payload}" ;;
    esac

    log "namespaced write confirmed (201, namespace persisted)"
}

# Print the HTTP status of one bounded request, discarding the body. "000" is
# curl's own "no response arrived", which --max-time produces on a wedged socket.
probe_status() {
    _ps_method="$1"
    _ps_url="$2"
    _ps_token="$3"
    _ps_namespace="$4"
    shift 4
    curl -sS -o /dev/null -w '%{http_code}' \
        --max-time "$REQUEST_TIMEOUT" \
        -X "$_ps_method" "$_ps_url" \
        -H "Authorization: Bearer ${_ps_token}" \
        -H "X-Tenant-ID: ${TENANT_ID}" \
        -H "X-Namespace-Type: ${NAMESPACE_TYPE}" \
        -H "X-Namespace-ID: ${_ps_namespace}" \
        "$@"
}

# The exact merchant grants, proved in both directions.
#
# Unlike assert_namespaced_write above, the failure this guards against is not a
# vacuous pass in the isolation suite -- a token that reached nothing would make
# every assertion there fail loudly. It is a MISDIAGNOSIS: thirty 403s inside
# test assertions read as an SDK defect, and the actual fault is a token minted
# with the wrong grant shape. Two requests here say so in one line instead.
#
# The negative direction is the one worth the round trip. A grant that reached
# every namespace would still satisfy the positive probe, and the whole suite
# rests on it not doing that.
assert_exact_grants() {
    log "asserting each exact merchant grant reaches its own namespace and no other"

    code="$(
        probe_status POST "${BASE_URL}/api/v2/vocabularies" \
            "$NAMESPACE_A_TOKEN" "$NAMESPACE_A_ID" \
            -H "Content-Type: application/json" \
            -d "{\"application_id\":\"${APPLICATION_ID}\",\"name\":\"Harness Probe A\",\"slug\":\"harness-probe-a\"}"
    )"
    [ "$code" = "201" ] || fail "the ${NAMESPACE_A_ID} grant could not write in its own namespace (HTTP ${code}, want 201)"

    # Refused, not merely filtered: an exact grant that does not match the
    # request's partition never reaches a queryset (BearerTokenPermission,
    # octonomy/core/auth.py:402-408).
    code="$(
        probe_status GET "${BASE_URL}/api/v2/tags?application_id=${APPLICATION_ID}" \
            "$NAMESPACE_A_TOKEN" "$NAMESPACE_B_ID"
    )"
    [ "$code" = "403" ] || fail "the ${NAMESPACE_A_ID} grant was not refused in ${NAMESPACE_B_ID} (HTTP ${code}, want 403): these tokens are not namespace-isolated, so the isolation suite would be asserting nothing"

    log "exact merchant grants confirmed (201 inside, 403 across)"
}

# --- The seeded corpus ---------------------------------------------------------

# Everything above proves the environment WORKS. Nothing above puts a taxonomy
# in it: the probes leave three namespaced vocabularies and not one tag, so a
# fixture captured here would be a fixture of empty lists, and a list assertion
# would pass by finding nothing to check.
#
# `manage.py seed_demo` cannot stand in. It writes tenant_demo (seed_demo.py:14)
# with a token scoped to that tenant, while every credential here is
# harness-tenant -- requests are tenant-scoped, so these tokens would see none of
# its rows -- and it creates no namespaced data at all.
#
# So the corpus is written here, with curl, as the wildcard token. Through curl
# and not through the SDK, on purpose: fixture capture (#9) records what these
# rows look like, and if the SDK wrote them, a defect in the code under repair
# could shape the very fixtures meant to catch it.
#
# It covers both axes, because the bugs live in the difference between them:
#
#   global (no namespace headers; the only partition /api/v1 can see)
#     vocabulary colors (shared)       tags red, green, maroon (child of red)
#     vocabulary sizes  (harness-app)  tag  large
#     aliases     scarlet -> red (shared), big -> large (harness-app)
#     assignments product/sku-001: red, large
#                 product/sku-002: green, and red again via alias_slug scarlet
#   merchant:harness-merchant-a        tags red (in global colors), sale
#     alias       promo -> sale
#     assignments product/sku-001: red, sale
#   merchant:harness-merchant-b        tags red, clearance
#     assignments product/sku-001: red
#
# `red` exists in all three partitions under the same (type, slug) on purpose.
# Slug uniqueness is per partition (octonomy/tags/models.py), so nothing but the
# namespace tells the three apart -- exactly what a namespace-blind client or a
# namespace-blind fixture gets wrong. product/sku-001 carries a tag in every
# partition for the same reason. Nullable fields are populated on some rows and
# null on others (description, application_id, vocabulary_id, parent_id), so a
# fixture holds both shapes of each.
#
# The database is new on every `up`, so these writes never meet a previous run's
# rows, and assert_corpus can require exact counts rather than minimums. Keep
# the counts below in step with the table above.
SEED_GLOBAL_TAGS=4
SEED_NAMESPACE_A_TAGS=2
SEED_NAMESPACE_B_TAGS=2

# Send one bounded request as the wildcard token and print "<status> <body>".
# $1 method, $2 path under BASE_URL, $3 namespace id or "" for global, and $4
# the JSON body or "" for none.
#
# The `|| _api_code=000` matters under `set -e`: a curl that times out exits
# non-zero, and without it the assignment would end the script before the
# caller could say which request hung. 000 is curl's own "no response".
api_request() {
    _api_method="$1"
    _api_path="$2"
    _api_namespace="$3"
    _api_body="$4"
    if [ -n "$_api_namespace" ]; then
        set -- -H "X-Namespace-Type: ${NAMESPACE_TYPE}" -H "X-Namespace-ID: ${_api_namespace}"
    else
        set --
    fi
    if [ -n "$_api_body" ]; then
        set -- "$@" -H "Content-Type: application/json" -d "$_api_body"
    fi
    _api_file="$(mktemp)"
    _api_code="$(
        curl -sS -o "$_api_file" -w '%{http_code}' \
            --max-time "$REQUEST_TIMEOUT" \
            -X "$_api_method" "${BASE_URL}${_api_path}" \
            -H "Authorization: Bearer ${SERVICE_TOKEN}" \
            -H "X-Tenant-ID: ${TENANT_ID}" \
            "$@"
    )" || _api_code=000
    printf '%s %s\n' "$_api_code" "$(cat "$_api_file")"
    rm -f "$_api_file"
}

# POST one corpus row to /api/v2/$1, require a 201, and print the new row's id.
# $2 is the namespace id ("" for global) and $3 the JSON body.
#
# Called as `ID="$(seed_write ...)"`, like mint_token: `fail` exits the
# subshell, `set -e` then ends the script, and the message reaches the terminal
# on stderr. Every response serializer lists `id` first, so the id is read from
# the head of the envelope; a 201 whose body does not open {"data":{"id":...}}
# is a contract change, and failing on it here is cheaper than hydrating it later.
seed_write() {
    _sw_where="${2:-global}"
    _sw_response="$(api_request POST "/api/v2/${1}" "$2" "$3")"
    _sw_code="${_sw_response%% *}"
    _sw_payload="${_sw_response#* }"
    [ "$_sw_code" = "201" ] ||
        fail "seeding POST /api/v2/${1} (${_sw_where}) returned HTTP ${_sw_code}, want 201: ${_sw_payload} -- sent: ${3}"
    _sw_id="$(printf '%s\n' "$_sw_payload" | sed -n 's/^{"data":{"id":"\([0-9a-f-]\{36\}\)".*/\1/p')"
    [ -n "$_sw_id" ] ||
        fail "seeding POST /api/v2/${1} (${_sw_where}) returned 201 without a {\"data\":{\"id\":...}} body: ${_sw_payload}"
    printf '%s\n' "$_sw_id"
}

# Global rows that omit application_id are SHARED across every application in
# the tenant. Only a tenant-wide grant may write one, which the wildcard token
# is: it was minted without --application. A namespaced row always names its
# application (octonomy/core/auth.py:195-198), and an assignment always does.
seed_corpus() {
    log "seeding the corpus (global, ${NAMESPACE_A_ID}, ${NAMESPACE_B_ID})"
    _app="$APPLICATION_ID"
    _a="$NAMESPACE_A_ID"
    _b="$NAMESPACE_B_ID"

    # Global.
    _colors="$(seed_write vocabularies "" \
        '{"name":"Colors","slug":"colors","description":"Shared by every application in the tenant"}')"
    _sizes="$(seed_write vocabularies "" \
        "$(printf '{"application_id":"%s","name":"Sizes","slug":"sizes"}' "$_app")")"
    _red="$(seed_write tags "" \
        "$(printf '{"name":"Red","slug":"red","type":"color","vocabulary_id":"%s","description":"Primary red","metadata":{"hex":"#cc0000"}}' "$_colors")")"
    _green="$(seed_write tags "" \
        "$(printf '{"name":"Green","slug":"green","type":"color","vocabulary_id":"%s"}' "$_colors")")"
    seed_write tags "" \
        "$(printf '{"name":"Maroon","slug":"maroon","type":"color","vocabulary_id":"%s","parent_id":"%s"}' "$_colors" "$_red")" >/dev/null
    _large="$(seed_write tags "" \
        "$(printf '{"application_id":"%s","name":"Large","slug":"large","type":"size","vocabulary_id":"%s"}' "$_app" "$_sizes")")"
    seed_write tag-aliases "" \
        "$(printf '{"tag_id":"%s","name":"Scarlet","slug":"scarlet"}' "$_red")" >/dev/null
    # An alias of an application-scoped tag must name that same application.
    seed_write tag-aliases "" \
        "$(printf '{"application_id":"%s","tag_id":"%s","name":"Big","slug":"big"}' "$_app" "$_large")" >/dev/null
    seed_assignment "" "tag_id" "$_red" sku-001
    seed_assignment "" "tag_id" "$_large" sku-001
    seed_assignment "" "tag_id" "$_green" sku-002
    seed_assignment "" "alias_slug" scarlet sku-002

    # merchant A. Its red hangs off the GLOBAL colors vocabulary: a namespaced
    # row may reference a global one, and that cross-partition reference is a
    # shape worth having on record.
    _a_red="$(seed_write tags "$_a" \
        "$(printf '{"application_id":"%s","name":"Red","slug":"red","type":"color","vocabulary_id":"%s"}' "$_app" "$_colors")")"
    _a_sale="$(seed_write tags "$_a" \
        "$(printf '{"application_id":"%s","name":"Sale","slug":"sale","type":"label","description":"Merchant A promotion"}' "$_app")")"
    seed_write tag-aliases "$_a" \
        "$(printf '{"application_id":"%s","tag_id":"%s","name":"Promo","slug":"promo"}' "$_app" "$_a_sale")" >/dev/null
    seed_assignment "$_a" "tag_id" "$_a_red" sku-001
    seed_assignment "$_a" "tag_id" "$_a_sale" sku-001

    # merchant B.
    _b_red="$(seed_write tags "$_b" \
        "$(printf '{"application_id":"%s","name":"Red","slug":"red","type":"color"}' "$_app")")"
    seed_write tags "$_b" \
        "$(printf '{"application_id":"%s","name":"Clearance","slug":"clearance","type":"label"}' "$_app")" >/dev/null
    seed_assignment "$_b" "tag_id" "$_b_red" sku-001

    log "corpus seeded"
}

# Assign one tag to product/$4 in partition $1. $2 names how the tag is
# identified (tag_id or alias_slug) and $3 is its value. A 201 means a new
# assignment; the server answers 200 for one that already existed, which on a
# fresh database means the corpus assigned the same tag twice by mistake.
seed_assignment() {
    seed_write tag-assignments "$1" \
        "$(printf '{"application_id":"%s","%s":"%s","resource_type":"product","resource_id":"%s","assigned_by":"octonomy-php-harness"}' \
            "$APPLICATION_ID" "$2" "$3" "$4")" >/dev/null
}

# Count the occurrences of the literal string $2 in $1.
count_of() {
    printf '%s\n' "$1" | grep -o -F -- "$2" | wc -l | tr -d ' '
}

# Read one partition's tags back and require exactly $3 rows, each carrying the
# literal $4 -- or, when $4 is empty, no namespace field at all. $1 is the API
# version and $2 the namespace id ("" for global).
#
# Three numbers must agree, because each can be right while the corpus is
# wrong: the pagination count (the server's total), the rows actually on the
# page, and the rows carrying the partition's own namespace marker. A namespaced
# list that leaked global rows would pass the first two and fail the third.
assert_tag_partition() {
    _at_where="/api/${1}/tags (${2:-global})"
    # application_id is required on a namespaced read (auth.py:195-198) and
    # harmless on a global one, where include_shared keeps the shared rows.
    _at_response="$(api_request GET "/api/${1}/tags?application_id=${APPLICATION_ID}&limit=200" "$2" "")"
    _at_code="${_at_response%% *}"
    _at_payload="${_at_response#* }"
    [ "$_at_code" = "200" ] || fail "reading back ${_at_where} returned HTTP ${_at_code}: ${_at_payload}"

    _at_total="$(printf '%s\n' "$_at_payload" | sed -n 's/.*"pagination":{[^}]*"count":\([0-9][0-9]*\).*/\1/p')"
    [ "$_at_total" = "$3" ] ||
        fail "${_at_where} reports ${_at_total:-no} total rows, want ${3}: ${_at_payload}"
    _at_rows="$(count_of "$_at_payload" "\"tenant_id\":\"${TENANT_ID}\"")"
    [ "$_at_rows" = "$3" ] ||
        fail "${_at_where} returned ${_at_rows} rows on the page, want ${3}: ${_at_payload}"
    if [ -n "$4" ]; then
        _at_marked="$(count_of "$_at_payload" "$4")"
        [ "$_at_marked" = "$3" ] ||
            fail "${_at_where}: ${_at_marked} of ${3} rows carry ${4}, so the partition is not what was seeded: ${_at_payload}"
    else
        _at_marked="$(count_of "$_at_payload" '"namespace_')"
        [ "$_at_marked" = "0" ] ||
            fail "${_at_where} emitted namespace fields, which /api/v1 must never do: ${_at_payload}"
    fi
}

# The seed writes all returned 201, which proves each row exists. It does not
# prove the rows landed in the partitions the corpus table claims, and that
# placement is the whole value of the corpus. So read every partition back.
assert_corpus() {
    log "asserting each partition reads back exactly as seeded"
    assert_tag_partition v2 "" "$SEED_GLOBAL_TAGS" '"namespace_type":null,"namespace_id":null'
    assert_tag_partition v2 "$NAMESPACE_A_ID" "$SEED_NAMESPACE_A_TAGS" \
        "\"namespace_type\":\"${NAMESPACE_TYPE}\",\"namespace_id\":\"${NAMESPACE_A_ID}\""
    assert_tag_partition v2 "$NAMESPACE_B_ID" "$SEED_NAMESPACE_B_TAGS" \
        "\"namespace_type\":\"${NAMESPACE_TYPE}\",\"namespace_id\":\"${NAMESPACE_B_ID}\""
    # v1 cannot address a namespace at all (namespace headers are a 400 there),
    # so it must see the global rows and nothing else.
    assert_tag_partition v1 "" "$SEED_GLOBAL_TAGS" ""
    log "corpus confirmed: ${SEED_GLOBAL_TAGS} global tags, ${SEED_NAMESPACE_A_TAGS} in ${NAMESPACE_A_ID}, ${SEED_NAMESPACE_B_TAGS} in ${NAMESPACE_B_ID}; /api/v1 sees only the global ones"
}

# --- Commands ------------------------------------------------------------------

cmd_down() {
    docker rm -f "$APP" >/dev/null 2>&1 || true
    docker rm -f "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    log "torn down"
}

cmd_up() {
    require_docker
    require_pinned_images
    command -v curl >/dev/null 2>&1 || fail "curl is not installed or not on PATH"

    # Any failure from here on dumps container logs before exiting -- a bare
    # "exit 1" from CI with the containers already reaped is undebuggable.
    trap 'dump_logs; cmd_down' EXIT

    # Idempotent: a previous interrupted run leaves containers behind, and
    # `docker run --name` fails on a name clash rather than reusing it.
    docker rm -f "$APP" >/dev/null 2>&1 || true
    docker rm -f "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true

    log "using image ${HARNESS_IMAGE}"
    docker network create "$NET" >/dev/null || fail "could not create the docker network ${NET}"

    log "starting Postgres"
    docker run -d --name "$DB" --network "$NET" \
        -e "POSTGRES_DB=${PG_DB}" \
        -e "POSTGRES_USER=${PG_USER}" \
        -e "POSTGRES_PASSWORD=${PG_PASSWORD}" \
        "$POSTGRES_IMAGE" >/dev/null || fail "could not start Postgres"
    wait_for_postgres

    # The image entrypoint runs `manage.py check` but NOT `migrate`
    # (docker-entrypoint.sh, and the Dockerfile says so explicitly: migrations
    # are a deploy step). Without this the app boots, /health/ready returns 200
    # because a cursor opens fine, and the first API call dies on a missing
    # relation.
    log "applying migrations"
    oneshot python manage.py migrate --noinput >/dev/null || fail "migrations failed"

    # --namespace-wildcard is load-bearing; see assert_namespaced_write.
    log "minting the wildcard service token"
    SERVICE_TOKEN="$(
        mint_token octonomy-php-harness --namespace-wildcard
    )"

    # The two exact merchant grants. See NAMESPACE_A_ID above for why a wildcard
    # token cannot stand in for them.
    log "minting the exact merchant grants (${NAMESPACE_A_ID}, ${NAMESPACE_B_ID})"
    NAMESPACE_A_TOKEN="$(
        mint_token octonomy-php-harness-ns-a \
            --application "$APPLICATION_ID" \
            --namespace-type "$NAMESPACE_TYPE" --namespace-id "$NAMESPACE_A_ID"
    )"
    NAMESPACE_B_TOKEN="$(
        mint_token octonomy-php-harness-ns-b \
            --application "$APPLICATION_ID" \
            --namespace-type "$NAMESPACE_TYPE" --namespace-id "$NAMESPACE_B_ID"
    )"

    log "starting Octonomy on port ${PORT}"
    # shellcheck disable=SC2046 # deliberate word splitting of the env flag list
    docker run -d --name "$APP" --network "$NET" \
        $(octonomy_env_args) \
        -p "${PORT}:8000" \
        "$HARNESS_IMAGE" >/dev/null || fail "could not start the Octonomy container"
    wait_for_ready

    assert_namespaced_write
    assert_exact_grants
    seed_corpus
    assert_corpus

    # Remove first, then create under a restrictive umask: `>` truncates an
    # existing file but leaves its old mode, so a previously world-readable
    # env file would keep leaking the token it now holds.
    rm -f "$ENV_FILE"
    umask 077
    # The heredoc is unquoted so the values below expand -- which means its
    # comment lines are live shell too. A backtick or a dollar-paren in one of
    # them runs as a command during `up` and writes its output into this file.
    cat > "$ENV_FILE" <<ENV_EOF
# Generated by scripts/octonomy-harness.sh -- do not commit.
# Source this (make test-integration does) before running the integration
# suite. OCTONOMY_TEST_BASE_URL is the gate: when it is empty the suite skips
# rather than fails. The seeded corpus these credentials can read is documented
# at seed_corpus in the script.
OCTONOMY_TEST_BASE_URL=${BASE_URL}
OCTONOMY_TEST_TOKEN=${SERVICE_TOKEN}
OCTONOMY_TEST_TENANT_ID=${TENANT_ID}
OCTONOMY_TEST_APPLICATION_ID=${APPLICATION_ID}
OCTONOMY_TEST_NAMESPACE_TYPE=${NAMESPACE_TYPE}
OCTONOMY_TEST_NAMESPACE_ID=${NAMESPACE_ID}
# Two merchant namespaces under the same type, each with a token holding an
# EXACT grant for it alone, for isolation assertions: a wildcard token can never
# be refused, so it cannot prove a cross-merchant boundary holds. Callers that
# need only the wildcard credentials above can ignore these.
OCTONOMY_TEST_NAMESPACE_A_ID=${NAMESPACE_A_ID}
OCTONOMY_TEST_NAMESPACE_A_TOKEN=${NAMESPACE_A_TOKEN}
OCTONOMY_TEST_NAMESPACE_B_ID=${NAMESPACE_B_ID}
OCTONOMY_TEST_NAMESPACE_B_TOKEN=${NAMESPACE_B_TOKEN}
ENV_EOF

    trap - EXIT
    log "ready at ${BASE_URL}; credentials written to ${ENV_FILE}"
}

case "${1:-up}" in
    up) cmd_up ;;
    down) cmd_down ;;
    logs) dump_logs ;;
    env) printf '%s\n' "$ENV_FILE" ;;
    *) fail "unknown command '${1}' (expected: up, down, logs, env)" ;;
esac
