# CI Step Categorisation Guide

Reference for Step 4c of `create-pr`. Categorises CI `run:` steps into Install / Check / Docker check / Skip groups and builds the execution plan.

---

## Categorise Steps and Build Execution Plan

From the full list of CI `run:` steps, categorise each as:

- **Install** — dependency install commands that must be run first:
  - `composer install`, `composer ci`, `npm ci`, `npm install`, `pip install`, etc.
  - Note the **exact flags** the CI uses (e.g. `--no-interaction`, `--legacy-peer-deps`, `--frozen-lockfile`)
- **Check** — quality/lint/test commands that run against source files only (no live server needed):
  - phpcs, phpstan, psalm, phpmd, eslint, stylelint, pytest, ruff, mypy, etc.
  - Use the **exact command** from the workflow, adapted to run from `{CHECK_DIR}`
- **Docker check** — commands from jobs that require a running Nextcloud server (see detection rule below)
- **Skip** — steps that cannot run locally at all (cloud infrastructure, upload/deploy, CI secrets/runners)

---

## Handling jobs that mix runnable and skippable steps

Some CI jobs combine steps that are locally runnable (e.g. `npm audit`) with steps that are CI-only (e.g. generating and committing SBOM files, uploading artifacts, installing Grype). **Do not skip the entire job** — extract the individually runnable steps and classify them as Check steps.

The key pattern to watch for: a job that generates artifact files (CycloneDX SBOM, coverage reports, etc.) but **also contains audit or quality commands**. Extract those commands as Check steps with their **exact flags from that job** — not the flags from a different job.

**Critical example:** the SBOM job runs `npm audit --audit-level=critical` (no `--omit=dev`), while the Security job runs `npm audit --audit-level=critical --omit=dev`. These are different commands producing different results. Always use the exact flags from the job where the step appears.

Steps to skip within an otherwise-mixed job:
- Any step that installs/runs Grype, Trivy, or other CVE scanners (requires network + CI credentials)
- Any step that generates SBOM files (CycloneDX npm/composer commands)
- Any step that commits files back to the repo (`git commit`, `git push` in CI)
- Any step that uploads/attaches artifacts (`actions/upload-artifact`, `softprops/action-gh-release`)

Steps to extract and run:
- `composer audit ...` — run locally with the exact flags from the job
- `npm audit ...` — run locally with the exact flags from the job (pay attention to `--omit=dev` or lack thereof)

---

## Detecting "requires Nextcloud" jobs

**Do not hardcode tool names.** Instead, read each CI job and ask: does it set up a live Nextcloud server before running tests? The signals are job steps that contain any of:
- `nextcloud/server` checkout or `git submodule update --init 3rdparty`
- `php occ maintenance:install` or `php occ app-enable`
- `docker-compose up` or `docker run` targeting a Nextcloud image
- Any `nextcloud-test-refs` matrix variable being used

If a job has these signals, **every `run:` step in that job that executes test/check commands** is a Docker check — regardless of the tool name. This covers phpunit, newman, and any future tools added to such a job.

---

## Running Docker checks locally

For any job classified as "requires Nextcloud", check the environment once before running any of its steps:

**1. Check if the Nextcloud container is running:**
```bash
NC_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i nextcloud | head -1)
echo "Container: $NC_CONTAINER"
```

**2. Check the app is mounted inside it:**
```bash
docker exec "$NC_CONTAINER" ls /var/www/html/apps-extra/{APP_DIR}/vendor/bin/ 2>/dev/null | head -3
```

**If the container is running and the app is mounted:**

Adapt each test command from that job to run via `docker exec` (for server-side commands) or against `http://nextcloud.local` (for HTTP/API commands). Specific adaptations:

- **PHPUnit** (runs inside server):
  ```bash
  docker exec -w /var/www/html/apps-extra/{APP_DIR} -e XDEBUG_MODE=coverage "$NC_CONTAINER" \
    ./vendor/bin/phpunit -c phpunit-unit.xml --colors=always
  ```
  Note: Use `phpunit-unit.xml` locally (unit tests only, fast). CI uses `phpunit.xml` + coverage. The `XDEBUG_MODE=coverage` env var is required — without it phpunit emits a runner warning that causes a non-zero exit code even when all tests pass.

- **Newman / HTTP integration tests** (calls the server over HTTP):
  ```bash
  npx newman run tests/integration/*.postman_collection.json \
    --env-var base_url=http://nextcloud.local \
    --env-var admin_user=admin \
    --env-var admin_password=admin
  ```
  Note: Use `base_url`, `admin_user`, `admin_password` — these are the exact variable names the CI workflow passes (CI uses `http://localhost:8080` via PHP built-in server; locally use `http://nextcloud.local`). Using different names causes Newman to silently fall back to the collection's hardcoded defaults, making tests pass locally but fail in CI.

- **Any other command from a Nextcloud-server job**: run via `docker exec -w /var/www/html/apps-extra/{APP_DIR} "$NC_CONTAINER" {command}` unless the command clearly makes HTTP requests, in which case substitute `http://nextcloud.local` as the base URL.

**If no Nextcloud container is running, or the app is not mounted:**

Ask using AskUserQuestion:
**"Some CI checks require the Nextcloud Docker environment, which is not running. What would you like to do?"**
- **Start Docker first** — stop here; remind user to start the environment (e.g. `cd openregister && docker compose up -d`), then re-run the skill
- **Skip Docker checks** — continue without them; note in the PR which jobs were not run locally

---

## Execution plan display

**Lock file gate (before install):** Check that lock files expected by the install commands are committed:
- CI uses `npm ci` → `package-lock.json` must be committed and up-to-date
- CI uses `composer install` → `composer.lock` should be committed

If a required lock file is missing or not committed, stop:
> "⚠️ `{lockfile}` is required by the CI install step (`{command}`) but is not committed. Run `{generate-command}`, commit the file, then re-run."

Display the full execution plan to the user before running anything:
```
Execution plan derived from CI workflows:

  Install steps:
    1. {exact install command from CI}
    2. {exact install command from CI}

  Check steps:
    3. {exact check command from CI}   [{job name}]
    4. {exact check command from CI}   [{job name}]
    ...

  Docker check steps (require Nextcloud container — {NC_CONTAINER}):
    N. docker exec ... {command}   [{job name}]
    N. npx newman run ...          [{job name}]

  Skipped (cannot run locally):
    - {step description}
```
