#!/usr/bin/env bash
#
# Unit tests for the staging table-prefix logic in lib/.staging (PRESS0-4144).
#
# The staging script runs server-side via PHP exec() and is not exercised by the PHP (wpunit) or
# Playwright suites, so this is the only automated coverage for the equal-length prefix substitution
# and the dump prefix rewrite. The tests source lib/.staging with NFD_STAGING_SOURCED=1 (which skips
# the entrypoint and the top-level wp bootstrap) and call individual functions directly. No database
# or wp-cli is required: every function tested here is pure text/string logic.
#
# Run: bash tests/bash/test-staging-prefix.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGING_SCRIPT="$SCRIPT_DIR/../../lib/.staging"

if [ ! -f "$STAGING_SCRIPT" ]; then
  echo "FATAL: cannot find lib/.staging at $STAGING_SCRIPT" >&2
  exit 1
fi

# Source the script for testing. The guards keyed on NFD_STAGING_SOURCED skip the entrypoint and the
# wp bootstrap; set the globals the tested functions read by hand below.
export NFD_STAGING_SOURCED=1
# The script reads positional args ($1 command, $2 token, $3.. paths); give it harmless dummies so
# sourcing its arg-parsing lines does not fail. The NFD_STAGING_SOURCED guards skip all the real work.
set -- compat_check token /nonexistent/prod /nonexistent/stg http://p.test http://s.test 0 id slug name
# shellcheck disable=SC1090
source "$STAGING_SCRIPT"
set +e              # the script enables `set -e`; tests assert on non-zero returns, so turn it off
trap - EXIT         # drop the script's cleanup trap (it would shell out to wp on test exit)

# Keep logging out of the real production path during tests.
LOG_FILE="$(mktemp)"

PASS=0
FAIL=0

ok() { PASS=$((PASS + 1)); printf '  ok   - %s\n' "$1"; }
no() { FAIL=$((FAIL + 1)); printf '  FAIL - %s\n' "$1"; [ -n "${2:-}" ] && printf '         %s\n' "$2"; }

assert_eq() { # label expected actual
  if [ "$2" = "$3" ]; then ok "$1"; else no "$1" "expected [$2], got [$3]"; fi
}
assert_le() { # label value limit
  if [ "$2" -le "$3" ]; then ok "$1"; else no "$1" "expected $2 <= $3"; fi
}
assert_ne() { # label a b
  if [ "$2" != "$3" ]; then ok "$1"; else no "$1" "expected [$2] != [$3]"; fi
}
assert_contains() { # label haystack needle
  case "$2" in *"$3"*) ok "$1" ;; *) no "$1" "[$2] does not contain [$3]" ;; esac
}
assert_not_contains() { # label haystack needle
  case "$2" in *"$3"*) no "$1" "[$2] unexpectedly contains [$3]" ;; *) ok "$1" ;; esac
}

echo "== compute_staging_prefix =="

# The spec's own example: wp_5w8a7w6n2r_ -> sg_... (we use st_), same length, clearly different.
assert_eq "wp_ -> st_" "st_" "$(compute_staging_prefix 'wp_')"
assert_eq "randomized wp prefix" "st_5w8a7w6n2r_" "$(compute_staging_prefix 'wp_5w8a7w6n2r_')"
assert_eq "abilitynowbayarea prefix" "st_jqzu_" "$(compute_staging_prefix 'wp_jqzu_')"
assert_eq "vinmar prefix" "st_8s7r16xq9h_" "$(compute_staging_prefix 'wp_8s7r16xq9h_')"
# A production prefix that already starts with 'st' must fall back so staging != production.
assert_eq "st-prefixed prod falls back to sx" "sx_foo_" "$(compute_staging_prefix 'st_foo_')"
assert_eq "exactly 'st'" "sx" "$(compute_staging_prefix 'st')"
# Degenerate short prefixes: never longer than production, always distinct.
assert_eq "single char a -> s" "s" "$(compute_staging_prefix 'a')"
assert_eq "single char s -> x" "x" "$(compute_staging_prefix 's')"

