---
status: done
---

# features-roadmap-menu Specification

## Purpose
Adds a Features & Roadmap surface to an app — a dedicated route with a Features tab listing capabilities from a generated `docs/features.json` manifest and a Roadmap tab showing live GitHub issues sorted by reaction count, plus a "Suggest feature" link to the forge's issue form. Markdown is rendered with strict DOMPurify sanitization, pipeline labels are filtered out, widgets and pages can declare a `specRef` to pre-fill and filter by capability, and admins can disable the whole feature via an app-config flag. The same manifest powers a public Docusaurus `/features` page, with a CI check keeping it in sync with the specs.
## Requirements
### Requirement: Full route, not side panel

The Features & Roadmap surface SHALL be implemented as a dedicated Vue route (e.g.
`/features-roadmap`) registered by the host app. The `NcAppNavigationSettings` entry SHALL
be an `<NcAppNavigationItem>` (exposed by `@conduction/nextcloud-vue` as
`CnFeaturesAndRoadmapLink`) that navigates to this route on click. The route view SHALL
NOT be implemented as a side panel or modal.

#### Scenario: Clicking the nav entry navigates to the route

- **WHEN** a user clicks the "Features & Roadmap" item in `NcAppNavigationSettings`
- **THEN** the browser SHALL navigate to the registered route (e.g. `/features-roadmap`)
- **AND** the route view SHALL render in the main content area, not as an overlay

### Requirement: Route entry above settings gear

The `CnFeaturesAndRoadmapLink` component SHALL be positioned as the first child of `<NcAppNavigationSettings>` in a host app's `src/navigation/MainMenu.vue` (or equivalent), above any existing `<NcAppNavigationItem>` children such as the Settings gear.

#### Scenario: Mounted above Settings in host app

- **WHEN** a host app places `<CnFeaturesAndRoadmapLink>` as the first child of
  `<NcAppNavigationSettings>` and `<NcAppNavigationItem>` for Settings as the second child
- **THEN** the Features & Roadmap row SHALL render above the Settings row in the rendered
  DOM

### Requirement: Two tabs with i18n labels

The route view SHALL render two tabs labelled "Features" and "Roadmap" in English, or
"Functies" and "Planning" in Dutch. The tab labels, empty-state messages, the
"Suggest feature" link, error messages, and toasts SHALL be translatable via Nextcloud's `t()` / `n()` helpers.
English (en) and Dutch (nl) MUST be shipped at minimum.

#### Scenario: Dutch locale shows Dutch tab labels

- **WHEN** the Nextcloud UI locale is `nl`
- **THEN** the tabs SHALL read "Functies" and "Planning"

#### Scenario: English locale shows English tab labels

- **WHEN** the Nextcloud UI locale is `en`
- **THEN** the tabs SHALL read "Features" and "Roadmap"

### Requirement: Features list ordering

Features in the Features tab SHALL render in alphabetical order by `title`, case-
insensitively, using a locale-aware comparator. Ties SHALL fall back to the source order in
`docs/features.json`. The Docusaurus features page SHALL match this ordering exactly.

#### Scenario: Alphabetical ordering

- **WHEN** `features` contains entries with titles `["Zaken", "Archivering", "mock-registers"]`
- **THEN** the rendered order in the Features tab SHALL be
  `["Archivering", "mock-registers", "Zaken"]`

### Requirement: Roadmap sorted by reaction count

Within the Roadmap tab, items SHALL be rendered in the order returned by the proxy, which
is `reactions.+1` descending. The reaction count SHALL be visible as a numeric badge on
each item. Ties SHALL fall back to `created_at` descending.

#### Scenario: Highest-reaction issue renders first

- **WHEN** the proxy returns items with `reactions.+1` values of `[7, 15, 3]`
- **THEN** the rendered order SHALL be `[15, 7, 3]`

### Requirement: Full markdown rendering with XSS protection

