# Design: add-features-roadmap-menu

## Context

Two sources of truth for every Conduction app are invisible to end-users:
the `openspec/specs/*/spec.md` tree (what's built — YAML frontmatter
`status: implemented | reviewed | redirect`, H1 = capability title,
`## Purpose` section as summary) and the app's GitHub issue tracker
(roadmap — reactions act as informal voting). This change binds both into
one menu entry, one route with two tabs, plus a submission path that lets
authenticated users file feature requests straight into the repo.

`@conduction/nextcloud-vue` is the existing shared Vue library (currently
exporting only settings primitives — `CnSettingsCard`, `CnSettingsSection`)
so it's the natural home for the new component family. The build-time
spec-to-manifest tool lives in-tree as
`openregister/scripts/build-features-manifest.js`; adopting apps copy the
222-line script verbatim into their own `scripts/` tree. The Docusaurus
surface is a per-site `src/pages/features.js` template (~30 LOC) that
imports `docs/features.json` directly. Originally both surfaces were scoped
as standalone npm packages (`@conduction/openspec-manifest`,
`@conduction/docusaurus-features`) — rescoped 2026-06-12 per the W33
honest-handoffs review (see §D6 and §D19).

The backend (read proxy + submission endpoint) lives in OpenRegister and
ships in the paired `add-github-issue-proxy` change. This change depends on
it.

## Goals

- One menu entry → one dedicated route with two tabs in every Conduction app.
- Features surfaced from OpenSpec specs at build time (no runtime filesystem
  walk, no backend read path for feature metadata). Same `docs/features.json`
  powers the in-app view AND the Docusaurus public page.
- Roadmap items fetched live via OR's `github-issue-proxy` with full
  markdown rendering (XSS-sanitised) and a blocklist that hides
  pipeline/workflow labels.
- Submission via the same proxy — preferring the user's own PAT for
  authorship, falling back to the server PAT with attribution.
- Widgets/pages declaratively opt into a `specRef` (ADR-008 capability-slug
  convention) so the "Suggest feature" action appears contextually wherever
  the user is working.
- Pilot integration on OpenRegister itself in this change.

## Non-Goals

- The OR backend — separate change.
- GitHub Discussions integration.
- Fleet rollout to other Conduction apps (separate ADR-019).
- Webpack-plugin wrapper for the manifest generator.

## Decisions

### D3. Features source: build-time manifest from `openspec/specs/`

Runtime endpoint reading specs from the installed app's filesystem rejected
(couples backend to deployment layout). Hand-authored JSON curated per
release rejected (guaranteed drift). **Chosen:** the in-tree
`scripts/build-features-manifest.js` runs as `prebuild`, writes
`docs/features.json`; both webpack/vite and Docusaurus consume the same
file.

### D4. Feature filter: `status: implemented` OR `status: reviewed`

`implemented` only rejected (`reviewed` is strictly more finished).
Include `redirect` rejected (re-homing markers, not features). Include
drafts / planned rejected (Features tab must show what actually ships).
**Chosen:** `status ∈ {implemented, reviewed}`.

### D5. Docs link default

Require every spec to add a `docsUrl:` frontmatter key rejected
(high-friction migration). No link at all rejected. **Chosen:**
auto-compute against the repo's default branch resolved from
`origin/HEAD` (NOT the currently checked-out branch). Specs can opt in
to an override via a `docsUrl:` frontmatter key (validated as `https://`
URL with non-empty hostname; rejection → use computed default).

### D6. Manifest generator delivery: in-tree script, copied per app

