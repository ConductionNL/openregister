# Design: Add Features & Roadmap Menu

## Context

Every Conduction Nextcloud app ships with two sources of truth that today are invisible to
users:

1. **OpenSpec specs** under `openspec/specs/*/spec.md` describe the capabilities that are
   actually built. Each spec has YAML frontmatter (`status: implemented | reviewed | redirect`),
   a single `# Title` H1 that matches the capability directory, and a `## Purpose` section with
   a short business summary. Today this tree is only read by developers and by the OpenSpec CLI.
2. **GitHub issues** in each app's repository capture the roadmap: bugs, enhancement requests,
   epics. Reactions (`+1`) already act as an informal voting signal from users who discover the
   issue tracker.

The goal is to expose both of these inside the app itself — one menu entry, a dedicated route
with two tabs — and to add a submission path that lets users file feature requests straight
into the repo without leaving the product.

**Scope pivot:** the first iteration of this design deferred user submissions as a follow-up.
The user reopened that decision because the submission path IS the critical value proposition
("the most important part"). Submission is therefore back in scope for this change; the
design below reflects that reopening.

OpenRegister is the framework dependency shared by every Conduction Nextcloud app, so that is
where the backend (read proxy + submission endpoint) lives. `@conduction/nextcloud-vue` is
the shared Vue component library (currently exporting only settings primitives), so that is
where the UI component family lives. The build-time spec-to-manifest tool is introduced as a
third package, `@conduction/openspec-manifest`, because it must be usable from any app's
build without dragging in the full UI library. A fourth package,
`@conduction/docusaurus-features`, renders the same manifest on each app's public
Docusaurus site.

## Goals

- One menu entry → one dedicated route with two tabs, every Conduction app. Consistent
  placement above the Settings gear in `NcAppNavigationSettings`.
- Features surfaced from OpenSpec specs at build time (no runtime filesystem walk, no backend
  read path for feature metadata). Same manifest powers the in-app view and the Docusaurus
  public page.
- Roadmap items fetched live from GitHub with a short cache, full markdown rendering (XSS-
  sanitized), and a blocklist that hides pipeline/workflow labels.
- Submission directly to GitHub Issues, preferring the user's own token for authorship and
  falling back to the app-level token with an authorship prefix when the user has none.
- Widgets and pages may declaratively opt into a `specRef` — reusing the ADR-008 capability-
  slug convention — so the "Suggest feature" action appears contextually wherever the user
  is working.
- Reuse the existing `GitHubHandler` + both PAT stores; reuse the existing admin UI for PAT
  configuration; no new secret storage.
- Ship the pilot wiring on OpenRegister itself in this change.

## Non-Goals

- GitHub Discussions integration — user explicitly chose Issues.
- "Accept feature → auto-generate spec" pipeline (future: specter integration).
- Fleet-wide adoption across every Conduction app (future: ADR-019 in hydra).
- Editing or moderating issues from inside the app (create + read only; no close, no comment).
- Private / enterprise repos in this iteration. Public repos only.
- Webpack plugin wrapper for the manifest generator (CLI only in this iteration).

## Decisions

### D1. Runtime storage: none (thin proxy)

We do NOT persist GitHub issues into an OpenRegister register, nor do we persist a per-instance
copy of features. Alternatives considered:

- **Per-instance register of features and issues, synced nightly.** Rejected: creates drift,
  requires a sync worker, duplicates data we already have at source, and was explicitly
  rejected by the user.
- **In-app static JSON only for features, no roadmap at all.** Rejected: roadmap is the main
  engagement driver and is cheap if proxied with caching.
- **Chosen:** thin cached proxy for roadmap, build-time JSON bundle for features, direct
  proxy for submissions.

