# Proposal: add-features-roadmap-menu

`kind: feature` per ADR-032 — new shared Vue component family +
in-repo manifest-builder script (copied per-app) + per-site Docusaurus page
template + OpenRegister pilot wiring.

> **Scope note 2026-06-12 (W33 honest-handoffs).** This proposal originally
> planned two new npm packages (`@conduction/openspec-manifest`,
> `@conduction/docusaurus-features`). After review the build-tool surface was
> rescoped to a single in-repo script (`scripts/build-features-manifest.js`)
> that adopting apps copy verbatim, and the Docusaurus surface was rescoped to
> a ~30-LOC per-site `src/pages/features.js` page template. Rationale in
> §Design and §Tasks (~600 LOC of npm release plumbing per package for a
> 222-line script + a 30-line page is gold-plating per ADR-022). The
> component-family addition to `@conduction/nextcloud-vue` is unchanged.

## Summary

Ship the cross-repo UX, build-time extraction, and Docusaurus-page contract
that together let every Conduction Nextcloud app expose a single "Features
& Roadmap" entry above the Settings gear in `NcAppNavigationSettings`. The
entry navigates to a dedicated route with two tabs: Features (extracted from
the app's own `openspec/specs/*/spec.md` at build time, status ∈
{implemented, reviewed}) and Roadmap (live open GitHub issues via
OpenRegister's `github-issue-proxy`). A "Suggest feature" button — surfaced
from the route header AND any widget/page that opts in via the ADR-008-aligned
`specRef` declaration — opens a modal that POSTs straight to GitHub via the
same proxy. The same `docs/features.json` manifest also powers a public
features page on each app's Docusaurus site via a new shared package.

The backend half (OR controller + handler methods + IAppConfig keys) ships
in the paired `add-github-issue-proxy` change; this change depends on it.

## Motivation

Documentation lives in READMEs and GitHub issue trackers that non-developers
never visit. Every Conduction app has the same gap, and every app has the
same two sources of truth already: its `openspec/specs/` tree (what's built)
and its repo (what's planned). Binding both to a single, shared UI element
removes the gap fleet-wide without inventing a new storage model or sync
worker. The submission path is the critical value proposition explicitly
flagged by the user ("the most important part") — letting any authenticated
user file a feature request from inside the app, authored as themselves when
their PAT is configured and via the server PAT with attribution otherwise.

## Affected Projects

- [x] Project: `@conduction/nextcloud-vue` — new component family
  (`CnFeaturesAndRoadmapLink`, `FeaturesAndRoadmapView`,
  `SuggestFeatureModal`, `useSpecRef`, `useSuggestFeatureAction`,
  `SAFE_MARKDOWN_DOMPURIFY_CONFIG`, `ROADMAP_LABEL_BLOCKLIST`), nl + en i18n,
  Jest tests, Storybook stories, README, version bump.
- [x] In-repo build tooling: `openregister/scripts/build-features-manifest.js`
  (222 LOC) — walks `openspec/specs/*/spec.md`, emits `docs/features.json`,
  with `gray-matter` parsing and default-branch `docsUrl` resolution
  (github.com + codeberg.org). Adopting apps copy the script verbatim into
  their own `scripts/` tree; **no separate npm package is published**.
  Originally scoped as `@conduction/openspec-manifest` — rescoped 2026-06-12
  per the W33 honest-handoffs review. The OR `manifest:check` CI step
  exercises the contract end-to-end against a real spec corpus on every CI
  run, replacing the originally-scoped Jest fixture suite.
- [x] Docusaurus features page: a per-site `src/pages/features.js` template
  (~30 LOC) that imports `docs/features.json` and renders the
  alphabetically-sorted list as a public `/features` page. Adopting apps
  copy the template into their own Docusaurus tree; **no separate npm
  package is published**. Originally scoped as
  `@conduction/docusaurus-features` — rescoped 2026-06-12 per the W33
  honest-handoffs review.
- [x] Project: openregister — adopts the prebuild step, mounts the link
  + route, tags 2-3 widgets/pages with `specRef`, ships the Playwright
  smoke + Newman API collections; depends on `add-github-issue-proxy` for
  the backend. The Docusaurus page template lands in the OR docs site as
  the reference implementation.
- [ ] Other Conduction apps — fleet adoption deferred to a future
  hydra ADR-019 + rollout plan; explicitly NOT in this change.

## Scope

### In Scope

- One new capability spec (`features-roadmap-menu`) — see `specs/`.
- One in-repo build script (`scripts/build-features-manifest.js`) copied per
  adopting app + one per-site `src/pages/features.js` Docusaurus page
  template. No new npm packages are published.
- Component family additions to `@conduction/nextcloud-vue` with strict
  `marked` + `DOMPurify` markdown rendering, label blocklist, opt-out
  prop, full-route UX (not panel/modal), nl + en chrome.
- `useSpecRef()` composable that reads either Vue Options API
  `$options.specRef`, Composition API `defineOptions({ specRef })`, OR
  Vue Router `route.meta.specRef`. Slug convention is the same as
  ADR-008 `@spec` PHPDoc annotations.
- Pilot integration on OpenRegister: prebuild step, `/features-roadmap`
  route, mounted link, devDep + dep bumps, Playwright + Newman tests,
  reference Docusaurus features page in the OR docs site.
- Manifest-freshness CI snippet (`node scripts/build-features-manifest.js
  --check`) documented in the OR README + this change's tasks file AND
  added to OpenRegister's existing workflow.
- Admin opt-out wiring through to the bundle (IAppConfig flag becomes a
  prop on the link / a guard on the route).

### Out of Scope

- OR backend (controller, handler methods, IAppConfig keys, rate limit,
  audit log) — all in `add-github-issue-proxy`.
- GitHub Discussions integration.
- "Accept feature → specter spec proposal" automation.
- Adoption PRs in opencatalogi, docudesk, openconnector, launchpad,
  zaakafhandelapp, procest, pipelinq, softwarecatalog, larpingapp,
  decidesk, nldesign — separate ADR-019 + per-app PRs.
- Webpack-plugin wrapper for the manifest generator.
- Publishing the extractor or Docusaurus page as standalone npm packages —
  explicitly rescoped 2026-06-12 to the copy-pattern documented in §Tasks
  §2 and §3.

## Impact

- **First cross-repo shared-library navigation component family.**
  `@conduction/nextcloud-vue` today exports only `CnSettingsCard` /
  `CnSettingsSection`. The new family sets the pattern for future
  "cross-app surfaces" additions (help, support, feedback).
- **First Conduction Docusaurus features page.** A per-site
  `src/pages/features.js` template that renders `docs/features.json`. Visual
  approximation of the in-app component within Docusaurus theming
  constraints; Vue components cannot be reused inside Docusaurus's React
  runtime, so per-site copies are honest about that constraint and avoid an
  npm-package release path that would cost more than the code it ships.
- **i18n.** Component ships with Dutch + English strings for all chrome
  (route title, tab labels, modal fields, empty states, toasts, errors).
  Feature titles + summaries pass through unchanged — specs are SoT.
- **Fleet rollout deliberately deferred.** Only OpenRegister adopts in
  this change. Limits the blast radius of a first-time shared-component
  release.