Each roadmap item's body SHALL be rendered as full GitHub-flavored markdown using the
`marked` library. Rendered output MUST be sanitized with `DOMPurify` using a strict
allowlist config that MUST exclude `<script>`, all `on*` attributes, `javascript:` URLs,
`<iframe>`, and `<style>`.

#### Scenario: Markdown body renders as formatted HTML

- **WHEN** a roadmap issue body is `"**bold** and *italic*\n\n- item one\n- item two"`
- **THEN** the rendered DOM SHALL contain a `<strong>` element wrapping "bold", an `<em>`
  element wrapping "italic", and a `<ul>` with two `<li>` children

#### Scenario: XSS in body is stripped

- **WHEN** a roadmap issue body is `"<script>alert('x')</script><img src=x onerror=alert(1)>"`
- **THEN** the rendered DOM SHALL NOT contain any `<script>` element
- **AND** the rendered `<img>` element (if present) SHALL NOT carry an `onerror`
  attribute

### Requirement: Label blocklist

The roadmap view SHALL filter out any issue labels whose name matches any of the
following regular expressions: `^build:`, `^code-review:`, `^security-review:`,
`^applier:`, `^retry:`, `^rebuild:`, `^fix:`, `^fix-iteration:`, `^build-retry:`,
`^ready-`, `^needs-input$`, `^yolo$`, `^openspec$`, `^agent-maxed-out$`,
`^pipeline-active$`, `^done$`, `:queued$`, `:running$`, `:pass$`, `:fail$`. Labels that
do not match any blocklist pattern SHALL render with their GitHub-native color from the
proxy response. The blocklist SHALL live in a single named constant exported by
`@conduction/nextcloud-vue` so it is documented and easy to extend.

#### Scenario: Pipeline labels are hidden

- **WHEN** an issue carries labels `["enhancement", "build:running", "ready-for-code-review", "accessibility"]`
- **THEN** the rendered label chips SHALL be exactly `["enhancement", "accessibility"]`

#### Scenario: Label retains its GitHub color

- **WHEN** an issue carries a label `{name: "enhancement", color: "a2eeef"}`
- **THEN** the rendered chip SHALL use `#a2eeef` as its background or border color

### Requirement: Widget specRef declaration

Vue widgets SHALL declare a capability reference via `defineOptions({ specRef: '<slug>' })` (Composition API) or a component option `specRef: '<slug>'` (Options API), where `<slug>` MUST be a kebab-case capability slug identical in convention to ADR-008 `@spec` PHPDoc annotations on the backend. Widgets without a `specRef` declaration are unaffected by this contract.

#### Scenario: Composition API declaration is detected

- **WHEN** a Vue widget uses `<script setup>` and calls `defineOptions({ specRef: 'catalog-management' })`
- **AND** the composable `useSpecRef()` is invoked within that widget's scope
- **THEN** `useSpecRef()` SHALL return `'catalog-management'`

### Requirement: Page specRef via route meta

The `useSpecRef()` composable SHALL support declaring a page's capability reference via the Vue Router `meta.specRef` field on the active route; when invoked inside a page component that has no component-level `specRef`, it SHALL fall back to `route.meta.specRef`.

#### Scenario: Route meta fallback

- **WHEN** the active route is defined with `meta: { specRef: 'search-indexing' }`
- **AND** the rendered component has no `specRef` option
- **THEN** `useSpecRef()` SHALL return `'search-indexing'`

### Requirement: Action menu integration

Widgets and pages that declared a `specRef` SHALL expose a "Suggest feature" item in their
`NcActions` menu, carrying the active `specRef`. The helper exposed by
`@conduction/nextcloud-vue` to inject this action into an existing `NcActions` SHALL be
opt-in (available only when a non-empty `specRef` is present).

