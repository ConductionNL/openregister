#!/bin/bash
# cm_tree.sh: build and unit-test a merge tree of one fleet app in a cloud session, step by step (each step fits
# one foreground Bash call). The same steps as the local merge runners (tree.sh + cull.sh), so the same verdict.
#   bash bin/cm_tree.sh <step> <app> <workdir> [<n@sha,...>]
# Steps, in order:
#   prep   clone <app> on origin/development into <workdir>/<app>, vendor via composer-cloud.sh, openregister
#          main beside it for dossiq and hermiq
#   base   unit suite on development: <workdir>/logs/<app>.base.{out,fail}, base.sha
#   build  merge the PRs (n@sha in tree order) one by one: <workdir>/logs/<app>.tree (n sha STATUS reason);
#          STATUS IN, CONFLICT, LINT, MOVED, DONE (already on development) or EXCL (never merged)
#   tree   unit suite on the tree, then the new failures (tree minus baseline) in <app>.new.fail
#   cull   only when new.fail is non-empty: replay the IN PRs one by one on the baseline, running only the new
#          failing tests; a PR after which one fails is RED and dropped; then rebuild the tree from the rest and
#          run the tree suite again. Exit 0 when the rebuilt tree adds no failures.
#   status print the tree file and the suite summary lines
# Development only; never openregister. Suite timeout per step: SUITE_TIMEOUT (default 570 s): a step that
# times out prints TIMEOUT and the tree is not landed.
set -u
step=$1 app=$2 W=$3 prs=${4:-}
BIN=$(cd "$(dirname "$0")" && pwd)
C=$W/$app LOG=$W/logs
mkdir -p "$LOG"
[ "$app" = openregister ] && { echo "openregister is never merged here"; exit 2; }
case " zaakafhandelapp Softwarecatalogus " in *" $app "*) echo "never touched: $app"; exit 2 ;; esac
EXCL=" launchpad:1106 launchpad:1107 launchpad:1109 dossiq:6203 "
g() { git -C "$C" "$@"; }
cfg() {
	local c=phpunit-unit.xml
	[ -f "$C/$c" ] || c=phpunit.xml
	[ -f "$C/tests/phpunit-unit-only.xml" ] && c=tests/phpunit-unit-only.xml
	echo "$c"
}
orenv() {
	[ "$app" = hermiq ] && export HERMIQ_OPENREGISTER_PATH=$W/openregister
	return 0
}
# vendor/nextcloud/ocp has no autoload entry in some apps: an autoloader for OCP\ and NCU\ onto it.
prepend() {
	[ -d "$C/vendor/nextcloud/ocp" ] || return 0
	cat > "$W/ocp-autoload.php" <<'PHP'
<?php
spl_autoload_register(static function (string $c): void {
	foreach (['OCP\\' => 'OCP/', 'NCU\\' => 'NCU/'] as $p => $d) {
		if (strncmp($c, $p, strlen($p)) === 0) {
			$f = getenv('CM_OCP_DIR') . '/' . $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
			if (is_file($f)) {
				require $f;
			}
			return;
		}
	}
});
PHP
	export CM_OCP_DIR=$C/vendor/nextcloud/ocp
	echo "-d auto_prepend_file=$W/ocp-autoload.php"
}
suite() { # suite <name> [filter]: write failing test ids to $LOG/$app.<name>.fail
	local name=$1 filter=${2:-} pre rc
	orenv; pre=$(prepend)
	(cd "$C" && timeout "${SUITE_TIMEOUT:-570}" php -d memory_limit=4G $pre vendor/bin/phpunit -c "$(cfg)" --no-coverage \
		${filter:+--filter "$filter"} > "$LOG/$app.$name.out" 2>&1); rc=$?
	[ "$rc" = 124 ] && echo "TIMEOUT after ${SUITE_TIMEOUT:-570}s" >> "$LOG/$app.$name.out"
	grep -E '^[0-9]+\) ' "$LOG/$app.$name.out" | sed -E 's/^[0-9]+\) //; s/ with data set.*//' | sort -u > "$LOG/$app.$name.fail"
	grep -E '^(OK|Tests:|FAILURES|ERRORS|TIMEOUT)' "$LOG/$app.$name.out" | tail -2 | tr '\n' ' '
	echo
}
ok() { grep -qE '^(OK|Tests:)' "$LOG/$app.$1.out" 2>/dev/null && ! grep -q '^TIMEOUT' "$LOG/$app.$1.out"; }

