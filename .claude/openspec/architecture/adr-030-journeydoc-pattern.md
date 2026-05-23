# ADR-030: Journeydoc — capture-driven user documentation

## Status
Proposed

## Date
2026-05-06

## Context

Mydash documentation surfaced a reusable workflow: 15 step-by-step
tutorial pages were authored alongside a Playwright spec that drives
each user journey end-to-end and captures a fresh PNG at every step.
The result is a Docusaurus site whose screenshots stay in sync with
the live UI — re-run the capture spec after any UI change and every
image refreshes automatically.

The workflow has eight reusable artifacts, none of which is mydash-
specific: a `tutorials/{user,admin}/` markdown structure, a
`docs-screenshots.spec.ts` capture spec, a per-track `_category_.json`
sidebar config, a Playwright `docs-capture` project flag, a Docusaurus
config snippet (`onBrokenMarkdownImages: 'warn'` + `static/`-based
asset path), a `data-testid` instrumentation convention on the
high-traffic Vue surfaces, a screenshot output dir
(`docs/static/screenshots/tutorials/{track}/<file>.png`) reachable at
the canonical `/screenshots/...` URL, and a per-app domain wired into
`docs/static/CNAME` + `docs/docusaurus.config.js url` +
`.github/workflows/documentation.yml cname:`.

Without a fleet-wide convention each app re-discovers the gotchas in
isolation:

- Path mistakes (e.g. `docs/screenshots/` instead of
  `docs/static/screenshots/`) silently ship broken images to
  production.
- Selector brittleness on the capture spec. The mydash first run
  passed only 6/15 stories; pulling stable `data-testid`s into the
  Vue components took 28 testids across 8 components and lifted the
  pass rate to 9/15.
- Branch-name policy violations on the doc PRs themselves
  (`feat/...` → rejected, must be `feature/...`).
- Domain wiring: three places need to agree on the docs hostname or
  GitHub Pages serves a 404 / mismatched cert.

## Decision

**Every Conduction app SHALL produce its end-user documentation via
the journeydoc pattern. The pattern lives in hydra; per-app adoption
is bootstrapped by the `journeydoc-init` skill.**

Specifically:

1. **Source layout**:
   ```
   docs/
   ├── tutorials/
   │   ├── _category_.json                # "Tutorials" parent
   │   ├── user/
   │   │   ├── _category_.json            # "User guide"
   │   │   └── 01-…, 02-…, NN-…           # one numbered story per page
   │   └── admin/
   │       ├── _category_.json            # "Admin guide"
   │       └── 01-…, 02-…, NN-…
   └── static/
       └── screenshots/
           └── tutorials/
               ├── user/<file>.png        # captured by the spec
               └── admin/<file>.png
   ```
   Page filenames carry numeric prefixes for `sidebar_position`
   ordering. Docusaurus strips the prefix from the URL — the canonical
   page URL is `/docs/tutorials/{track}/{slug-without-prefix}`.

2. **Markdown image references** are root-absolute:
   `![alt](/screenshots/tutorials/user/<file>.png)` — never relative.
   Docusaurus serves `docs/static/*` from the build root; relative
   paths to `docs/screenshots/` are NOT copied and silently 404 on
   deploy.

3. **Capture spec** lives at
   `tests/e2e/docs-screenshots.spec.ts`, runs under a dedicated
   `docs-capture` Playwright project that the regression `chromium`
   project explicitly excludes. The spec writes PNGs to
   `docs/static/screenshots/tutorials/{track}/<file>.png` so a single
   `npx playwright test --project docs-capture` refreshes every image
   in the docs site.

4. **Test-id convention** for stable selectors that the capture spec
   targets: `data-testid="<scope>-<element>"`, where `<scope>` is the
   feature surface (e.g. `dashboard`, `widget`, `cog`, `ctx`,
   `admin`). Capture-relevant surfaces — modal containers, primary
   buttons, list-row actions, context menu items — MUST carry a
   testid.