Rationale: minimizes blast radius, zero new data model, survives backend outages for features
(since they're bundled into JS), and single-source-of-truth for both.

### D2. GitHub read auth: reuse `openregister::github_api_token` (IAppConfig)

Alternatives:

- Per-user PAT only. Rejected for read: the roadmap is public data; burdening every user with
  configuring a PAT defeats the point of "works out of the box."
- Anonymous read (no auth). Rejected: 60 req/hour per IP is not enough for a component that
  polls on panel open; will rate-limit quickly across a fleet of apps.
- **Chosen:** reuse the admin-configured app-level PAT for reads. Admin configures once; all
  users benefit.

### D3. Features source: build-time manifest from `openspec/specs/`

- Runtime endpoint reading specs from the installed app's filesystem. Rejected: couples the
  backend to the app's deployment layout.
- Hand-authored JSON curated per release. Rejected: guaranteed to drift.
- **Chosen:** `@conduction/openspec-manifest` CLI runs as a `prebuild` step, writes
  `docs/features.json`, both webpack/vite and Docusaurus consume that same file.

### D4. Feature filter: `status: implemented` OR `status: reviewed`

- `implemented` only. Rejected: `reviewed` is strictly more finished, not less.
- Include `redirect`. Rejected: redirects are capability re-homing markers, not features.
- Include drafts / planned. Rejected: the Features tab must show what actually ships.
- **Chosen:** `status ∈ {implemented, reviewed}`.

### D5. Docs link default

- Require every spec to add a `docsUrl:` frontmatter key. Rejected: high-friction migration.
- No link at all. Rejected: users who want detail should be able to get it.
- **Chosen:** auto-compute against the repo's default branch resolved from `origin/HEAD`,
  never the currently checked-out branch. Specs can opt in to an override via a `docsUrl:`
  frontmatter key.

### D6. Manifest generator as a standalone npm package

- Ship as a dev-dependency module inside `@conduction/nextcloud-vue`. Rejected: couples build
  tooling to the UI library; every app consumes the UI lib but only some apps have OpenSpec.
- Inline script per app, copy-pasted. Rejected: DRY violation across ten-plus apps.
- **Chosen:** new `@conduction/openspec-manifest` package with a `build` CLI.

### D7. Component home: `@conduction/nextcloud-vue`

The `@conduction/nextcloud-vue` library currently exports `CnSettingsCard` and
`CnSettingsSection`. The new `CnFeaturesAndRoadmapLink`, `FeaturesAndRoadmapView`,
`SuggestFeatureModal`, and `useSpecRef` additions set the convention for a new component
category: "cross-app surfaces."

### D8. i18n

The component ships with Dutch (nl) and English (en) strings for all chrome (route title,
tab labels, modal fields, empty states, toasts, error hints). Feature titles and summaries
pass through unchanged — specs are single-source.

### D9. Cache TTL: 15 minutes in-memory, global (per-instance)

- Per-user cache. Rejected: same data for everyone; no personalization.
- Longer TTL (1 hour). Rejected: roadmap engagement benefits from fresh-feeling data.
- Shorter TTL (1 min). Rejected: wastes rate-limit budget.
- **Chosen:** 15 min in-memory cache, keyed by `(repo, state, sort, per_page)`, global per
  instance.

### D10. Graceful degradation when PAT is missing

The read endpoint returns `200 { items: [], hint: "github_pat_not_configured" }` rather than
`403` or `500`. Features tab is unaffected.

### D11. Pilot scope: OpenRegister only

- Ship adoption into every Conduction app in this single change. Rejected: huge blast
  radius for a first-time shared component.
- **Chosen:** wire only OpenRegister in this change. Fleet rollout is a separate hydra
  ADR-019.

### D12. UI pattern: full route, not side panel

- Side panel (`NcAppSidebar`). Considered; would be less intrusive but provides a cramped
  surface for browsing + submitting feature requests, and awkward on mobile.
- Modal. Rejected: too restrictive for two-tab browsing.
- **Chosen:** dedicated Vue route (e.g. `/features-roadmap`). The
  `NcAppNavigationSettings` entry is an `<NcAppNavigationItem>` that navigates to this
  route. Provides a richer surface for browsing Features, the Roadmap, and filing new
  requests from one page.

### D13. Submission destination: GitHub Issues (not Discussions)

- Discussions. Rejected: harder to link to the roadmap tab, which reads Issues; user
  explicitly preferred Issues.
- **Chosen:** Issues. Direct roadmap visibility; the new submission shows up immediately in
  the same Roadmap tab where it was filed.

### D14. Authorship fallback: user-PAT preferred, server-PAT fallback with attribution

- PAT-required (every submitter must configure their own PAT). Rejected: heavy UX friction
  for a feature whose point is low-friction.
- Server-PAT only. Rejected: every issue would appear to be filed by the bot account;
  attribution lost.
- **Chosen:** prefer `openregister::github_token` (IConfig per-user); fall back to
  `openregister::github_api_token` (IAppConfig) when the user has no token; on fallback,
  prefix the issue body with
  `> Submitted by **<nc_user_display_name>** via <nc_instance_url>\n\n---\n\n` so
  traceability survives.

### D15. Markdown rendering: `marked` + `DOMPurify`

- Plain-text body rendering (no markdown). Rejected: GitHub issue bodies are commonly
  markdown; plain rendering looks broken (raw `*`, `#`, code fences).
- Iframe-embed GitHub's render. Rejected: XSS-free but no style control, slow, and breaks
  dark mode theming.
- First-paragraph-only + "Read more on GitHub." Rejected by the user in favor of full
  markdown.
- **Chosen:** `marked` to parse GitHub-flavored markdown, `DOMPurify` with a strict
  allowlist to sanitize (no `<script>`, no `on*` attributes, no `javascript:` URLs, no
  `<iframe>`, no `<style>`). The strict DOMPurify config is exported from
  `@conduction/nextcloud-vue` so any consumer of user-generated markdown can reuse it.

### D16. Label filter strategy: explicit blocklist

- Allowlist of known user-facing labels. Rejected: new labels invented over time (e.g.
  `accessibility`, `security`) would silently disappear from the UI until someone added them
  to the allowlist.
- Show everything. Rejected: Hydra pipeline/workflow labels
  (`build:...`, `code-review:...`, `ready-for-code-review`, etc.) are internal and noisy.
- **Chosen:** explicit blocklist as a regex set:
  - `^build:`
  - `^code-review:`
  - `^security-review:`
  - `^applier:`
  - `^retry:`
  - `^rebuild:`
  - `^fix:`
  - `^fix-iteration:`
  - `^build-retry:`
  - `^ready-`
  - `^needs-input$`
  - `^yolo$`
  - `^openspec$`
  - `^agent-maxed-out$`
  - `^pipeline-active$`
  - `^done$`
  - `:queued$`
  - `:running$`
  - `:pass$`
  - `:fail$`

Anything else passes through and renders with its native GitHub color.

### D17. `features.json` location: committed to git at `docs/features.json`

- `src/features.json` + gitignored (original design). Rejected because the same file must
  power the public Docusaurus page; we cannot ship a public page from a gitignored artifact
  (Docusaurus would see a missing file in CI).
- Two files (one for bundle, one for Docusaurus). Rejected: guaranteed to drift.
- **Chosen:** single committed `docs/features.json`. The CLI is still idempotent and
  deterministic; re-running `prebuild` before any build regenerates it so a stale manifest is
  caught in the diff.

### D18. Widget/page `specRef` contract: declarative, ADR-008-aligned

- Imperative `registerSpecRef('slug')` call in each widget/page's `mounted()`. Rejected:
  harder to audit at rest, not colocated with the component definition, easy to forget.
- **Chosen:** declarative. Widgets use `defineOptions({ specRef: 'slug' })` (Composition API)
  or a component option `specRef: 'slug'` (Options API). Pages use Vue Router
  `meta: { specRef: 'slug' }`. The slug is a kebab-case capability slug — identical
  convention to ADR-008 `@spec` PHPDoc annotations on the backend. `useSpecRef()` reads
  either source.

### D19. Docusaurus integration: shared `@conduction/docusaurus-features` package

- Per-app Docusaurus code. Rejected: DRY violation across ten-plus apps.
- In-app component rendered into Docusaurus. Rejected: Vue-in-Docusaurus integration is not
  worth the tooling overhead; Docusaurus is React.
- **Chosen:** new shared package `@conduction/docusaurus-features` exports a React
  `<FeaturesPage />` component that loads `docs/features.json` and renders alphabetically,
  matching the in-app visual style as closely as Docusaurus theming allows.

### D20. Endpoint CSRF posture

- Both GET and POST carry `#[NoCSRFRequired]`. Rejected: POST mutates external state (creates
  a GitHub issue on behalf of the user). Must carry CSRF protection.
- Neither carries `#[NoCSRFRequired]`. Rejected: GET is pure read with no mutation; requiring
  CSRF tokens on every roadmap fetch adds unnecessary friction.
- **Chosen:** `#[NoCSRFRequired]` on `GET /api/github/issues`, no such attribute on
  `POST /api/github/issues` (CSRF MUST apply).

### D21. Submission rate limit: APCu-backed, 1 per user per 60s

- Database-backed rate limiter. Rejected: heavy, and the rate limit is ephemeral by design.
- No rate limit. Rejected: abuse/spam path on a server-PAT fallback is obvious.
- **Chosen:** APCu key `openregister.feature_submission:<user_id>` with 60s TTL; return 429
  with `{error: "rate_limited", retry_after: <seconds>}` when exceeded.

### D22. Spec-dir discovery: prefer `openspec/specs/`, fall back to `./specs/`

- Only `openspec/specs/`. Rejected: legacy apps pre-dating the `openspec/` convention.
- Only `./specs/`. Rejected: breaks the current canonical layout.
- **Chosen:** prefer `openspec/specs/`, fall back to `./specs/`, emit a warning when both
  are present so the ambiguity is visible.

## Risks / Trade-offs

- **GitHub read rate-limit exhaustion.** With an app-level PAT the unauthenticated 60/hr
  limit becomes 5000/hr; the 15-min cache further reduces it to a handful of real calls per
  day per instance. Mitigation: cache TTL tunable via app config; endpoint surfaces 429 with
  `reset_at`.
- **Server-PAT exhausted by spam submissions.** A bad actor could use a user without a
  personal PAT to spam the server-PAT with issues. Mitigation: per-user APCu rate limit
  (1/60s); admin can revoke/rotate the server PAT; APCu key is per user so one bad actor does
  not block others.
- **User without PAT configured is attributed weakly.** The issue appears to be authored by
  the server-PAT's GitHub identity. Mitigation: authorship prefix with the user's Nextcloud
  display name and the instance URL; prefix is idempotent so repeat submissions from the
  same user remain traceable.
- **XSS via user markdown.** Both rendered roadmap issue bodies AND the submit-modal live
  preview render markdown from untrusted sources. Mitigation: `DOMPurify` with a strict
  allowlist — no `<script>`, no `on*` handlers, no `javascript:` URLs, no `<iframe>`, no
  `<style>`; the config lives in one place in `@conduction/nextcloud-vue` and is reused by
  both the roadmap list and the preview pane; a Jest test asserts known XSS vectors are
  stripped.
- **Admin has not configured any PAT.** Features tab works, Roadmap tab shows "ask your
  admin" message, POST endpoint returns a clear error with the same remediation hint.
- **Orphan `specRef` pointing to a removed capability.** A widget/page tags itself with
  `specRef: 'foo-bar'` but the `foo-bar` capability has since been removed. Mitigation:
  manifest generator emits a warning when a `specRef` references a slug not present in
  `docs/features.json`; the Features route UI shows "capability no longer documented" when
  filtered by an unknown slug.
- **Docusaurus build breaks if `features.json` is malformed.** Mitigation: the manifest
  generator validates its own output against a JSON Schema before writing, and fails the
  build loudly (non-zero exit) if the schema is not satisfied.
- **Private or archived repositories.** Out of scope for this iteration — documented. The
  read endpoint will forward GitHub's 404; UI shows empty state with a hint.
- **Manifest generation drift.** If a spec's `## Purpose` is missing or malformed the
  generator emits a warning and skips that spec. Invariant test asserts "output is valid
  JSON with required fields for every included spec."
- **i18n of spec content.** Feature titles/summaries are not translated. Apps that must ship
  Dutch-only UX today will see English spec summaries until they translate their specs.
- **First-time shared navigation-slot component family.** No prior art in
  `@conduction/nextcloud-vue` for this slot, so API surface decisions here set the pattern.
  Mitigation: minimal surface, iterate via minor bumps.
- **Security — PAT leakage.** Both the app-level and user-level PATs MUST never appear in
  any response body, response header, log line, or error message. Added as an explicit
  requirement and a PHPUnit test.

## Migration Plan

1. **Backend (OpenRegister).** `GitHubHandler::listIssues` + `GitHubHandler::createIssue`
   (with user-PAT preferred, server-PAT fallback + attribution prefix) + new
   `GitHubIssuesController` with `index()` and `create()` actions + route registration
   (GET with `#[NoCSRFRequired]`, POST without it) + 15-min read cache + APCu submission
   rate limit + specRef label handling + PHPUnit tests + OpenAPI update.
2. **Shared library (`@conduction/nextcloud-vue`).** New `CnFeaturesAndRoadmapLink`,
   `FeaturesAndRoadmapView`, `FeaturesTab`, `RoadmapTab`, `RoadmapItem`,
   `SuggestFeatureModal`, `useSpecRef()`, strict DOMPurify config export, `marked` +
   `DOMPurify` wired as peer deps, nl/en i18n, Storybook stories, unit tests, minor bump.
3. **Build tooling (`@conduction/openspec-manifest`).** CLI that walks
   `openspec/specs/` (or `./specs/` fallback), filters by status, extracts title +
   summary, resolves `docsUrl` against `origin/HEAD` default branch, writes
   `docs/features.json` with schema validation, tests, README.
4. **Docusaurus package (`@conduction/docusaurus-features`).** New React component +
   Docusaurus plugin hook that reads `docs/features.json` and renders a public
   `/features` page with alphabetical ordering matching the in-app view.
5. **Pilot integration (OpenRegister).** Prebuild script, `/features-roadmap` route,
   mount `CnFeaturesAndRoadmapLink` above Settings gear, tag 2-3 existing widgets/pages
   with `specRef` to validate the contract, install `@conduction/docusaurus-features` in
   OpenRegister's Docusaurus site, Playwright smoke test.
6. **Docs + rollout prep.** README + migration guide + ADR-019 placeholder in hydra + note
   in `docs/adr-008-spec-annotations.md` that `specRef` is the runtime equivalent of the
   static `@spec` PHPDoc annotation.
7. **(Deferred)** Hydra ADR-019 mandating adoption.
8. **(Deferred)** Per-app adoption PRs in the other Conduction apps.

## Seed Data

This change introduces no new OpenRegister schemas or registers, so no seed data is
required. The Roadmap tab's items are fetched live from GitHub via the new proxy endpoint
and are therefore not seeded in any test dataset. The Features tab's items are computed at
build time from the host app's own `openspec/specs/` tree, so fixture data for tests is
simply a small tree of synthetic spec files inside the `@conduction/openspec-manifest`
package's test directory. For backend integration tests of `GET /api/github/issues`, use
the real public repository `ConductionNL/openregister` as the query target — it has
sufficient open-issue volume to exercise the sort + pagination paths. For frontend tests
of the submit modal, mock the POST and assert the modal closes on success. No live network
is used in CI.

## Open Questions

All of the originally-surfaced open questions have been resolved by the ten decisions
applied in this revision. No new genuinely-open questions identified; follow-ups
(ADR-019 wording, per-app rollout order, optional webpack plugin) are explicitly deferred
and tracked as non-goals of this change.
