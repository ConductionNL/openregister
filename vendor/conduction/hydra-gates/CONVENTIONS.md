# Conduction Conventions

Cross-app conventions for repositories under the [ConductionNL](https://github.com/ConductionNL) organisation. These are the rules every Conduction Nextcloud app should follow so that org-wide tooling (CI, security, docs, releases) keeps working uniformly.

## Workflows

### Central Quality workflow

There is **one** Quality workflow for the entire org: [`.github/workflows/quality.yml`](./.github/workflows/quality.yml) in this repo (`ConductionNL/.github`). It is a [reusable workflow](https://docs.github.com/en/actions/using-workflows/reusing-workflows) that runs the full quality matrix — PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint, license check, security audit, PHPUnit, Newman, Playwright, SBOM, coverage report.

Every Conduction app **must** consume the central workflow via a thin wrapper. **Do not duplicate quality logic in per-app workflows.**

#### Wrapper convention

| Property | Required value                                                                                                                  |
| -------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Filename | `.github/workflows/code-quality.yml`                                                                                            |
| `uses`   | `ConductionNL/.github/.github/workflows/quality.yml@main`                                                                       |
| Trigger  | `push` to `main` / `development` / `feature/**` / `bugfix/**` / `hotfix/**` + `pull_request` to `main` / `beta` / `development` |
| Inputs   | At minimum `app-name`. Optionally toggle the per-tool `enable-*` flags.                                                         |

Reference template (use as-is, just change `app-name`):

```yaml
name: Code Quality

on:
  push:
    branches: [main, development, feature/**, bugfix/**, hotfix/**]
  pull_request:
    branches: [main, beta, development]
  workflow_dispatch:

jobs:
  quality:
    uses: ConductionNL/.github/.github/workflows/quality.yml@main
    with:
      app-name: <app-id>
      php-version: "8.3"
      enable-psalm: true
      enable-phpstan: true
      enable-phpmetrics: true
      enable-frontend: true
      enable-eslint: true
```

#### Why one workflow, not per-app variants

- **Single source of truth** — improvements (new linter, security gate, dependency scan) ship to every app immediately by updating one file.
- **No drift** — apps can't quietly fall behind on quality coverage.
- **Reviewable in one place** — auditors and clients see one canonical CI definition for the whole platform.

#### Anti-patterns to avoid

- ❌ Per-app `quality-check.yml`, `quality.yml`, `tests.yml`, `lint.yml` files that duplicate central workflow logic. They cause silent divergence and miss central improvements.
- ❌ Inline quality jobs (PHPCS / Psalm / etc.) defined directly in app repos rather than via the central reusable workflow.
- ❌ Renaming the wrapper to anything other than `code-quality.yml`. Filename consistency lets contributors and tools find the wrapper without per-repo guesswork.

#### JS-only repos (`enable-php: false`)

Repos without a `composer.json` (component libraries, themes — e.g. `nextcloud-vue`, `conduction-theme`) use the same wrapper with the PHP toolchain switched off:

```yaml
    with:
      app-name: <repo-name>
      enable-php: false        # skips php-quality, PHPUnit and the composer legs of security/license
      enable-frontend: true
      enable-eslint: true
      node-version: "24"       # optional, defaults to "20"
```

The skipped PHP checks still report (as "skipped"), which satisfies required status checks — so the same org-wide required contexts work for PHP apps and JS-only repos alike.

The mirror image exists too: **PHP-only repos** without a `package.json` (e.g. `openklant`) set `enable-npm: false` (plus `enable-frontend: false`) so the npm legs of security/license skip instead of failing on `npm ci`. Skipped legs satisfy required checks the same way.

#### Frontend build gate (`Frontend Build`) — automatic, no input

`quality / Frontend Build` compiles the repo's frontend on every run. It is **auto-detected and has no opt-in input**, exactly like `Frontend Tests (unit)`: when `enable-frontend` is true and the repo has a `package.json` with a `build` script (plus a `package-lock.json`), it runs `npm ci && npm run build` and **blocks on failure** via the `Quality Report` gate. A repo missing any of those skips cleanly, without even setting up Node.

It exists because `npm run build` previously ran *only* inside the opt-in Playwright and journeydoc jobs. Two of the twenty-one repos calling this workflow enable Playwright and none enable journeydoc capture, so nineteen repos had **never compiled their frontend in CI**. hermiq's build was broken for exactly that reason — a floating `node-polyfill-webpack-plugin: ^4.0.0` resolved to 4.1.0 and produced 25 webpack errors — with every gate green.

The compiled output is uploaded as the `frontend-build-output` artifact (tarred, 1-day retention) and the **Playwright job reuses it instead of rebuilding**. That reuse is a fast path, never a replacement: if the artifact is absent, empty, contains no JavaScript, or the repo sets a non-default `frontend-path`, the Playwright job builds for itself exactly as before. A missing bundle answers HTTP 200 `text/html` rather than 404, so a bad hand-off would show up as selector timeouts across every UI spec — hence the fall-back-on-any-doubt rule.

**Pin your build-critical devDependencies.** A caret on a build plugin means a lockfile regeneration can break the build in a way that used to be invisible and is now blocking.

#### Custom frontend checks (`frontend-checks`)

Repo-specific quality gates (unit tests, build verification, docs coverage, …) run through the `frontend-checks` input — a JSON array of **npm script names**. Each entry becomes its own `quality / Frontend Check (<script>)` job.

```yaml
    with:
      frontend-setup-command: "cd docusaurus && npm ci"   # optional, runs AFTER the root npm ci (never replaces it)
      frontend-checks: '["test", "check:build", "check:docs"]'
```

**Requirements for every script you list:**

1. **It must exist in the repo's `package.json` `scripts`** — the leg fails fast with an explicit annotation (`npm script '<name>' is listed in this repo's frontend-checks input but does not exist in package.json`) before anything is installed.
2. **It must be self-contained.** Every leg is an independent job with a fresh checkout + `npm ci`; nothing from another leg (like `dist/`) is available. Bundle order-dependent steps into one script:
   ```json
   "check:build": "npm run build && npm run check:css-entry"
   ```
   (`check:css-entry` needs `dist/`, so it must live in the same script as the build.)
3. **Multi-command checks become named scripts.** Inline shell sequences can't be expressed in the JSON list — wrap them, e.g.:
   ```json
   "check:docs-fresh": "cd docusaurus && npm run prebuild:docs && cd .. && git diff --exit-code docs/components/_generated/"
   ```

All `frontend-checks` legs feed into the `Quality Report` gate, so a failing custom check fails the org-required `quality / Quality Report` context.

Two related inputs:

- **`frontend-path`** (default `"."`) — points every npm-side job (Vue Quality, Frontend Checks, npm security/license legs) at a subdirectory containing `package.json` + `package-lock.json`, for repos where the frontend is not at the repo root (e.g. `woo-website-template-apiv2` with its app in `pwa/`).
- **`enable-stylelint`** (default `true`) — skip the Stylelint leg for repos without a `stylelint` npm script, mirroring `enable-eslint`.

#### Peer-dependency policy (`.npmrc`, not CLI flags)

The central workflow runs plain `npm ci` — it does **not** pass `--legacy-peer-deps`, and it never will. Peer-resolution behaviour is owned by each repo via its committed `.npmrc`, so CI behaves exactly like every local and production install, and real conflicts surface in CI instead of being masked org-wide.

Do not ask for the flag to be re-hardcoded in `quality.yml`; that silently disables peer checking for every repo in the fleet at once.

##### `legacy-peer-deps` is an emergency brake, not a setting

**The default is: never use it.** Not on the command line, not in `.npmrc`, not "just to get CI green".

What the flag actually does: it reverts npm to its pre-v7 behaviour where peer dependencies are *advisory* — npm stops checking whether the packages in your tree are compatible with each other and simply installs whatever the lockfile says. The error you silenced does not describe a problem with npm; it describes a real incompatibility between two libraries you ship.

Why that always comes back to bite you:

- **It moves the failure from install-time to runtime.** A strict `npm ci` failure is loud, early, and points at the exact packages in conflict. With the flag, the same conflict ships — and resurfaces as `export 'X' was not found`, a second copy of Vue with broken reactivity, or a component that silently renders nothing. Those bugs cost days instead of minutes and are found by users instead of CI.
- **It compounds silently.** Every `npm install` run under the flag can bake further unresolvable combinations into the lockfile. The longer it stays on, the bigger the eventual cleanup — you are not deferring one conflict, you are accumulating them.
- **It hides the fix.** The moment the error is gone, the pressure to actually align the versions is gone with it. Flags like this have a way of becoming permanent; the openconnector `.npmrc` exists since the vue2/vue3 migration and is still there.
- **It desynchronises the fleet.** A repo running legacy resolution produces lockfiles that strict repos can't reproduce, so the same dependency bump behaves differently across apps — the exact drift the central quality workflow exists to prevent.

##### The only acceptable use

A release or critical merge is blocked **right now**, the real fix (aligning the conflicting versions, upgrading the offending dependency, or removing it) is genuinely not achievable on that timeline, and shipping matters more than waiting. Under those circumstances — and only those:

1. Set `legacy-peer-deps=true` in the repo's **`.npmrc`** (never as an ad-hoc CLI flag, so at least the behaviour is committed, visible, and identical everywhere).
2. Add a comment directly above it stating **why** it is needed, **which packages** conflict, **who** set it and **when**.
3. Open a tracking issue for the real fix and link it in that comment.
4. Remove the flag as soon as the conflict is resolved. Treat it like a `TODO` with interest: every week it survives makes the eventual removal harder.

A `.npmrc` containing `legacy-peer-deps=true` without a dated explanation and a linked issue should be treated as a bug and flagged in review.

#### features.json is generated at commit time, never by CI

`docs/features.json` (the commercial capability list derived from `openspec/specs/`) is regenerated **on the developer's machine at commit time**, by a committed pre-commit hook. CI only verifies:

- `features-check` (PRs) and `features-extract` (pushes) are both **read-only gates** — they regenerate in memory, fail if the committed file is stale, and attach the regenerated file as a run artifact. Neither ever commits or pushes.
- The pipeline previously auto-committed the regenerated file back to the branch. That is forbidden now and must not come back: a CI push moves the PR head out from under its checks (with `[skip ci]` it stripped **all** checks from the PR), dismisses reviewer approvals via the org ruleset's dismiss-stale-on-push, and bounced off branch protection on protected branches anyway (#61). A quality pipeline must never mutate the branch it is judging.

Repo setup (the verbatim hook below is the canonical source — copy it, don't reinvent it):

1. Commit `.githooks/pre-commit` — regenerates `docs/features.json` whenever staged changes touch `openspec/specs/` or `openspec/features.overlay.json`, fetching the canonical `scripts/extract-features.py` from this repo (cached fallback when offline). Best-effort: it warns and never blocks the commit; the CI gate is the enforcement backstop.
2. Activate it automatically for every contributor — use **whichever manifests the repo has** (either one suffices; wire both when both exist):
   - `package.json`: `"prepare": "git config core.hooksPath .githooks || true"`
   - `composer.json`: add `"git config core.hooksPath .githooks || true"` to `post-install-cmd`
   - Existing clones activate once manually: `git config core.hooksPath .githooks`

**Husky repos (e.g. `nextcloud-vue`):** husky already owns `core.hooksPath` (`.husky`) — do NOT add `.githooks/` there, it would silently disable the existing husky hooks. Put the regeneration snippet inside the existing `.husky/pre-commit` instead.

**Repos with no package.json or composer.json** (e.g. `hydra`): no auto-activation path exists — but such repos currently have `enable-features-extract: false`, so no hook is needed. If features are ever enabled there, document the manual `git config` line in the repo README.

##### Enforcement: staleness is a merge blocker

The commit hook is convenience, not the safety net — CI is. `features-check` (PR events) and `features-extract` (push events) both **hard-fail** when `docs/features.json` is out of sync with `openspec/specs/`, and both feed the `Quality Report` gate. Since `quality / Quality Report` is (to become) the org-wide required check, **a stale features.json blocks the merge** even if the hook was skipped, broken, or not activated. The failed run attaches the regenerated file as an artifact so recovery is one download + commit away.

##### The hook, verbatim (`.githooks/pre-commit`)

```sh
#!/bin/sh
# Regenerates docs/features.json whenever staged changes touch openspec/specs/
# or the features overlay. Best-effort: warns but never blocks the commit —
# the CI gate (features-check/features-extract → Quality Report) enforces.

if git diff --cached --name-only | grep -qE "^openspec/(specs/|features\.overlay\.json)"; then
  CACHE=".git/extract-features.py"
  curl -sf --max-time 10 \
    https://raw.githubusercontent.com/ConductionNL/.github/main/scripts/extract-features.py \
    -o "$CACHE" 2>/dev/null || true

  if [ -f "$CACHE" ]; then
    if command -v python3 >/dev/null 2>&1; then PY="python3";
    elif command -v py >/dev/null 2>&1; then PY="py -3";
    else PY="python"; fi

    # stdout is silenced for a quiet happy path, but stderr must reach the
    # terminal: a spec file with broken YAML frontmatter should show the real
    # parse error, not get mis-diagnosed as a missing-python problem.
    if $PY "$CACHE" --app-root . >/dev/null; then
      git add docs/features.json
      echo "pre-commit: docs/features.json regenerated from openspec/specs/."
    else
      echo "pre-commit: WARNING — could not regenerate docs/features.json (see error above; missing python/pyyaml also lands here). CI features-check will verify." >&2
    fi
  else
    echo "pre-commit: WARNING — could not fetch extract-features.py (offline?). CI features-check will verify." >&2
  fi
fi

exit 0
```

**Trust boundary — know what you're inheriting.** The hook downloads and executes `scripts/extract-features.py` from this repo's `main` at commit time. That is the same trust model the reusable workflow already uses (its `Checkout shared scripts` step checks out `ConductionNL/.github@main`), but the blast radius differs: the workflow runs on an ephemeral CI runner, the hook runs on every contributor's machine — anyone with write access to `.github`'s `main` can execute code there on the next openspec-touching commit. This is accepted for now because it matches existing CI practice and keeps the script in one canonical place. If that ever stops being acceptable, harden by pinning the fetch to a tag (`.../refs/tags/quality-vN/...`) or verifying a committed SHA-256 checksum of the script before executing (fail closed on mismatch).

##### Per-repo rollout checklist

1. Copy `.githooks/pre-commit` (above) into the repo and mark it executable (`git add --chmod=+x .githooks/pre-commit` on Windows).
2. Wire the activation one-liner into every manifest the repo has (`package.json` `prepare`, `composer.json` `post-install-cmd`); husky repos put the snippet in `.husky/pre-commit` instead.
3. Run `git config core.hooksPath .githooks` once in your own clone (installs only cover future clones/installs).
4. Regenerate once (`commit anything touching openspec/`, or run the script manually) so the repo enters the enforced state green.

##### SBOM: nothing to set up per repo

The SBOM never had this problem and needs no hook: the `sbom` job generates the CycloneDX SBOM in CI, hard-fails on validation (Grype CVE scan, composer/npm audit), and publishes it as a run artifact + release asset. It is **intentionally never committed to the repo** (SBOMs embed timestamps/serial numbers, so a committed copy would be perpetually "stale" and pollute every diff). Its failures block merges through the same Quality Report gate. PR-time dependency gating is covered separately by the `Security (composer)`/`Security (npm)` legs, which run on every PR.

## SBOM (Software Bill of Materials)

Each app's SBOM is published exclusively as a **release asset** via the central Quality workflow's SBOM job. Per-app `sbom.yml` workflows are not allowed — they were removed in [`ConductionNL/.github#34`](https://github.com/ConductionNL/.github/pull/34).

See [SECURITY.md](./SECURITY.md#software-bill-of-materials-sbom) for the consumer contract (stable URLs, format, verification gates).

## Branch flow

Every Conduction app uses three protected branches with this promotion direction:

```
feature/* → development → beta → main
```

- **`development`** — integration branch. Open feature/bugfix/hotfix PRs against it.
- **`beta`** — pre-release. Periodically refreshed from `development` via the standard release PR.
- **`main`** — production. Refreshed from `beta` after sign-off. Every push to `main` (= every release) generates a release tag, which the SBOM job attaches the SBOM to.

Branch protection on each branch (per the org-wide ruleset):

- `development` — 1 review required
- `beta` — 1 review required
- `main` — 2 reviews required

PRs always target `development` unless they are explicitly a release-promotion PR.

## OpenSpec

Specs and ADRs live under `openspec/` in each app. Cross-app shared specs and ADRs live at [`ConductionNL/hydra/openspec/`](https://github.com/ConductionNL/hydra). See the per-app `CLAUDE.md` for the current workflow.

## Documentation

App-specific docs live in `docs/` per app. Cross-org developer docs live at [`ConductionNL/.github/docs/`](./docs/). The conventions in this file complement (not replace) the docs there.
