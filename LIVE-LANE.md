# LIVE-LANE.md: the brief for a cloud LIVE routine (coordinator, 25 Nov 2026 12:00 CET; Ruben: lanes start their own Nextcloud)

FIRST STEP, BEFORE ANY LEASE (coordinator, 25 Nov 2026 12:15 CET): your session can only push to the repo it was started for. The state branch lives in ConductionNL/openregister, so attach it first: call the tool mcp__Claude_Code_Remote__add_repo with owner ConductionNL, repo openregister, access push (load it with ToolSearch if needed). Without it every lease and state write is refused, and lease_acquire used to retry that silently for ~10 minutes; it now returns 4 and prints 'STATE PUSH REFUSED'.

Why this lane exists: builders and helpers end idle because the open tasks left need a running Nextcloud (live checks, Newman, Playwright, screenshots, speed measurements). You bring one up inside your own sandbox and do exactly those tasks. Decide everything yourself; never ask.

You are live lane K for APP (both named in your routine prompt; repo ConductionNL/APP, base development). Read ~/q/BUILDER-LANE.md first: SAVE OFTEN, CI, Verify before merging and Standing rules apply to you. Skip MERGE FIRST; only process your own PRs. COORDINATOR AWAY and NEVER IDLE (HELPER-LANE.md) apply too.

## Leases
- From ~/q: `source bin/state.sh; lease_acquire live-APP-K 90`. Lost push races: same retry rule as HELPER-LANE.md (pull, read the lease, retry up to 6 times, 20-60 s apart; end only when ANOTHER holder's `expires_epoch` is in the future). Renew `lease_renew live-APP-K 90` after every task, release at the end.
- Per change: `lease_acquire live-APP-<change> 240`. Helpers and builders hold `help-APP-<change>` / their own branches for code tasks; you only take the live-only tasks of a change, so a change with a help lease is fine as long as you do not touch its code tasks. Branch `cloud/live-APP-<change>`.

## 1. Bring up Nextcloud (about 10 minutes, once per run)
1. PHP: `php -v` (8.3 or 8.4). Install missing modules with apt: `php-sqlite3 php-gd php-zip php-intl php-mbstring php-xml php-curl php-bcmath php-gmp`. Node 20+ and composer must be present; install them if not.
2. Server: `curl -sL https://download.nextcloud.com/server/releases/latest-34.tar.bz2 | tar xj -C ~` gives ~/nextcloud. Then
   `cd ~/nextcloud && php occ maintenance:install --database sqlite --admin-user admin --admin-pass admin`
   `php occ config:system:set trusted_domains 1 --value=localhost:8080; php occ config:system:set appstoreenabled --value=false --type=boolean` (appstore off BEFORE any app install or upgrade).
3. OpenRegister from main: `git clone -q --depth 1 https://github.com/ConductionNL/openregister ~/nextcloud/apps/openregister`, then in it `composer install --no-dev -q` (if composer refuses the platform PHP version: `bash ~/q/bin/composer-cloud.sh ~/nextcloud/apps/openregister`), `npm ci && npm run build`. `php occ app:enable openregister`.
4. APP from development the same way into ~/nextcloud/apps/APP (composer, npm ci, npm run build), `php occ app:enable APP`. Dependencies named in APP's info.xml or README (another fleet app, nextcloud-vue is an npm package and needs nothing): install them the same way from their development branch.
5. Serve: `cd ~/nextcloud && PHP_CLI_SERVER_WORKERS=8 nohup php -S localhost:8080 ~/q/bin/php-s-router.php > ~/ncserve.log 2>&1 &` (the router makes pretty URLs such as /apps/files/ work; without it they 404 and read as app reds). Also run `php occ app:disable firstrunwizard` (run_in_background; note the PID, kill by PID only, never pkill). Check `curl -s -o /dev/null -w '%{http_code}' localhost:8080/status.php` says 200. Run `php occ background:cron` and `php cron.php` once.
6. Seed: APP's own demo/seed command or repair step if it has one (look in appinfo/info.xml `<repair-steps>`, lib/Command, the Newman collection's setup). Users the tests expect: create them with `OC_PASS=... php occ user:add --password-from-env`.
If a step fails after two real attempts, write one line in builders-idle.md (`<UTC> live-APP-K setup failed at step N: <error>`) and switch to NEVER IDLE work instead of ending.

## 2. Pick live work for APP, in this order
1. e2e-red/APP-2026-11-25.txt (launchpad and thematiq) and the "Still red" list in FINISH-ORDER.md LIVE PASS: run the failing spec against your instance, fix the spec to the board or the app to the spec (never the board to the test), one PR per spec file. Lease `e2e-APP-<spec>`.
2. Unticked live tasks in APP's openspec/changes/*/tasks.md: Newman runs, e2e runs, "verify live", screenshots, seed checks. Run them, attach the result (counts, screenshot paths in the PR, Newman summary) and tick the task in the same PR.
3. Lines in builders-idle.md of the last 24 h that say a task of APP was skipped as live-only: those are your queue too.
4. Speed tasks and SPEED BUDGET checks: php -S with sqlite is not representative, so measure these on a production-like stack in the same sandbox: apt install mariadb-server nginx php-fpm php-mysql php-apcu php-redis redis-server, a second Nextcloud install on MariaDB behind nginx + php-fpm with opcache, APCu local cache and Redis locking (Nextcloud's admin manual nginx config), apps installed as in step 3-4. Numbers from that stack count: tick the task when it is under budget, and write the numbers in the PR with the label "sandbox reference stack (nginx, php-fpm, MariaDB, Redis)". A number over budget is a red to fix, not a reason to wait for another instance.

## 3. Rules for live runs
- Every Playwright run gets its own `--output` folder (two runs sharing one wipe each other's traces and fail with ENOENT, 25 Nov). Install the browser with `npx playwright install --with-deps chromium`. Point the tests at `http://localhost:8080` via the env var the app's playwright config reads (look it up, usually NEXTCLOUD_URL or BASE_URL).
- A red caused by the sandbox (sqlite, php -S, missing external service such as Collabora, mail, LDAP) is not an app red: note it in the PR and move on; do not change the app for it.
- Never commit anything from ~/nextcloud, logs with tokens, or screenshots of other people's data. PR bodies hold no local paths.
- Merge your own PR on local green per BUILDER-LANE (diff check plus the tests you touched).

Work about 4 hours, then release your leases and stop. Final message: live tasks done (PR numbers, counts), tasks still red with why, setup problems.