build() { # build <n@sha,...>: on origin/development, writes $LOG/$app.tree
	local item n sha h files alone bad f
	g merge --abort 2>/dev/null
	g checkout -q -f -B tree "$(cat "$LOG/$app.base.sha")" || return 2
	g clean -qfd -e vendor -e node_modules -e vendor-bin
	: > "$LOG/$app.tree"
	for item in ${1//,/ }; do
		n=${item%@*} sha=${item#*@}
		case "$EXCL" in *" $app:$n "*) printf '%s\t%s\tEXCL\tnever merged\n' "$n" "$sha" >> "$LOG/$app.tree"; continue ;; esac
		g fetch -q origin "+refs/pull/$n/head:refs/pr/$n" || { printf '%s\t%s\tMOVED\tfetch failed\n' "$n" "$sha" >> "$LOG/$app.tree"; continue; }
		h=$(g rev-parse "refs/pr/$n")
		[ "$h" = "$sha" ] || { printf '%s\t%s\tMOVED\thead is %s\n' "$n" "$sha" "$h" >> "$LOG/$app.tree"; continue; }
		if g merge-base --is-ancestor "$sha" "$(cat "$LOG/$app.base.sha")"; then
			printf '%s\t%s\tDONE\talready on development\n' "$n" "$sha" >> "$LOG/$app.tree"; continue
		fi
		if ! g -c user.name=cloudmerge -c user.email=cloudmerge@localhost merge -q --no-ff --no-edit "refs/pr/$n" >/dev/null 2>&1; then
			files=$(g diff --name-only --diff-filter=U | tr '\n' ' ' | cut -c1-200)
			g merge --abort
			alone=$(g merge-tree --write-tree "$(cat "$LOG/$app.base.sha")" "refs/pr/$n" >/dev/null 2>&1 && echo "merges clean on development alone" || echo "conflicts with development")
			printf '%s\t%s\tCONFLICT\t%s; files: %s\n' "$n" "$sha" "$alone" "$files" >> "$LOG/$app.tree"
			continue
		fi
		bad=""
		for f in $(g diff --name-only --diff-filter=AM HEAD^1 HEAD -- '*.php'); do
			php -l "$C/$f" >/dev/null 2>&1 || bad="$bad $f"
		done
		if [ -n "$bad" ]; then
			g reset -q --hard HEAD^1
			printf '%s\t%s\tLINT\tphp -l fails:%s\n' "$n" "$sha" "$bad" >> "$LOG/$app.tree"
			continue
		fi
		printf '%s\t%s\tIN\t\n' "$n" "$sha" >> "$LOG/$app.tree"
	done
	echo "tree: $(grep -c $'\tIN\t' "$LOG/$app.tree") IN; $(grep -v $'\tIN\t' "$LOG/$app.tree" | cut -f1,3 | tr '\t\n' ': ')"
}

case $step in
prep)
	if [ ! -d "$C/.git" ]; then
		git clone -q --branch development "https://github.com/ConductionNL/$app" "$C" || exit 2
	fi
	g fetch -q origin development || exit 2
	g merge --abort 2>/dev/null
	g checkout -q -f -B tree origin/development || exit 2
	g clean -qfd -e vendor -e node_modules -e vendor-bin
	bash "$BIN/composer-cloud.sh" "$C" | tail -2 || echo "composer-cloud failed" >&2
	if [ -f "$C/vendor-bin/phpunit/composer.json" ] && [ ! -d "$C/vendor-bin/phpunit/vendor" ]; then
		(cd "$C" && COMPOSER_PROCESS_TIMEOUT=0 composer bin phpunit install -q --no-interaction --no-progress) || echo "composer bin phpunit install failed" >&2
	fi
	if [ "$app" = dossiq ] || [ "$app" = hermiq ]; then
		O=$W/openregister
		[ -d "$O/.git" ] || git clone -q --depth 1 --branch main https://github.com/ConductionNL/openregister "$O"
		git -C "$O" fetch -q --depth 1 origin main && git -C "$O" checkout -q -f -B main FETCH_HEAD
		if [ "$app" = dossiq ] && [ ! -d "$O/vendor" ]; then bash "$BIN/composer-cloud.sh" "$O" | tail -1; fi
	fi
	[ -x "$C/vendor/bin/phpunit" ] || [ -f "$C/vendor/bin/phpunit" ] || { echo "prep: no vendor/bin/phpunit"; exit 2; }
	echo "prep: $app at $(g rev-parse --short origin/development)"
	;;
