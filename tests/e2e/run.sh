#!/usr/bin/env bash
#
# Migrates a wallabag 2.6 installation to 2.7, updates it and rolls it back
# with wallabin, and checks that the data is kept at each step.
#
# Usage: tests/e2e/run.sh <wallabag 2.6 package> <wallabag 2.7 package>
#
# Environment variables:
#   DATABASE     sqlite (default), mysql or pgsql
#   DB_HOST      127.0.0.1 by default
#   DB_PORT      3306 for mysql, 5432 for pgsql by default
#   DB_USER      root for mysql, postgres for pgsql by default
#   DB_PASSWORD  empty by default
#   PHP          php by default
#   WALLABIN     command to run wallabin, bin/wallabin of this repository by default

set -euo pipefail

PACKAGE_26=$(realpath "$1")
PACKAGE_27=$(realpath "$2")
DATABASE=${DATABASE:-sqlite}
PHP=${PHP:-php}
WALLABIN=${WALLABIN:-"$PHP $(cd "$(dirname "$0")/../.." && pwd)/bin/wallabin"}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PASSWORD=${DB_PASSWORD:-}
WORK=$(mktemp -d)
WALLABAG=$WORK/wallabag
RELEASES=$WORK/releases.json
PORT=8099

case $DATABASE in
    sqlite) DRIVER=pdo_sqlite SCHEME=sqlite DB_PORT=null DB_USER=null CHARSET=utf8 ;;
    mysql) DRIVER=pdo_mysql SCHEME=mysql DB_PORT=${DB_PORT:-3306} DB_USER=${DB_USER:-root} CHARSET=utf8mb4 ;;
    pgsql) DRIVER=pdo_pgsql SCHEME=postgresql DB_PORT=${DB_PORT:-5432} DB_USER=${DB_USER:-postgres} CHARSET=utf8 ;;
    *) echo "Unknown database $DATABASE" >&2; exit 1 ;;
esac

step() { printf '\n== %s\n' "$*"; }
fail() { printf '\nFAILED: %s\n' "$*" >&2; exit 1; }
console() { (cd "$WALLABAG" && $PHP -d display_errors=0 bin/console --env=prod --no-interaction "$@"); }
wallabin() { $WALLABIN "$@" --dir="$WALLABAG"; }

assert_version() {
    grep -qE "version: $1\$" "$WALLABAG/app/config/wallabag.yml" || fail "wallabag $1 expected, found $(grep -E 'version:' "$WALLABAG/app/config/wallabag.yml")"
}

assert_data() {
    console dbal:run-sql "SELECT label FROM wallabag_tag WHERE label = 'e2e-tag'" | grep -q e2e-tag || fail 'the tag is lost'
    console dbal:run-sql "SELECT username FROM wallabag_user WHERE username = 'wallabag'" | grep -q wallabag || fail 'the user is lost'
}

assert_login_page() {
    (cd "$WALLABAG/web" && exec $PHP -d display_errors=0 -S "127.0.0.1:$PORT" index.php >/dev/null 2>&1) &
    local server=$! page=''

    for _ in $(seq 1 30); do
        page=$(curl -sf "http://127.0.0.1:$PORT/login" || true)
        [ -n "$page" ] && break
        sleep 1
    done

    kill "$server"
    wait "$server" 2>/dev/null || true
    grep -q '_csrf_token' <<< "$page" || fail 'the login page does not work'
}

package() {
    local version=$1 directory=$WORK/package-$1

    mkdir "$directory"
    tar -xzf "$PACKAGE_27" -C "$directory"
    mv "$directory"/* "$directory/wallabag-$version"
    sed -i.orig -E "s/(wallabag\.version:).*/\1 $version/" "$directory/wallabag-$version/app/config/wallabag.yml"
    rm "$directory/wallabag-$version/app/config/wallabag.yml.orig"
    tar -czf "$WORK/wallabag-$version.tar.gz" -C "$directory" "wallabag-$version"
    rm -rf "$directory"
}