echo "== compute_staging_prefix invariants (property checks) =="
for prod in 'wp_' 'wp_5w8a7w6n2r_' 'wordpress_' 'mysite_' 'st_x_' 'sx_y_' 'ab' 'a' 's' 'ppe_db_tables'; do
  stg="$(compute_staging_prefix "$prod")"
  # Core guarantee of the ticket: staging prefix is never longer than production.
  assert_le "len($prod) ok: |$stg| <= |$prod|" "${#stg}" "${#prod}"
  # Staging and production prefixes must differ, or they would collide in the shared database.
  assert_ne "distinct: $prod vs $stg" "$prod" "$stg"
done

echo "== the actual overflow this fixes (64-char MySQL identifier limit) =="
# DEVSUP-137909 / abilitynowbayarea: a valid production table whose staging name overflowed.
longtable='woocommerce_downloadable_product_permissions'   # 44 chars
prod='wp_5w8a7w6n2r_'                                      # 14 chars
old_staging="staging_${prod}"                              # 22 chars (the old prepend)
new_staging="$(compute_staging_prefix "$prod")"            # 14 chars (equal-length)
assert_le "production table is valid" "$(( ${#prod} + ${#longtable} ))" 64
# Prove the bug existed under the old scheme (this is why we are changing it).
if [ "$(( ${#old_staging} + ${#longtable} ))" -gt 64 ]; then
  ok "old prepend scheme overflowed ($(( ${#old_staging} + ${#longtable} )) > 64) -- the bug"
else
  no "old prepend scheme overflowed" "expected > 64"
fi
# Prove the new scheme does not.
assert_le "new equal-length scheme fits ($(( ${#new_staging} + ${#longtable} )) <= 64)" \
  "$(( ${#new_staging} + ${#longtable} ))" 64

echo "== read_staging_prefix (ground truth from staging wp-config) =="
TMP_STAGING="$(mktemp -d)"
DB_PREFIX='wp_5w8a7w6n2r_'   # used only for the fallback branch
STAGING_DIR="$TMP_STAGING"

# New-scheme staging site.
printf "%s\n" "<?php" "\$table_prefix = 'st_5w8a7w6n2r_';" > "$TMP_STAGING/wp-config.php"
assert_eq "reads new-scheme prefix" "st_5w8a7w6n2r_" "$(read_staging_prefix)"

# Legacy staging site created with the old prepend.
printf "%s\n" "<?php" "\$table_prefix = 'staging_wp_5w8a7w6n2r_';" > "$TMP_STAGING/wp-config.php"
assert_eq "reads legacy prefix (backward compat)" "staging_wp_5w8a7w6n2r_" "$(read_staging_prefix)"

# No wp-config: fall back to the legacy scheme so old behavior is preserved.
rm -f "$TMP_STAGING/wp-config.php"
assert_eq "falls back when wp-config missing" "staging_wp_5w8a7w6n2r_" "$(read_staging_prefix)"
rm -rf "$TMP_STAGING"

echo "== rewrite_dump_prefixes (schema dump: production -> staging, and reverse) =="
prod='wp_5w8a7w6n2r_'
stg="$(compute_staging_prefix "$prod")"   # st_5w8a7w6n2r_
tables="${prod}options,${prod}${longtable}"

