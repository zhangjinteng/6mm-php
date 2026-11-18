#!/usr/bin/env bash
set -euo pipefail
EMAIL_PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EMAIL_PG_BIN="${PG_BIN:-/opt/homebrew/opt/postgresql@18/bin}"
EMAIL_SCRATCH="$(mktemp -d /tmp/site-email-pg.XXXXXX)"
email_cleanup() { "$EMAIL_PG_BIN/pg_ctl" -D "$EMAIL_SCRATCH/data" -m fast stop >/dev/null 2>&1 || true; }
trap email_cleanup EXIT
"$EMAIL_PG_BIN/initdb" -D "$EMAIL_SCRATCH/data" -A trust -U email_test >/dev/null
"$EMAIL_PG_BIN/pg_ctl" -D "$EMAIL_SCRATCH/data" -l "$EMAIL_SCRATCH/server.log" -o "-k $EMAIL_SCRATCH -h '' -p 55461" -w start >/dev/null
"$EMAIL_PG_BIN/createdb" -h "$EMAIL_SCRATCH" -p 55461 -U email_test email_publication_test
cd "$EMAIL_PROJECT_ROOT"
EMAIL_TEST_SOCKET="$EMAIL_SCRATCH" php tests/site_email_postgres.php
