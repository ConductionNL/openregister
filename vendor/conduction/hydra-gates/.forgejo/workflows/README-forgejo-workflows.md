# Conduction reusable Forgejo Actions workflows

These reusable workflows live in `Conduction/.github/.forgejo/workflows/`
on Codeberg. They are the Forgejo Actions translations of the
centralized `ConductionNL/.github` GitHub Actions workflows the fleet
used before the GitHub → Codeberg migration.

Per-app repos call them via `uses:` from their own `.forgejo/workflows/`.

## Workflows

### `documentation.yml`

Builds a Docusaurus site and deploys it to Cloudflare Pages.

- Build job validates output on every event (incl. pull requests),
  so the `@conduction/docusaurus-preset` postbuild validator
  (robots.txt, llms.txt, sitemaps, JSON-LD, FAQPage, og:image, etc.)
  runs as a PR gate.
- Deploy job runs on `push`, `schedule`, `workflow_dispatch` only
  and pushes the build to Cloudflare Pages via `wrangler pages deploy`.
- Container: `node:24-bookworm`.

#### Inputs

| Input              | Type    | Default  | Notes |
| ------------------ | ------- | -------- | ----- |
| `cf-project-name`  | string  | _req_    | Cloudflare Pages project name. **Renamed** from GH original's `cname` (which was a GH Pages custom domain). |
| `source-folder`    | string  | `docs`   | Docusaurus project root. |
| `node-version`     | string  | `20`     | Node version for the build. |

#### Secrets

| Secret              | Used for |
| ------------------- | -------- |
| `CF_API_TOKEN`      | Cloudflare Pages auth (`Cloudflare Pages: Edit`). |
| `CF_ACCOUNT_ID`     | Cloudflare account ID. |
| `CF_PAGES_PROJECT`  | _Optional_ — if a repo prefers a secret over an input, pass `${{ secrets.CF_PAGES_PROJECT }}` to `cf-project-name`. |
| `CODEBERG_TOKEN`    | Auth for any forge-API calls the build script makes (e.g. nightly app-downloads fetch). Falls back to anonymous. |

### `release-stable.yml`

Calculates the next semver from the merged PR's labels (`major` /
`minor` / `patch`, default `patch`), builds the Nextcloud app, signs
the tarball, creates a Codeberg release with the tarball as an asset,
and uploads it to the Nextcloud App Store (`nightly: false`).

- Container: `php:8.3-cli` (installs `git`, `curl`, `jq`, `rsync`,
  `composer`, and the PHP `zip`/`gd` extensions in the first step).

### `release-beta.yml`

Same pipeline as stable, but the version is `<next-patch>-beta.<UTC-timestamp>`
above the latest stable tag and the App Store upload sets
`nightly: true`.

#### Inputs (both release workflows)

| Input                  | Type    | Default | Notes |
| ---------------------- | ------- | ------- | ----- |
| `app-name`             | string  | _req_   | Nextcloud app ID — must match `appinfo/info.xml`. |
| `php-version`          | string  | `8.3`   | PHP version. The container image is fixed to `php:8.3-cli`; this input is plumbed through but ignored by the build (kept for forward compat). |
| `node-version`         | string  | `20`    | Node version for the frontend build. |
| `verify-vendor-deps`   | boolean | `false` | Enable post-`composer install` vendor verification. |
| `vendor-check-paths`   | string  | `""`    | Comma-separated paths under `vendor/` that must exist. |
| `extra-rsync-excludes` | string  | `""`    | Additional `--exclude` flags for the package step. |

#### Secrets (both release workflows)

| Secret                       | Used for |
| ---------------------------- | -------- |
| `CODEBERG_TOKEN`             | Read PR labels, create the Codeberg release, upload the tarball as a release asset. Required scopes: `repository: read+write`. |
| `NEXTCLOUD_SIGNING_KEY`      | PEM private key used to sign the tarball for the NC App Store. |
| `NEXTCLOUD_SIGNING_CERT`     | Matching certificate. |
| `NEXTCLOUD_APPSTORE_TOKEN`   | NC App Store API token. |

## Sample consumer wrapper

A per-app repo (e.g. `Conduction/pipelinq`) adds two thin wrappers
under its own `.forgejo/workflows/`:

```yaml
# .forgejo/workflows/documentation.yml
name: Publish docs

on:
  push:
    branches: [documentation]
  pull_request:
    branches: [documentation]
  workflow_dispatch:
  schedule:
    - cron: "0 4 * * *"

jobs:
  call:
    uses: https://codeberg.org/Conduction/.github/.forgejo/workflows/documentation.yml@main
    with:
      cf-project-name: pipelinq-docs
      source-folder: docs
    secrets:
      CF_API_TOKEN:    ${{ secrets.CF_API_TOKEN }}
      CF_ACCOUNT_ID:   ${{ secrets.CF_ACCOUNT_ID }}
      CODEBERG_TOKEN:  ${{ secrets.CODEBERG_TOKEN }}
```