DUMP="$(mktemp)"
cat > "$DUMP" <<EOF
/*!40101 SET @saved_cs_client = @@character_set_client */;
DROP TABLE IF EXISTS \`${prod}options\`;
CREATE TABLE \`${prod}options\` (
  \`option_id\` bigint unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (\`option_id\`)
) ENGINE=InnoDB;
DROP TABLE IF EXISTS \`${prod}${longtable}\`;
CREATE TABLE \`${prod}${longtable}\` (
  \`permission_id\` bigint unsigned NOT NULL AUTO_INCREMENT,
  \`wp_user_id\` bigint DEFAULT NULL,
  PRIMARY KEY (\`permission_id\`),
  CONSTRAINT \`fk_perm_order\` FOREIGN KEY (\`permission_id\`) REFERENCES \`${prod}options\` (\`option_id\`)
) ENGINE=InnoDB;
EOF

if rewrite_dump_prefixes "$DUMP" "$prod" "$stg" "staging_" 1 "$tables"; then
  ok "forward rewrite returns success (residual check passed)"
else
  no "forward rewrite returns success" "rewrite_dump_prefixes returned non-zero"
fi
rewritten="$(cat "$DUMP")"
assert_contains "options table rewritten to staging prefix" "$rewritten" "\`${stg}options\`"
assert_contains "long table rewritten to staging prefix" "$rewritten" "\`${stg}${longtable}\`"
# No staging table name may exceed 64 chars (the whole point).
longest_staging_table="${stg}${longtable}"
assert_le "staging table '${longest_staging_table}' within 64" "${#longest_staging_table}" 64
# The DROP/CREATE lines must no longer name any production-prefixed table.
drop_create="$(grep -E '^(DROP TABLE IF EXISTS|CREATE TABLE) ' "$DUMP")"
assert_not_contains "no production table left in DROP/CREATE" "$drop_create" "\`${prod}options\`"
# FK constraint got the staging_ name prefix (namespacing in the shared schema), kept separately.
assert_contains "constraint got staging_ name prefix" "$rewritten" "CONSTRAINT \`staging_fk_perm_order\`"
# wp_user_id is a COLUMN, not a table, and must NOT be rewritten (not in the table list).
assert_contains "column wp_user_id left untouched" "$rewritten" "\`wp_user_id\`"

echo "== rewrite_dump_prefixes reverse (staging -> production), round-trips =="
# Reverse the just-rewritten dump back to production; the table identifiers should match the start.
rev_tables="${stg}options,${stg}${longtable}"
if rewrite_dump_prefixes "$DUMP" "$stg" "$prod" "prod_" 1 "$rev_tables"; then
  ok "reverse rewrite returns success"
else
  no "reverse rewrite returns success" "rewrite_dump_prefixes returned non-zero"
fi
reverted="$(cat "$DUMP")"
assert_contains "options table back to production prefix" "$reverted" "\`${prod}options\`"
assert_contains "long table back to production prefix" "$reverted" "\`${prod}${longtable}\`"
rm -f "$DUMP"

echo "== rewrite_dump_prefixes fail-safe: wrong 'from' prefix must NOT pass =="
# If the source prefix does not match the dump, the rewrite matches nothing and the residual check
# must fail (return non-zero) rather than silently leaving source-named tables to be dropped.
DUMP2="$(mktemp)"
cat > "$DUMP2" <<EOF
DROP TABLE IF EXISTS \`${prod}options\`;
CREATE TABLE \`${prod}options\` (\`id\` int) ENGINE=InnoDB;
EOF
if rewrite_dump_prefixes "$DUMP2" "wrongprefix_" "$stg" "staging_" 1 "${prod}options"; then
  no "wrong-from rewrite should fail" "it returned success, which risks dropping source tables"
else
  ok "wrong-from rewrite fails safe (non-zero)"
fi
rm -f "$DUMP2"

echo "== normalize_fk_names (PR #233 safety net, kept intact) =="
DUMP3="$(mktemp)"
# A constraint name that exceeds 64 chars after prefixing must be truncated to 64.
longfk='fk_wp_formgent_google_spreadsheet_queues_google_spreadsheet_id_ref_extra'
cat > "$DUMP3" <<EOF
CREATE TABLE \`st_5w8a7w6n2r_t\` (
  \`id\` int,
  CONSTRAINT \`${longfk}\` FOREIGN KEY (\`id\`) REFERENCES \`st_5w8a7w6n2r_p\` (\`id\`)
) ENGINE=InnoDB;
EOF
if normalize_fk_names "$DUMP3" "test" "staging_"; then
  ok "normalize_fk_names returns success"
else
  no "normalize_fk_names returns success" "returned non-zero"
fi
# Extract the resulting constraint name and assert it is within 64 chars.
newfk="$(grep -oE 'CONSTRAINT `[^`]+`' "$DUMP3" | head -n1 | sed -E 's/CONSTRAINT `([^`]+)`/\1/')"
assert_le "constraint name truncated to <= 64 (got ${#newfk})" "${#newfk}" 64
rm -f "$DUMP3"

echo
echo "-------------------------------------"
echo "PASS: $PASS   FAIL: $FAIL"
rm -f "$LOG_FILE"
[ "$FAIL" -eq 0 ]
