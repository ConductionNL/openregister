#!/bin/bash
# composer install for cloud sessions, where the proxy refuses api.github.com zipballs of repositories that
# are not attached to the session.
#   bash bin/composer-cloud.sh <repo checkout>
# 1. A vendor built before for the same app and composer.lock (~/w/vendor-<app>-<lockhash>) is copied in.
#    Composer's autoloader uses paths relative to vendor/, so a copy works in any checkout; nothing reinstalls.
# 2. Otherwise every GitHub zipball in composer.lock is rebuilt from git (`git fetch --depth 1` + `git archive`,
#    8 in parallel, cached in ~/w/zips), an uncommitted copy of the lock points at those zips, and composer
#    installs from it. The copy is removed again; composer.lock itself is never changed.
# 3. The result is saved as ~/w/vendor-<app>-<lockhash> for the next task. Never delete ~/w/vendor-* or ~/w/zips.
# Exit: composer's exit code (0 = installed).
set -u
repo=$(cd "${1:-.}" && pwd) || exit 2
W=${CLOUD_CACHE:-$HOME/w}
ZIPS=$W/zips
mkdir -p "$ZIPS"
cd "$repo" || exit 2
[ -f composer.lock ] || { echo "composer-cloud: no composer.lock in $repo"; exit 2; }
app=$(basename "$(git config --get remote.origin.url 2>/dev/null || echo "$repo")" .git)
hash=$(sha1sum composer.lock | cut -c1-12)
cache=$W/vendor-$app-$hash
if [ -d "$cache" ]; then
	rm -rf vendor && cp -a "$cache" vendor && echo "composer-cloud: vendor reused from $cache" && exit 0
fi

# Rebuild the GitHub zipballs from git.
python3 - "$ZIPS" <<'PY' > "$W/.zips-$hash.todo"
import json, re, sys
lock = json.load(open("composer.lock"))
for p in lock.get("packages", []) + lock.get("packages-dev", []):
    d = p.get("dist") or {}
    m = re.match(r"https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{7,40})$", d.get("url", ""))
    if m:
        print(m.group(1), m.group(2), m.group(3))
PY
build() { # owner repo ref
	local out="$ZIPS/$1-$2-$3.zip" tmp
	[ -s "$out" ] && return 0
	tmp=$(mktemp -d "$ZIPS/.b-XXXXXX")
	git -C "$tmp" init -q && git -C "$tmp" fetch -q --depth 1 "https://github.com/$1/$2" "$3" 2>/dev/null \
		&& git -C "$tmp" archive --format=zip --prefix="$2/" -o "$out.part" FETCH_HEAD && mv "$out.part" "$out"
	rm -rf "$tmp"
	[ -s "$out" ] || echo "composer-cloud: could not rebuild $1/$2@$3, composer will try it itself" >&2
}
export -f build; export ZIPS
xargs -P 8 -L 1 bash -c 'build "$@"' _ < "$W/.zips-$hash.todo"
rm -f "$W/.zips-$hash.todo"

# An uncommitted copy of composer.json and composer.lock whose dist urls point at the local zips.
python3 - "$ZIPS" <<'PY'
import json, os, re, sys
zips = sys.argv[1]
lock = json.load(open("composer.lock"))
for p in lock.get("packages", []) + lock.get("packages-dev", []):
    d = p.get("dist") or {}
    m = re.match(r"https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{7,40})$", d.get("url", ""))
    if m:
        z = os.path.join(zips, f"{m.group(1)}-{m.group(2)}-{m.group(3)}.zip")
        if os.path.getsize(z) if os.path.exists(z) else 0:
            d["url"], d["shasum"] = "file://" + z, ""
json.dump(lock, open("composer.cloud.lock", "w"), indent=4)
open("composer.cloud.json", "w").write(open("composer.json").read())
PY
grep -qxF composer.cloud.json .git/info/exclude 2>/dev/null || printf 'composer.cloud.json\ncomposer.cloud.lock\n' >> .git/info/exclude
env -u GH_TOKEN -u GITHUB_TOKEN COMPOSER=composer.cloud.json COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 \
	composer install --no-interaction --prefer-dist --no-progress
rc=$?
rm -f composer.cloud.json composer.cloud.lock
if [ "$rc" = 0 ]; then
	rm -rf "$cache.part" && cp -a vendor "$cache.part" && mv "$cache.part" "$cache" && echo "composer-cloud: vendor saved as $cache"
fi
exit "$rc"