5. **Per-page structure** is fixed:
   - `# Title`
   - one-line description in frontmatter (`description:` field)
   - `## Goal`
   - `## Prerequisites`
   - `## Steps` (numbered, each with one inline screenshot)
   - `## Verification`
   - `## Common issues` (table)
   - `## Reference` (cross-link into existing `features/*.md`)

6. **Domain wiring** is consistent across three files:
   - `docs/static/CNAME` — single line, the FQDN
   - `docs/docusaurus.config.js` — `url` field
   - `.github/workflows/documentation.yml` — `cname:` workflow input
   All three must equal `<app-slug>.conduction.nl` (default) unless an
   app has an established alternate domain.

7. **Docusaurus config**:
   - `markdown.hooks.onBrokenMarkdownImages: 'warn'` so a fresh
     checkout that hasn't run the capture spec yet can still build.
   - `i18n.locales` is `['en']` until an app has actual translated
     markdown. Stale `nl/current.json` translation strings break SSR
     rendering when the docs source moves faster than the translation.

8. **Cross-links between tutorial pages** use the on-disk filename
   including the numeric prefix (e.g.
   `[create](02-create-dashboard.md)`). Docusaurus resolves them; the
   rendered `<a href>` points at the prefix-stripped URL.

## Tooling

Three hydra skills implement the pattern:

| Skill | Purpose |
|---|---|
| `journeydoc-init` | Bootstrap a new app — drops the 8-artifact scaffold, fills `{{APP_SLUG}}` / `{{APP_TITLE}}` / `{{DOMAIN}}` from the repo's `composer.json` + `package.json`, opens a PR. |
| `journeydoc-add-story` | Append one tutorial page to an already-journeydoc-ed app — markdown skeleton + capture-spec test block + sidebar entry. |
| `journeydoc-instrument` | Audit a Vue file, propose `data-testid`s on the capture-relevant anchors, apply them after confirmation, run vitest. |

Templates under `hydra/templates/journeydoc/` are the canonical source
for what `journeydoc-init` drops into a target repo. When the pattern
evolves, update the templates first; the skill picks the changes up
automatically.

## Consequences

**Positive**:
- Every app's docs site stays current automatically — UI change → run
  capture spec → docs match again.
- New contributors land on a documented user journey, not a dump of
  feature reference pages.
- Visual regressions surface as failing screenshots in the capture
  spec, not as "looks fine in dev" silence.
- The pattern can be extended (video recordings, accessibility audits,
  i18n parity checks) without rewriting the capture-spec scaffold.

**Negative**:
- Adds one more piece of infrastructure per app to maintain (the
  capture spec). Mitigated by the `journeydoc-add-story` skill so the
  per-page incremental cost is one skill invocation.
- Selector misses on the first capture run are common — depending on
  the app's UI complexity 30-60% of stories typically need a second
  pass with `journeydoc-instrument` to add the missing `data-testid`s.
- Broken-images warnings on a fresh clone (before the capture spec
  runs) are accepted — the docusaurus config is set to warn rather
  than fail. Apps that want a strict build can flip to `'throw'` once
  their screenshots are committed.

## Out of scope

- **Translated tutorials.** The pattern ships English-first; adding
  per-locale capture spec runs (each in a different language) is a
  follow-up. ADR-007 (i18n) governs translation responsibilities for
  app source code; tutorial markdown sits outside that contract for
  now.
- **Video / GIF capture.** The pattern is still-image-only. Drag and
  resize flows are captured at three frames (start, mid-drag,
  post-drop) rather than as a video. The `playwright-video` package
  could extend this without changing the markdown shape.
- **Cross-app deduplication.** Each app owns its own tutorials. There
  is no plan to build a unified "Conduction tutorials" portal that
  aggregates every app's tutorials.

## References

- Mydash PR #132 (initial pattern landing) and PR #134 (path / static
  fix). Both merged into mydash `development`.
- `hydra/templates/journeydoc/` — canonical templates.
- `hydra/.claude/skills/journeydoc-{init,add-story,instrument}/` —
  the three skills.
- ADR-008 (testing) — capture spec lives under `tests/e2e/` per the
  Playwright convention.
- ADR-009 (documentation) — per-app docs site convention this builds
  on.