```yaml
# .forgejo/workflows/release-stable.yml
name: Stable release

on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  call:
    uses: https://codeberg.org/Conduction/.github/.forgejo/workflows/release-stable.yml@main
    with:
      app-name: pipelinq
      php-version: "8.3"
      node-version: "20"
    secrets:
      CODEBERG_TOKEN:            ${{ secrets.CODEBERG_TOKEN }}
      NEXTCLOUD_SIGNING_KEY:     ${{ secrets.NEXTCLOUD_SIGNING_KEY }}
      NEXTCLOUD_SIGNING_CERT:    ${{ secrets.NEXTCLOUD_SIGNING_CERT }}
      NEXTCLOUD_APPSTORE_TOKEN:  ${{ secrets.NEXTCLOUD_APPSTORE_TOKEN }}
```

The beta wrapper is identical but targets the `beta` branch and calls
`release-beta.yml`.

## Codeberg runner constraints

- **10-minute job cap** on `codeberg-medium` (the default Codeberg
  hosted runner). All jobs in these workflows set `timeout-minutes: 10`.
- **No Docker daemon** (Podman only). The `docker` runs-on label
  spins up the job _inside_ a container — it doesn't give you
  `docker` CLI to drive other containers. The GH-original
  "build a GHCR Apache fallback image" job was dropped for this
  reason (Cloudflare Pages now provides the resilience story).
- **Public, free/libre repos only** — Conduction is EUPL-1.2,
  so this is fine. Private repos would need a self-hosted runner.
- `secrets.GITHUB_TOKEN` does not exist on Forgejo Actions. Every
  forge-API call uses `secrets.CODEBERG_TOKEN`, a PAT each consuming
  repo must add.
- The `gh` CLI is not installed. All Codeberg/Gitea API calls use
  `curl`.

## Differences from the GitHub originals

| Concern | GitHub | Codeberg/Forgejo |
| ------- | ------ | ---------------- |
| Runner | `ubuntu-latest` | `runs-on: docker` + `container.image` |
| Forge token | `secrets.GITHUB_TOKEN` (auto) | `secrets.CODEBERG_TOKEN` (PAT, repo-scoped) |
| Docs deploy target | GitHub Pages via `peaceiris/actions-gh-pages` | Cloudflare Pages via `wrangler pages deploy` |
| Docs fallback image | Built and pushed to GHCR | **Dropped** — Cloudflare Pages is the resilience layer now |
| Semver bump action | `actions-ecosystem/action-bump-semver@v1` | Inline bash |
| Release creation | `ncipollo/release-action@v1` | `curl POST /api/v1/repos/{owner}/{repo}/releases` |
| Release asset upload | `svenstaro/upload-release-action@v2` | `curl POST /api/v1/repos/{owner}/{repo}/releases/{id}/assets` |
| `gh api` calls | Available | Replaced with `curl` |
| PR label lookup | `gh api repos/.../commits/{sha}/pulls` | `curl /repos/.../commits/{sha}/pull` + fallback to closed-PR list match |

## Known limitations vs the GH originals

- **No GHCR fallback image.** If you want a self-contained Apache
  image per docs site, build it manually or via a separate
  self-hosted runner. Cloudflare Pages is durable enough that the
  fleet doesn't need this in the standard pipeline.
- **No release-notes auto-generation.** The GH original used
  `ncipollo/release-action`'s `generateReleaseNotes: true`. The
  Codeberg API has no equivalent; releases ship with an empty body.
  Add a separate "compile release notes from PR titles" step if
  you need this back.
- **PR-label lookup is best-effort.** Codeberg's
  `/commits/{sha}/pull` endpoint behaviour varies; the workflow
  falls back to scanning the 20 most recent closed PRs by
  `merge_commit_sha`. Edge cases (rebase merges with rewritten
  SHAs, force-pushes) may default to `patch`.
- **10-minute hard cap.** Apps whose `composer install` + `npm ci` +
  build takes more than ~9 minutes will need a self-hosted runner.
  Currently every Conduction app fits.
- **`php-version` input is plumbed but inert.** The container is
  fixed at `php:8.3-cli`. To support multiple PHP versions, switch
  on the input and pick a matching tag.

## Not translated (out of scope)

The original `Conduction/.github` repo also carries `release.yml`
(the larger combined stable+beta workflow), code-quality gates,
branch-protection helpers, issue-triage, and openspec-sync. None
of those are translated here — pull them in separately if/when
needed.
