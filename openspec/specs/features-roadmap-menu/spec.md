---
status: done
---

# features-roadmap-menu Specification

## Purpose
Adds a Features & Roadmap surface to an app — a dedicated route with a Features tab listing capabilities from a generated `docs/features.json` manifest and a Roadmap tab showing live GitHub issues sorted by reaction count, plus a "Suggest feature" modal that submits new requests. Markdown is rendered with strict DOMPurify sanitization, pipeline labels are filtered out, widgets and pages can declare a `specRef` to pre-fill and filter by capability, and admins can disable the whole feature via an app-config flag. The same manifest powers a public Docusaurus `/features` page, with a CI check keeping it in sync with the specs.
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
"Functies" and "Planning" in Dutch. The tab labels, empty-state messages, modal fields,
error messages, and toasts SHALL be translatable via Nextcloud's `t()` / `n()` helpers.
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
`<iframe>`, and `<style>`. The same sanitizer configuration SHALL be used by the
`SuggestFeatureModal` live preview.

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
`NcActions` menu. Clicking the item SHALL open the `SuggestFeatureModal` pre-filled with
the active `specRef` in a hidden field. The helper exposed by `@conduction/nextcloud-vue`
to inject this action into an existing `NcActions` SHALL be opt-in (available only when a
non-empty `specRef` is present).

#### Scenario: Widget with specRef exposes the action

- **WHEN** a widget declares `specRef: 'catalog-management'` AND mounts an `NcActions` that
  wires in the shared helper
- **THEN** the action menu SHALL include an item labelled "Suggest feature"
- **AND** clicking it SHALL open `SuggestFeatureModal` with hidden field
  `specRef="catalog-management"` pre-populated

#### Scenario: Widget without specRef does NOT expose the action

- **WHEN** a widget has no `specRef` declaration
- **THEN** the action menu SHALL NOT include a "Suggest feature" item

### Requirement: Suggest feature modal

A modal form `SuggestFeatureModal` SHALL be available from (a) the Features & Roadmap
route header as a "Suggest feature" button, and (b) any widget or page that declared a
`specRef` via its action menu. The modal SHALL include: a required title field (3-200
chars), a required markdown body textarea (min 10 chars), a live preview toggle that
renders the body through the same `marked` + `DOMPurify` pipeline as roadmap items, a
Submit button, and a Cancel button. When launched from a `specRef` context, the modal
SHALL include the `specRef` as a hidden field. Submit SHALL POST to `/api/github/issues`
with the CSRF token included.

#### Scenario: Route-header launch has no specRef

- **WHEN** a user clicks "Suggest feature" from the route header
- **THEN** the modal SHALL open without any pre-filled `specRef`

#### Scenario: Widget launch pre-fills specRef

- **WHEN** a user clicks "Suggest feature" from a widget with `specRef: "catalog-management"`
- **THEN** the modal SHALL open with hidden field `specRef="catalog-management"`

#### Scenario: Submit requires title and body

- **WHEN** a user opens the modal and submits with empty title or a body shorter than 10
  chars
- **THEN** the Submit button SHALL be disabled OR SHALL show inline validation errors
- **AND** no POST SHALL be issued

### Requirement: Submission success feedback

On a successful submission (HTTP 201 from the endpoint), the modal SHALL close and a
Nextcloud toast SHALL appear with the localized text "Feature request submitted" (en) /
"Functieverzoek verzonden" (nl) and a clickable link to the issue's `html_url` opening in
a new tab with `rel="noopener noreferrer"`.

#### Scenario: Success toast contains link

- **WHEN** the submission endpoint responds HTTP 201 with `{number: 42, html_url: "https://github.com/.../42"}`
- **THEN** the modal SHALL close
- **AND** a toast SHALL appear containing the localized success message AND a link whose
  `href` equals `https://github.com/.../42` and whose `target` is `_blank`

### Requirement: Submission error handling

The modal SHALL handle submission errors as follows:

- On HTTP 401, the UI SHALL show a localized "Please sign in to submit a feature request"
  message and SHALL NOT retry automatically.
- On HTTP 429, the UI SHALL show a localized "Please wait <N>s before submitting another
  request" message using the `retry_after` value from the response body.
- On HTTP 503 with `error: "github_pat_not_configured"`, the UI SHALL show a localized
  "Feature submission is not configured on this instance — please ask your administrator"
  message.
- On HTTP 500, network error, or any other failure mode, the UI SHALL show a localized
  "Submission failed, try again" message and SHALL surface a Retry button that re-attempts
  the POST with the same title, body, and specRef.

#### Scenario: Rate-limited submission

- **WHEN** the endpoint responds HTTP 429 with body `{error: "rate_limited", retry_after: 45}`
- **THEN** the modal SHALL show the rate-limit message containing "45" as the remaining
  seconds

#### Scenario: Generic failure offers retry

- **WHEN** the endpoint responds HTTP 500
- **THEN** the modal SHALL show the generic failure message
- **AND** a Retry button SHALL be visible

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

All UI strings — route title, tab labels, modal field labels, modal placeholders, empty states, toasts, error messages, and button labels — MUST be translatable via Nextcloud's `t()` / `n()` helpers, and Dutch (nl) plus English (en) translations MUST be shipped at minimum. The Docusaurus public page SHALL use Docusaurus's native i18n infrastructure and MUST ship nl + en at minimum.

#### Scenario: Nl locale translates all route chrome

- **WHEN** the Nextcloud UI locale is `nl` and the user opens the Features & Roadmap route
- **THEN** the route title, both tab labels, the "Suggest feature" button, and the
  modal's field labels SHALL all render in Dutch

### Requirement: Link safety

All external links rendered by the in-app component and the Docusaurus public page MUST open with `target="_blank"` and `rel="noopener noreferrer"` attributes. This SHALL include feature `docsUrl` links, roadmap `html_url` links, and links in the success toast returned after a submission.

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

The `SuggestFeatureModal` live-preview pane SHALL use the same configuration so the
preview matches what will eventually render on the roadmap.

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

The `SuggestFeatureModal`'s widget-level entry points (action menu items injected into
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