Ship as dev-dependency inside `@conduction/nextcloud-vue` rejected
(couples build tooling to UI library; not every consumer has OpenSpec).
Original choice (D6, 2026-02): publish `@conduction/openspec-manifest`
npm package — rejected on review 2026-06-12 (W33 honest-handoffs): the
publishable contract was never landed, and ~600 LOC of release plumbing
(semantic-release, README, peer-dep matrix, Jest fixtures, CHANGELOG,
version bumps in every consumer) for a 222-line script is gold-plating
per ADR-022 (apps-consume-or-abstractions). DRY-across-apps concern
mitigated by: (a) the script has zero divergence drivers — the contract
is fixed by the spec.md format and the same `docs/features.json` shape —
and (b) the per-app `manifest:check` CI step would catch any local drift
the moment a frontmatter format change lands fleet-wide. **Chosen
(2026-06-12):** the canonical script lives at
`openregister/scripts/build-features-manifest.js`; adopting apps copy it
verbatim and wire the `prebuild` + `manifest:check` npm scripts. If the
script needs evolution (e.g. supporting a third VCS host beyond
github.com + codeberg.org), the change lands in OR first and is
back-propagated to adopters by re-copying the updated file — a manual
sync no different from how shared workflows in `Conduction/.github`
propagate today.

### D7. Component home: `@conduction/nextcloud-vue`

The new `CnFeaturesAndRoadmapLink`, `FeaturesAndRoadmapView`,
`SuggestFeatureModal`, `useSpecRef`, `useSuggestFeatureAction`,
`SAFE_MARKDOWN_DOMPURIFY_CONFIG`, and `ROADMAP_LABEL_BLOCKLIST` exports
establish a new component category: "cross-app surfaces."

### D8. i18n

Component ships with Dutch (nl) and English (en) strings for all chrome
(route title, tab labels, modal fields, empty states, toasts, errors).
Feature titles + summaries pass through unchanged.

### D11. Pilot scope: OpenRegister only

Ship adoption into every Conduction app in this single change rejected
(huge blast radius for a first-time shared component). **Chosen:** wire
only OpenRegister; fleet rollout deferred to hydra ADR-019.

### D12. UI pattern: full route, not side panel

Side panel (`NcAppSidebar`) considered — too cramped for browsing +
submitting + awkward on mobile. Modal rejected — too restrictive for
two-tab browsing. **Chosen:** dedicated Vue route (e.g.
`/features-roadmap`). The `NcAppNavigationSettings` entry is an
`<NcAppNavigationItem>` that navigates to this route.

### D13. Submission destination: GitHub Issues (not Discussions)

User explicitly chose Issues. Direct roadmap visibility: a new submission
shows up immediately in the same Roadmap tab where it was filed.

### D14 (UI half). Authorship UX

The "Suggest feature" modal POSTs `{repo, title, body, specRef?}` to
OR's `POST /api/github/issues` (with the Nextcloud CSRF token). Authorship
fallback (user-PAT preferred, server-PAT fallback with sanitised
attribution prefix) is OR's responsibility — see `add-github-issue-proxy`.

### D15. Markdown rendering: `marked` + `DOMPurify`

Plain-text rendering rejected (raw `*`, `#`, code fences look broken).
Iframe-embed GitHub's render rejected (no style control, slow, breaks
dark mode). First-paragraph-only rejected by the user. **Chosen:**
`marked` to parse GHFM, `DOMPurify` with a strict allowlist (no
`<script>`, no `on*`, no `javascript:` URLs, no `<iframe>`, no `<style>`)
exported as `SAFE_MARKDOWN_DOMPURIFY_CONFIG` from
`@conduction/nextcloud-vue`. Image policy: external `https://` / `data:`
/ `//` / `http://` image `src` attributes stripped via
`uponSanitizeAttribute` (only relative `src` values pass through); SVG
`<image href="https://...">` likewise stripped.

### D16. Label filter strategy: explicit blocklist

