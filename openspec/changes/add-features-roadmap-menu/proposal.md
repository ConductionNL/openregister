# Add Features & Roadmap Menu

## Why

Operators and end-users of Conduction Nextcloud apps currently have no in-product way to see
(a) what the app can do today, (b) what is coming next, and (c) how to suggest what they want
built. Documentation lives in README files and GitHub issue trackers that non-developers never
visit. Every app has the same gap, and every app has the same two sources of truth already:
its `openspec/specs/` tree (what is implemented) and its GitHub repository (what is planned,
via open issues). We will bind those two sources to a single, shared UI element mounted in the
`NcAppNavigationSettings` sidebar slot — above the existing Settings gear — so that every
Conduction app gets a consistent "Features & Roadmap" entry without inventing a new storage
model or synchronization job.

The critical value, explicitly called out by the user as "the most important part," is the
user-generated feature request path: any authenticated user, from inside the app (either the
dedicated route or any widget/page that opted into the `specRef` contract), can file a feature
request that lands directly as a GitHub issue on the app's repo, routed via their own GitHub
token when present and via the server's app-level token (with authorship prefix) as fallback.

## What Changes

- Extend OpenRegister's existing `GitHubHandler` (ADR-011 utility reuse) with `listIssues()`
  and `createIssue()` methods. `createIssue()` prefers the user's own PAT stored at
  `openregister::github_token` (IConfig) and falls back to the app-level PAT at
  `openregister::github_api_token` (IAppConfig); when falling back, it prepends an attribution
  prefix to the issue body so authorship is preserved.
- Add a new `GitHubIssuesController` exposing:
  - `GET /api/github/issues` — a thin, cached proxy to GitHub's issues search endpoint.
    Accepts an optional `labels` query parameter (comma-separated, up to 8 entries) with
    OR semantics: multiple labels yield issues carrying *any* of the named labels (per D23).
    The Roadmap tab uses `labels=enhancement,feature` to surface a curated planned-work
    list instead of every open issue.
    Carries `#[NoCSRFRequired]` since it is a pure read with no side effects.
  - `POST /api/github/issues` — accepts `{repo, title, body, specRef?}` and creates a GitHub
    issue on the app's repo. MUST enforce CSRF (no `#[NoCSRFRequired]` attribute). Subject to
    per-user APCu-backed rate limit of 1 submission per 60 seconds.
- Add a new shared Vue component family to `@conduction/nextcloud-vue`:
  - `CnFeaturesAndRoadmapLink` — an `NcAppNavigationItem` that navigates to a dedicated route.
  - `FeaturesAndRoadmapView` — the full-route view with Features, Roadmap, and a header
    "Suggest feature" button.
  - `SuggestFeatureModal` — a modal form with live markdown preview, title + body fields, and
    a hidden `specRef` field pre-filled when launched from a widget/page context.
  - `useSpecRef()` — a composable that reads `specRef` from the active component's
    `defineOptions({ specRef })` / Options-API component option OR from Vue Router's
    `meta.specRef` on the active route.
  - Roadmap item bodies render via `marked` + `DOMPurify` (strict allowlist: no `<script>`,
    no `on*` handlers, no `javascript:` URLs, no `<iframe>`).
  - A label blocklist filters out Hydra pipeline / workflow labels so only user-facing labels
    render on each roadmap item.
- Add a new standalone build-tool package `@conduction/openspec-manifest` that walks an app's
  `openspec/specs/*/spec.md` tree (preferring that path, falling back to `./specs/` for
  legacy layouts), parses YAML frontmatter, filters to `status ∈ {implemented, reviewed}`,
  extracts the H1 title and the `## Purpose` summary, resolves `docsUrl` against the repo's
  default branch (`origin/HEAD`, not the currently checked-out branch), and emits
  `docs/features.json` — committed to git (NOT gitignored) — to be bundled by the app's
  build AND consumed by the app's Docusaurus site. CLI only in this iteration; a webpack
  plugin is deferred.
- Add a new shared Docusaurus package `@conduction/docusaurus-features` that reads
  `docs/features.json` and renders a public features page on the app's Docusaurus site,
  reusing the same ordering and visual style as the in-app component (within Docusaurus
  theming constraints).
- Pilot the end-to-end wiring inside OpenRegister itself: add the prebuild step, add the
  `/features-roadmap` route, mount `CnFeaturesAndRoadmapLink` in OpenRegister's own
  `MainMenu.vue`, tag 2-3 existing widgets/pages with `specRef` to validate the contract,
  install `@conduction/docusaurus-features` in OpenRegister's Docusaurus site, ship a small
  smoke test.