releases() {
    $PHP -r '
        $releases = [];
        foreach (array_slice($argv, 2) as $version) {
            $file = $argv[1] . "/wallabag-" . $version . ".tar.gz";
            $releases[] = ["version" => $version, "date" => "2027-01-01", "prerelease" => false, "security" => false, "php_min" => "8.2", "migrations" => "2.7.0" === $version, "release_url" => "https://github.com/wallabag/wallabag/releases/tag/" . $version, "tarball_url" => "file://" . $file, "sha256" => hash_file("sha256", $file), "notes_url" => null];
        }
        file_put_contents($argv[1] . "/releases.json", json_encode(["releases" => $releases]));
    ' "$WORK" "$@"
}

trap 'rm -rf "$WORK"' EXIT

step "Prepare the 2.7.0 and 2.7.1 packages"
package 2.7.0
package 2.7.1
releases 2.7.0

step "Install wallabag 2.6 with $DATABASE"
tar -xzf "$PACKAGE_26" -C "$WORK"
mv "$WORK"/wallabag-2.6.* "$WALLABAG"
sed -i.orig -E \
    -e "s/^( *database_driver:).*/\1 $DRIVER/" \
    -e "s/^( *database_host:).*/\1 $DB_HOST/" \
    -e "s/^( *database_port:).*/\1 $DB_PORT/" \
    -e "s/^( *database_name:).*/\1 wallabag_e2e/" \
    -e "s/^( *database_user:).*/\1 $DB_USER/" \
    -e "s/^( *database_password:).*/\1 \"$DB_PASSWORD\"/" \
    -e "s/^( *database_charset:).*/\1 $CHARSET/" \
    -e "s/^( *secret:).*/\1 \"e2e\$secret\"/" \
    -e "s#^( *domain_name:).*#\1 http://127.0.0.1:$PORT#" \
    "$WALLABAG/app/config/parameters.yml"
rm "$WALLABAG/app/config/parameters.yml.orig"

if [ sqlite = "$DATABASE" ]; then
    sed -i.orig -E 's#^( *database_path:).*#\1 "%kernel.project_dir%/data/db/wallabag.sqlite"#' "$WALLABAG/app/config/parameters.yml"
    rm "$WALLABAG/app/config/parameters.yml.orig"
fi

if [ sqlite != "$DATABASE" ]; then
    console doctrine:database:drop --force --if-exists >/dev/null
    console doctrine:database:create >/dev/null
fi

console wallabag:install >/dev/null

if [ pgsql = "$DATABASE" ]; then
    console dbal:run-sql "INSERT INTO wallabag_tag (id, label, slug) VALUES (nextval('tag_id_seq'), 'e2e-tag', 'e2e-tag')" >/dev/null
else
    console dbal:run-sql "INSERT INTO wallabag_tag (label, slug) VALUES ('e2e-tag', 'e2e-tag')" >/dev/null
fi
assert_version '2\.6\.[0-9]+'
assert_data

step "Migrate to 2.7.0"
wallabin migrate --releases-url="file://$RELEASES" --yes
assert_version 2.7.0
assert_data
[ ! -e "$WALLABAG/app/config/parameters.yml" ] || fail 'parameters.yml is still there'
grep -q "DATABASE_URL=\"$SCHEME:" "$WALLABAG/.env.local" || fail 'DATABASE_URL is wrong in .env.local'
grep -q 'APP_SECRET="e2e\\$secret"' "$WALLABAG/.env.local" || fail 'APP_SECRET is wrong in .env.local'
assert_login_page

step "Check: up to date"
wallabin check --releases-url="file://$RELEASES"

step "Check: 2.7.1 available"
releases 2.7.0 2.7.1
status=0
wallabin check --releases-url="file://$RELEASES" || status=$?
[ 2 = "$status" ] || fail "check should exit with 2, not $status"

step "Update to 2.7.1"
wallabin update --releases-url="file://$RELEASES" --yes
assert_version 2.7.1
assert_data
[ -d "$WALLABAG/var/wallabin/backup-2.7.0" ] || fail 'no backup of 2.7.0'
assert_login_page

step "Roll back to 2.7.0"
wallabin rollback --yes
assert_version 2.7.0
assert_data
[ ! -e "$WALLABAG/var/wallabin/backup-2.7.0" ] || fail 'the backup is still there'
assert_login_page

step "Roll back again: no backup"
if wallabin rollback --yes; then
    fail 'a second rollback should fail'
fi

printf '\nAll steps passed with %s.\n' "$DATABASE"