base)
	dev=$(g rev-parse origin/development)
	g merge --abort 2>/dev/null
	g checkout -q -f -B tree "$dev" && g clean -qfd -e vendor -e node_modules -e vendor-bin
	echo -n "baseline $dev: "; suite base
	echo "$dev" > "$LOG/$app.base.sha"
	ok base || { echo "base: the suite did not run to a summary"; exit 2; }
	;;
build)
	[ -n "$prs" ] || { echo "build: no PR list"; exit 2; }
	[ -s "$LOG/$app.base.sha" ] || { echo "build: run base first"; exit 2; }
	build "$prs"
	;;
tree)
	echo -n "tree ($(grep -c $'\tIN\t' "$LOG/$app.tree") PRs): "; suite tree
	ok tree || { echo "tree: the suite did not run to a summary"; echo "the tree suite did not finish" > "$LOG/$app.new.fail"; exit 2; }
	comm -13 <(sort -u "$LOG/$app.base.fail") <(sort -u "$LOG/$app.tree.fail") > "$LOG/$app.new.fail"
	echo "new failures: $(wc -l < "$LOG/$app.new.fail")"; cat "$LOG/$app.new.fail"
	;;
cull)
	[ -s "$LOG/$app.new.fail" ] || { echo "cull: no new failures"; exit 0; }
	base=$(cat "$LOG/$app.base.sha")
	cp "$LOG/$app.tree" "$LOG/$app.tree.precull"
	filter="/($(sed -E 's/.*\\//; s/[][(){}.*+?^$|]/\\&/g' "$LOG/$app.new.fail" | paste -sd'|'))/"
	probe() {
		suite cull "$filter" >/dev/null
		comm -12 <(sort -u "$LOG/$app.cull.fail") <(sort -u "$LOG/$app.new.fail")
	}
	g merge --abort 2>/dev/null
	g checkout -q -f -B cull "$base" || exit 1
	g clean -qfd -e vendor -e node_modules -e vendor-bin
	pre=$(probe)
	[ -n "$pre" ] && { echo "cull: fails on the baseline already: $pre"; exit 1; }
	red=() keep=()
	while IFS=$'\t' read -r n sha st why; do
		[ "$st" = IN ] || continue
		if ! g -c user.name=cloudmerge -c user.email=cloudmerge@localhost merge -q --no-ff --no-edit "$sha" >/dev/null 2>&1; then
			g merge --abort; echo "cull: $n does not merge in replay order; kept for the rebuild"; keep+=("$n@$sha"); continue
		fi
		hit=$(probe)
		if [ -n "$hit" ]; then
			g reset -q --hard HEAD^1
			red+=("$n"$'\t'"$sha"$'\t'RED$'\t'"adds unit failure(s) with the PRs before it in the tree: $(printf '%s' "$hit" | sed 's/.*\\//' | paste -sd' ' | cut -c1-200)")
			echo "cull: $n RED: $hit"
		else
			keep+=("$n@$sha")
		fi
	done < "$LOG/$app.tree"
	[ ${#red[@]} = 0 ] && { echo "cull: no PR reproduces the failures; the tree is not landed"; exit 1; }
	if [ ${#keep[@]} = 0 ]; then
		{ grep -v $'\tIN\t' "$LOG/$app.tree.precull"; printf '%s\n' "${red[@]}"; } > "$LOG/$app.tree"
		: > "$LOG/$app.new.fail"; cp "$LOG/$app.base.out" "$LOG/$app.tree.out"
		echo "cull: every IN PR is RED"; exit 0
	fi
	build "$(IFS=,; echo "${keep[*]}")" >/dev/null
	{ grep -v $'\tIN\t' "$LOG/$app.tree.precull"; printf '%s\n' "${red[@]}"; } >> "$LOG/$app.tree"
	echo "cull: rebuilt tree of ${#keep[@]} PRs, ${#red[@]} RED; now run: cm_tree.sh tree $app $W"
	;;
status)
	cat "$LOG/$app.tree" 2>/dev/null
	for s in base tree; do echo "$s: $(grep -E '^(OK|Tests:|FAILURES|ERRORS|TIMEOUT)' "$LOG/$app.$s.out" 2>/dev/null | tail -2 | tr '\n' ' ')"; done
	echo "new failures: $(wc -l < "$LOG/$app.new.fail" 2>/dev/null)"
	;;
*) echo "unknown step $step"; exit 2 ;;
esac