⚠️ **WHAT THE ITEM DOES IS UNSETTLED.** It opened `SuggestFeatureModal`, and that modal is
gone (see "Suggest feature deep-link"). The helper still ships and still hands the slug to
a callback, so the descriptor is buildable, but openregister mounts nothing behind it and
no scenario below is exercised today. The obvious answer is the same forge deep-link with
the slug carried into the form, and that is a decision nobody has taken yet rather than
one this file should invent.

#### Scenario: Widget with specRef exposes the action

- **WHEN** a widget declares `specRef: 'catalog-management'` AND mounts an `NcActions` that
  wires in the shared helper
- **THEN** the action menu SHALL include an item labelled "Suggest feature"
- **AND** the descriptor SHALL carry `specRef="catalog-management"`

#### Scenario: Widget without specRef does NOT expose the action

- **WHEN** a widget has no `specRef` declaration
- **THEN** the action menu SHALL NOT include a "Suggest feature" item

### Requirement: Suggest feature deep-link

The Features & Roadmap route header SHALL carry a "Suggest feature" control that opens the
forge's feature-request issue form for the app's repository, in a new tab with
`rel="noopener noreferrer"`. It SHALL be a link rather than a button, because it navigates
away rather than opening anything in the page. It SHALL be present only when a target URL
resolves: the host's `suggestUrl` override when given, otherwise the form derived from the
app's `repo` and `forge` (GitHub by default). No feature request SHALL be composed or
submitted from inside the app.

This replaces the in-product `SuggestFeatureModal`, which nextcloud-vue removed in 2.36.4
(team decision 2026-09-04: the forge is where the conversation happens, in English). Three
requirements went with it, and they are recorded here rather than deleted silently so that
a reader of this file learns what happened rather than wondering:

- **Suggest feature modal** — the modal, its title and body fields, its markdown preview,
  and its submit gating.
- **Submission success feedback** — the "Feature request submitted" toast carrying a link
  to the created issue.
- **Submission error handling** — the 401, 429, 503 and generic-failure messages and the
  Retry button.

The behaviour those requirements asked for now belongs to the forge's own issue form,
which validates its structured fields and reports its own failures. `POST /api/github/issues`
has no caller in the UI any more; the endpoint and its guards are unchanged and out of
scope here.

#### Scenario: The header offers a link to the forge, not a form

- **WHEN** a user opens the Features & Roadmap route
- **THEN** a link labelled "Suggest feature" SHALL be visible
- **AND** its `href` SHALL be the feature-request issue form for the app's repository
- **AND** it SHALL open in a new tab with `rel="noopener noreferrer"`

#### Scenario: Nothing is submitted from the app

- **WHEN** a user activates the "Suggest feature" control
- **THEN** no POST SHALL be issued to `/api/github/issues`

### Requirement: specRef-aware filtering

The Features & Roadmap view SHALL filter its content by `specRef` when opened from a widget or page context with a known slug: the Features tab SHALL filter to features whose capability slug equals the `specRef`, and the Roadmap tab SHALL filter to issues carrying a label named exactly `specRef:<slug>`. When opened directly with no `specRef` context, no filtering SHALL be applied.

#### Scenario: Filtered from widget context

- **WHEN** the user opens the route with query parameter `?specRef=catalog-management`
- **THEN** the Features tab SHALL show only features whose slug equals
  `catalog-management`
- **AND** the Roadmap tab SHALL show only issues labelled `specRef:catalog-management`

### Requirement: Manifest generator and output location

`docs/features.json` SHALL be generated by the fleet-wide extractor
`scripts/extract-features.py` in `ConductionNL/.github` — the single writer — and SHALL be
committed to git (it MUST NOT be listed in `.gitignore`) so the same artifact powers both
the Features & Roadmap surface and the app's Docusaurus public `/features` page. This repo
SHALL NOT carry a second in-tree generator for the same file.

Generation SHALL happen at COMMIT time via the committed `.githooks/pre-commit` hook
(activated by `npm install` / `composer install`, which set `core.hooksPath .githooks`),
never from an `npm run build` hook and never from CI — CI only verifies.