Allowlist of known user-facing labels rejected (new labels silently
disappear until manually added). Show everything rejected (Hydra
pipeline/workflow labels are internal noise). **Chosen:** explicit
blocklist as a regex set exported as `ROADMAP_LABEL_BLOCKLIST: RegExp[]`
covering: `^build:`, `^code-review:`, `^security-review:`, `^applier:`,
`^retry:`, `^rebuild:`, `^fix:`, `^fix-iteration:`, `^build-retry:`,
`^ready-`, `^needs-input$`, `^yolo$`, `^openspec$`, `^agent-maxed-out$`,
`^pipeline-active$`, `^done$`, `:queued$`, `:running$`, `:pass$`,
`:fail$`. Anything else passes through and renders with its native
GitHub colour.

### D17. `features.json` location: committed to git at `docs/features.json`

`src/features.json` + gitignored (original design) rejected — the same
file must power the public Docusaurus page; cannot ship a public page
from a gitignored artifact. Two files rejected (guaranteed to drift).
**Chosen:** single committed `docs/features.json`. The in-tree script is
idempotent + deterministic; recommended CI snippet
`node scripts/build-features-manifest.js --check` catches stale manifests
in PRs.

### D18. Widget/page `specRef` contract: declarative, ADR-008-aligned

Imperative `registerSpecRef('slug')` in `mounted()` rejected (harder to
audit at rest, easy to forget). **Chosen:** declarative — widgets use
`defineOptions({ specRef: 'slug' })` (Composition API) OR a component
option `specRef: 'slug'` (Options API); pages use Vue Router
`meta: { specRef: 'slug' }`. The slug is a kebab-case capability slug —
identical convention to ADR-008 `@spec` PHPDoc annotations on the
backend. `useSpecRef()` reads either source. The modal validates any
pre-filled value against the same regex as the backend
(`^[a-z0-9][a-z0-9-]*[a-z0-9]$`, ≤ 80 chars); on mismatch, clear the
hidden field + console warn rather than POSTing a value the backend
will reject.

### D19. Docusaurus integration: per-site `src/pages/features.js` template

In-app component rendered into Docusaurus rejected (Vue-in-Docusaurus
tooling overhead not worth it; Docusaurus is React). Original choice
(D19, 2026-02): publish `@conduction/docusaurus-features` npm package
exporting `<FeaturesPage />` + a Docusaurus plugin hook — rejected on
review 2026-06-12 (W33 honest-handoffs): same ~600 LOC of release
plumbing as §D6 for an even smaller payload (the page is ~30 LOC of
React). DRY-across-apps concern mitigated by: (a) `docs/features.json`
is already a static asset of the docs build, so a Docusaurus plugin hook
buys nothing the standard `import` syntax can't provide; (b) per-site
copies let each app tune the `<Translate>` strings and the layout to its
own Docusaurus theme without negotiating cross-app theming props on the
shared package. **Chosen (2026-06-12):** OR ships the reference
`src/pages/features.js` in its own Docusaurus tree; adopting apps copy
the file (~30 LOC), drop in their own translations, and ship. The page
is exposed at `/features` by Docusaurus's file-based routing.

### D22. Spec-dir discovery: prefer `openspec/specs/`, fall back to `./specs/`

Only `openspec/specs/` rejected (breaks legacy apps pre-dating the
convention). Only `./specs/` rejected (breaks current canonical layout).
**Chosen:** prefer `openspec/specs/`, fall back to `./specs/`, emit a
warning when both are present so the ambiguity is visible. Exit 0 with
empty manifest when neither exists.

## Migration Plan

Existing apps adopt by copying
`openregister/scripts/build-features-manifest.js` into their own
`scripts/` tree, wiring `"prebuild": "node scripts/build-features-manifest.js"`
and `"manifest:check": "node scripts/build-features-manifest.js --check"`,
bumping `@conduction/nextcloud-vue` to the version that ships the new
component family, registering the route, mounting
`<CnFeaturesAndRoadmapLink/>`, and copying OR's
`docusaurus/src/pages/features.js` into their own Docusaurus tree (then
translating the chrome strings). OpenRegister itself executes this
migration as the pilot in this change. Other apps are deferred to
ADR-019 + per-app PRs.
