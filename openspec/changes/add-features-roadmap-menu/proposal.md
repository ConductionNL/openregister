# Proposal: add-features-roadmap-menu

`kind: feature` per ADR-032 — new shared Vue component family +
new build-tool package + new Docusaurus package + OpenRegister pilot wiring.

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
- [x] Project: `@conduction/openspec-manifest` (new npm package) — CLI
  `openspec-manifest build` walking `openspec/specs/*/spec.md`, emitting
  `docs/features.json`, with `gray-matter` parsing, default-branch
  `docsUrl` resolution, and Jest fixtures.
- [x] Project: `@conduction/docusaurus-features` (new npm package) —
  React `<FeaturesPage />` rendering `docs/features.json` on each app's
  Docusaurus site.
- [x] Project: openregister — adopts the prebuild step, mounts the link
  + route, tags 2-3 widgets/pages with `specRef`, installs the Docusaurus
  package, ships the Playwright smoke + Newman API collections; depends on
  `add-github-issue-proxy` for the backend.
- [ ] Other Conduction apps — fleet adoption deferred to a future
  hydra ADR-019 + rollout plan; explicitly NOT in this change.

## Scope

### In Scope

- One new capability spec (`features-roadmap-menu`) — see `specs/`.
- Two new npm packages (`@conduction/openspec-manifest`, `@conduction/docusaurus-features`).
- Component family additions to `@conduction/nextcloud-vue` with strict
  `marked` + `DOMPurify` markdown rendering, label blocklist, opt-out
  prop, full-route UX (not panel/modal), nl + en chrome.
- `useSpecRef()` composable that reads either Vue Options API
  `$options.specRef`, Composition API `defineOptions({ specRef })`, OR
  Vue Router `route.meta.specRef`. Slug convention is the same as
  ADR-008 `@spec` PHPDoc annotations.
- Pilot integration on OpenRegister: prebuild step, `/features-roadmap`
  route, mounted link, devDep + dep bumps, Playwright + Newman tests,
  Docusaurus plugin install.
- Manifest-freshness CI snippet recommended in the package README AND
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

## Impact

- **First cross-repo shared-library navigation component family.**
  `@conduction/nextcloud-vue` today exports only `CnSettingsCard` /
  `CnSettingsSection`. The new family sets the pattern for future
  "cross-app surfaces" additions (help, support, feedback).
- **First shared Docusaurus component from Conduction.** Visual parity
  with the in-app component within Docusaurus theming constraints.
- **i18n.** Component ships with Dutch + English strings for all chrome
  (route title, tab labels, modal fields, empty states, toasts, errors).
  Feature titles + summaries pass through unchanged — specs are SoT.
- **Fleet rollout deliberately deferred.** Only OpenRegister adopts in
  this change. Limits the blast radius of a first-time shared-component
  release.