The extractor's own contract — overlay-first resolution
(`openspec/features.overlay.json` wins over spec derivation), the three buyer-facing
maturities `stable` / `beta` / `soon`, title/summary extraction, and `docsUrl`
resolution — is owned where the script lives and is deliberately NOT restated here. A
copy of a contract in a repo that does not own it is a copy that drifts.

> Superseded (2026-08-16, ConductionNL/openregister#2491). This requirement replaces eight
> requirements that specified an in-tree `scripts/build-features-manifest.js` emitting
> `{schemaVersion, generatedAt, features: [...]}`. That script was wired as an npm
> `prebuild` hook, so every `npm run build` rewrote the tracked file into a shape the CI
> `Features Check` rejects — a contributor who merely built the frontend turned the next
> PR red. The script and its hook are removed; the extractor above is now the only writer.

#### Scenario: A plain build does not touch the manifest

- **WHEN** a contributor runs `npm run build` in a clean checkout
- **THEN** `git status` SHALL report no modification to `docs/features.json`

#### Scenario: Committing a spec change regenerates the manifest

- **WHEN** a commit stages a change under `openspec/specs/` or
  `openspec/features.overlay.json`
- **THEN** the `.githooks/pre-commit` hook SHALL run the canonical extractor and stage the
  regenerated `docs/features.json`

### Requirement: Docusaurus features page

The host app's Docusaurus site SHALL ship a `src/pages/features.js` page template
(copied from openregister's reference implementation) that imports `docs/features.json`
at build time and renders a public `/features` page. The page SHALL render features in
the same alphabetical order as the in-app component and SHALL match the in-app visual
style as closely as Docusaurus theming allows.

#### Scenario: Public page renders the manifest

- **WHEN** a host app copies the reference `src/pages/features.js` into its Docusaurus
  tree and builds the Docusaurus site with a non-empty `docs/features.json`
- **THEN** the built site SHALL include a `/features` page
- **AND** the rendered feature titles SHALL match `docs/features.json` in alphabetical
  order by title

### Requirement: Empty states

The Features tab and Roadmap tab SHALL render localized, muted empty-state messages for every degraded condition instead of exposing technical errors. When the feature list is empty — `docs/features.json` is an empty array, or the route received no `features_roadmap_features` initial state — both the in-app Features tab AND the Docusaurus public features page SHALL render "No features documented yet" (en) / "Nog geen functies gedocumenteerd" (nl). When the roadmap proxy returns `{items: [], hint: "github_pat_not_configured"}`, the Roadmap tab SHALL render "Roadmap currently unavailable" (en) / "Planning momenteel niet beschikbaar" (nl) with an admin-remediation hint. When the proxy returns an empty `items` array without a `hint`, the tab SHALL render "No roadmap items yet" (en) / "Nog geen planningsitems" (nl). When the proxy returns HTTP 429, the tab SHALL render "Roadmap rate-limited, try again later" (en) / "Planning tijdelijk afgeknepen, probeer het later opnieuw" (nl).

#### Scenario: Empty features manifest

- **WHEN** `docs/features.json` is `[]`
- **THEN** the Features tab SHALL render the "No features documented yet" message
- **AND** the Docusaurus `/features` page SHALL render the same message

#### Scenario: PAT not configured roadmap

- **WHEN** the proxy returns `items: []` with `hint: "github_pat_not_configured"`
- **THEN** the Roadmap tab SHALL render the "Roadmap currently unavailable" message

### Requirement: i18n

All UI strings — route title, tab labels, the "Suggest feature" link, empty states, toasts, error messages, and button labels — MUST be translatable via Nextcloud's `t()` / `n()` helpers, and Dutch (nl) plus English (en) translations MUST be shipped at minimum. The Docusaurus public page SHALL use Docusaurus's native i18n infrastructure and MUST ship nl + en at minimum.

#### Scenario: Nl locale translates all route chrome

- **WHEN** the Nextcloud UI locale is `nl` and the user opens the Features & Roadmap route
- **THEN** the route title, both tab labels and the "Suggest feature" link SHALL all
  render in Dutch

### Requirement: Link safety

All external links rendered by the in-app component and the Docusaurus public page MUST open with `target="_blank"` and `rel="noopener noreferrer"` attributes. This SHALL include feature `docsUrl` links, roadmap `html_url` links, and the "Suggest feature" link to the forge's issue form.

#### Scenario: Feature docsUrl link safety

- **WHEN** a feature has `docsUrl: "https://example.invalid/spec"`
- **THEN** the rendered DOM SHALL wrap the feature in or contain an anchor with
  `target="_blank"` and `rel="noopener noreferrer"`

### Requirement: Manifest consumption in the app

The Features & Roadmap route SHALL receive its feature list from server-provided initial
state (`features_roadmap_features`, via `IInitialState`), NOT by importing
`docs/features.json` into the JS bundle. The manifest is a repository artifact consumed by
the Docusaurus `/features` page and verified by CI; bundling it would pin the in-app list
to whenever the frontend was last built.

The route SHALL degrade to an empty list when the initial state is absent, so a
misconfigured or not-yet-wired backend renders the documented empty state rather than an
error.

> Superseded (2026-08-16, ConductionNL/openregister#2491). Previously this required a
> webpack import plus a `prebuild` npm hook. `FeaturesRoadmapIndex.vue` has always used
> `loadState()` instead, and the `prebuild` hook was the defect this change removes.

#### Scenario: The build does not bundle the manifest

- **WHEN** the production bundle is built
- **THEN** no entry chunk SHALL import `docs/features.json`

#### Scenario: Missing initial state degrades to the empty state

- **WHEN** the server provides no `features_roadmap_features` initial state
- **THEN** the Features tab SHALL render the "No features documented yet" message

### Requirement: DOMPurify config policy on remote images

The `SAFE_MARKDOWN_DOMPURIFY_CONFIG` allowlist SHALL strip all `<img>` tags whose `src`
attribute resolves to a non-relative external origin (i.e. begins with `http://`,
`https://`, `//`, or any non-`/` protocol-bearing prefix). Inline `data:` image URLs
SHALL also be stripped. Only `<img>` elements with relative `src` attributes (e.g.
`./foo.png`, `/images/bar.svg`) SHALL render — these have no provenance leakage to
external origins. This is stricter than DOMPurify's default and is required to prevent
issue authors from embedding tracking-pixel images that leak the viewer's IP, request
headers, and timing to attacker-controlled origins on every roadmap render.

The same policy SHALL apply to `<image>` (SVG) and `<picture>`/`<source>` elements.

#### Scenario: External image is stripped

- **WHEN** a roadmap issue body contains
  `<img src="https://tracker.example/pixel.gif">`
- **THEN** the rendered DOM SHALL NOT contain the `<img>` element

#### Scenario: data: URL image is stripped

- **WHEN** a roadmap issue body contains
  `<img src="data:image/png;base64,iVBORw0KGgo...">`
- **THEN** the rendered DOM SHALL NOT contain the `<img>` element

#### Scenario: Relative image is permitted

- **WHEN** a roadmap issue body contains `<img src="./assets/diagram.png">` (rare in
  GitHub issue bodies but theoretically valid)
- **THEN** the rendered DOM SHALL contain the `<img>` element verbatim

### Requirement: Manifest freshness CI check

Every host app adopting this capability SHALL keep `enable-features-extract: true` on the
shared `ConductionNL/.github` quality workflow, whose `Features Check` job runs the
canonical extractor in verify mode and asserts the regenerated `docs/features.json` is
identical to the committed file:

```sh
python .conduction-shared/scripts/extract-features.py --app-root . --check
```

The check SHALL be READ-ONLY: it fails the build on drift and SHALL NOT commit a
regenerated file back to the branch under review. Remediation is to re-commit locally,
where the `.githooks/pre-commit` hook regenerates the file.

#### Scenario: Stale manifest is caught

- **WHEN** a developer changes a spec's frontmatter, commits with the hook bypassed
  (`--no-verify`), and opens a PR
- **THEN** `Features Check` SHALL exit non-zero
- **AND** the job SHALL NOT push a corrected `docs/features.json` to the PR branch

### Requirement: Admin opt-out for the navigation entry

The host app SHALL gate the rendering of `<CnFeaturesAndRoadmapLink>` on the boolean
IAppConfig key `openregister::features_roadmap_enabled`, defaulting to `true` when the
key is absent. When the key is `false`, neither the navigation entry nor the
`/features-roadmap` route SHALL be reachable: the link SHALL be hidden and a direct
navigation to the route SHALL render a localized "This feature has been disabled by your
administrator" message instead of the tabs.

The widget-level "Suggest feature" entry points (action menu items injected into
widgets that declared `specRef`) SHALL also respect this flag — when `false`, the action
menu item SHALL be hidden. The corresponding backend endpoints (`GET` and `POST` on
`/api/github/issues`) SHALL also check the flag and return HTTP 403 with the structured
error code `feature_disabled` when invoked while the flag is `false`, so a user who
crafted a direct request cannot bypass the UI gate.

This addresses operator personas (e.g. municipal/government deployments under CISO
control) that may need to disable external-data-egress feature-request submissions for
compliance reasons without forking the codebase.

#### Scenario: Admin disables the feature

- **WHEN** the administrator sets `openregister::features_roadmap_enabled = false`
- **THEN** the navigation sidebar SHALL NOT render the Features & Roadmap entry
- **AND** a logged-in user navigating directly to `/features-roadmap` SHALL see the
  localized "This feature has been disabled by your administrator" message
- **AND** any direct call to `GET /api/github/issues` or `POST /api/github/issues` SHALL
  return HTTP 403 with body `{error: "feature_disabled"}`

#### Scenario: Default behavior

- **WHEN** the IAppConfig key `openregister::features_roadmap_enabled` is absent
- **THEN** the feature SHALL render normally (default `true`)

### Requirement: docsUrl frontmatter override validation

The manifest-builder script SHALL validate any frontmatter `docsUrl:` override before accepting it as the manifest entry's `docsUrl`. The value MUST:

1. Be a syntactically valid URL parseable by Node's `URL` constructor.
2. Use the `https:` scheme (case-insensitive). `http:`, `javascript:`, `data:`, `file:`,
   and any other scheme SHALL be rejected.
3. Have a non-empty hostname.

When validation fails, the CLI SHALL emit a stderr warning naming the spec file and the
invalid value, treat the override as absent, and fall back to the default computed
`docsUrl`. The CLI SHALL NOT abort the build on a single invalid `docsUrl`; one bad spec
SHALL NOT poison the whole manifest.

#### Scenario: javascript: URL override is rejected

- **WHEN** a spec frontmatter contains `docsUrl: javascript:alert(1)`
- **THEN** the CLI SHALL emit a stderr warning naming the spec
- **AND** the manifest entry's `docsUrl` SHALL be the default computed value (or omitted
  if no default could be resolved)

#### Scenario: http: URL override is rejected

- **WHEN** a spec frontmatter contains `docsUrl: http://example.com/foo`
- **THEN** the CLI SHALL emit a stderr warning naming the spec
- **AND** the manifest entry's `docsUrl` SHALL be the default computed value

#### Scenario: Valid https: URL override is accepted

- **WHEN** a spec frontmatter contains `docsUrl: https://docs.example.com/foo`
- **THEN** the manifest entry's `docsUrl` SHALL be `https://docs.example.com/foo` verbatim