- Add cross-repo documentation: README in the new component package, short migration guide
  for app maintainers, placeholder reference to a future hydra ADR-019 that will mandate
  fleet-wide adoption, note in `docs/adr-008-spec-annotations.md` that `specRef` is the
  runtime equivalent of the static `@spec` annotation.

Out of scope for this change (follow-ups, tracked separately):

- GitHub Discussions integration (user chose Issues, not Discussions).
- "Accept feature → specter spec proposal" wiring.
- Company-wide ADR-019 in `hydra/openspec/` mandating adoption by every app.
- Adoption PRs in the other Conduction apps (opencatalogi, docudesk, openconnector, mydash,
  zaakafhandelapp, procest, pipelinq, softwarecatalog, larpingapp, decidesk, nldesign).
- Webpack plugin wrapper for the manifest generator.

## Capabilities

### New Capabilities

- **`github-issue-proxy`** — A thin OpenRegister backend that (a) proxies open GitHub issues
  (sorted by reactions) for a given public repository and (b) creates new GitHub issues on
  behalf of authenticated users. Uses the user's per-user GitHub PAT when present and falls
  back to the app-level PAT with an authorship prefix when the user has none. Handles
  rate-limit and missing-PAT gracefully, enforces per-user submission rate limiting, and
  applies `specRef:<slug>` labels when the submitter declared a capability context.
- **`features-roadmap-menu`** — The cross-repo UX and build-time contract that ties shipped
  features (extracted from OpenSpec specs, rendered both in-app and on the app's Docusaurus
  site) and roadmap items (GitHub issues via the proxy, with full markdown rendering and
  label filtering) into a single navigation entry and dedicated route, with an
  action-menu-driven submission modal available from any widget or page that declared a
  `specRef`. Implementation spans the shared `@conduction/nextcloud-vue` component family,
  the `@conduction/openspec-manifest` build tool, and the `@conduction/docusaurus-features`
  Docusaurus package.

### Modified Capabilities

None in this change. (Future ADR-019 in hydra will create a company-wide capability mandating
adoption — deliberately deferred.)

## Impact

- **Reuses ADR-011 utilities.** The GitHub proxy extends the existing `GitHubHandler` class
  (`lib/Service/Configuration/GitHubHandler.php`) with two new methods — no new HTTP client,
  no new retry/rate-limit machinery. The controller delegates entirely to the handler.
- **New behavior: submissions write to the app's GitHub repo.** This is the first OpenRegister
  feature that creates resources on an external system on behalf of an end-user. Traceability
  is guaranteed by the authorship prefix on server-PAT fallback.
- **OpenRegister's existing per-user GitHub token store (`openregister::github_token`) gains
  a new consumer.** Previously only used for other GitHub integrations; now also read by
  `createIssue()` as the preferred credential.
- **No new OpenRegister schemas, registers or data stores.** The proxy is stateless aside
  from a 15-minute in-memory response cache for reads and an APCu rate-limit key
  (`openregister.feature_submission:<user_id>`) for writes. Features come from a build-time
  JSON manifest bundled into each app's JS — no backend read path for features at all.
- **First cross-repo shared-library navigation component family.** `@conduction/nextcloud-vue`
  today exports only `CnSettingsCard` / `CnSettingsSection`. The new component family
  (`CnFeaturesAndRoadmapLink`, `FeaturesAndRoadmapView`, `SuggestFeatureModal`,
  `useSpecRef`) sets the pattern for future additions (help, support, feedback).
- **First shared Docusaurus component from Conduction.** `@conduction/docusaurus-features` is
  a new package; visual parity with in-app component within Docusaurus theming constraints.
- **Graceful degradation.** No PAT configured → GET endpoint returns `200` with an empty
  items array and a `hint` code; UI renders a muted "roadmap temporarily unavailable"
  message without blocking the Features tab. POST endpoint without PAT returns a clear
  401/403 with an admin-remediation hint.
- **i18n.** Component ships with Dutch (nl) and English (en) strings for its chrome (route
  title, tab labels, modal fields, empty states, toasts, error messages). Feature titles /
  summaries pass through unchanged because spec files are the single source of truth.
- **Fleet rollout deliberately deferred.** Only OpenRegister adopts the component in this
  change. The other apps will adopt under a separate ADR-019 + rollout plan — this limits
  the blast radius of a first-time shared-component release.
