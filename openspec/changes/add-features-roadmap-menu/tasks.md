# Tasks: add-features-roadmap-menu

## 1. Backend — OpenRegister (extends existing GitHubHandler)

- [ ] 1.1 Add `listIssues(string $owner, string $repo, string $state = 'open', string $sort = 'reactions-+1', int $perPage = 30): array` method to `lib/Service/Configuration/GitHubHandler.php`, reusing the existing Guzzle client, user-agent, and rate-limit error handling.
- [ ] 1.2 Add `createIssue(string $owner, string $repo, string $title, string $body, ?string $specRef, string $userId): array` method to `GitHubHandler` with user-PAT preferred (IConfig `openregister::github_token`) and server-PAT fallback (IAppConfig `openregister::github_api_token`). When falling back, prepend the attribution prefix `> Submitted by **<display_name>** via <instance_url>\n\n---\n\n` to the body sent to GitHub.
- [ ] 1.3 Create `lib/Controller/GitHubIssuesController.php` with:
  - `index()` action mapped to `GET /api/github/issues` — proxies to `listIssues()`.
  - `create()` action mapped to `POST /api/github/issues` — validates title (3-200 chars), body (>=10 chars), repo regex `^[\w.-]+/[\w.-]+$`, applies rate limit, delegates to `createIssue()`.
- [ ] 1.4 Register both routes in `appinfo/routes.php`. Apply attribute declarations per ADR-005: GET carries `#[NoAdminRequired]` + `#[NoCSRFRequired]`; POST carries `#[NoAdminRequired]` but NOT `#[NoCSRFRequired]` (CSRF MUST apply).
- [ ] 1.5 Implement in-memory TTL cache (15 min) keyed by `(repo, state, sort, per_page)` for GET only; set `X-OpenRegister-GitHub-Cache: HIT|MISS` response header.
- [ ] 1.6 Implement APCu-backed per-user rate limit for POST — key `openregister.feature_submission:<user_id>`, 60s TTL; return HTTP 429 with `{error: "rate_limited", retry_after: <seconds>}` when exceeded.
- [ ] 1.7 Graceful degradation: GET returns HTTP 200 `{items: [], hint: "github_pat_not_configured"}` when no app-level PAT set; POST returns HTTP 503 `{error: "github_pat_not_configured"}` when neither user nor app-level PAT is configured.
- [ ] 1.8 specRef handling in `create()`: when provided, append `\n\n---\nRelated capability: `<specRef>`\n` to body AND apply `specRef:<slug>` label on the created issue.
- [ ] 1.9 Map GitHub rate-limit (403 with `X-RateLimit-Remaining: 0`, or 429) to HTTP 429 with `{error: "github_rate_limited", reset_at: "<ISO-8601>"}` derived from `X-RateLimit-Reset`.
- [ ] 1.10 Strip sensitive fields from issue payloads before returning (keep: `number`, `title`, `body`, `html_url`, `user.login`, `user.avatar_url`, `reactions.total_count`, `reactions.+1`, `created_at`, `updated_at`, `labels[].{name,color}`); drop everything else.
- [ ] 1.11 Add PHPUnit tests for the controller and both handler methods covering: GET success, GET graceful-degraded, GET cache HIT, GET rate-limit mapping, GET invalid repo/per_page rejection, POST success with user PAT (no prefix), POST success with server PAT (with prefix), POST with specRef (body suffix + label), POST rate limit 429, POST missing-both-PATs 503, POST validation errors (title/body/repo), and PAT-leak assertion — the placeholder `YOUR_API_KEY_HERE` must not appear in any response body, header, log, or cache entry.
- [ ] 1.12 Update `docs/openapi.json` (or equivalent) with both endpoints, their request/response schemas, and all documented error statuses; use `YOUR_API_KEY_HERE` and nil UUID `00000000-0000-0000-0000-000000000000` in examples.
- [ ] 1.13 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any findings in the new files AND any pre-existing issues encountered.

## 2. Shared library — @conduction/nextcloud-vue

- [ ] 2.1 Create `CnFeaturesAndRoadmapLink.vue` — an `NcAppNavigationItem` that navigates to `/features-roadmap` (route name is a prop so apps can customize).
- [ ] 2.2 Create route-level view `FeaturesAndRoadmapView.vue` with tabs (Features, Roadmap) and a header "Suggest feature" button that opens `SuggestFeatureModal`.
- [ ] 2.3 Sub-component `FeaturesTab.vue` — alphabetical list by title, feature docsUrl links open with `target="_blank" rel="noopener noreferrer"`, empty-state message when features array is empty.
- [ ] 2.4 Sub-component `RoadmapTab.vue` — reaction-sorted list; fetches from `/index.php/apps/openregister/api/github/issues`; renders each via `RoadmapItem`; handles empty/rate-limited/PAT-not-configured states with localized messages.
- [ ] 2.5 Sub-component `RoadmapItem.vue` — renders one issue: title, submitter login + avatar, reaction count, relative created time, body rendered via `marked` + `DOMPurify` (strict config), filtered label chips using the blocklist constant.
- [ ] 2.6 Sub-component `SuggestFeatureModal.vue` — title field (3-200 chars, required), body markdown textarea (>=10 chars, required), live preview toggle reusing the same `marked` + `DOMPurify` pipeline, hidden `specRef` field, Submit + Cancel buttons. On Submit, POSTs to `/api/github/issues` with the Nextcloud CSRF token.
- [ ] 2.7 Composable `useSpecRef()` — reads `specRef` from (a) the nearest ancestor component's `$options.specRef` (Options API) or `defineOptions({ specRef })` (Composition API), with (b) Vue Router `route.meta.specRef` as fallback. Export as part of the package's public API.
- [ ] 2.8 Mixin / helper `useSuggestFeatureAction()` that returns an `NcActions` item ("Suggest feature") when a non-empty `specRef` is present; opt-in by importing and calling from a widget's NcActions.
- [ ] 2.9 Export a strict `DOMPurify` configuration constant (`SAFE_MARKDOWN_DOMPURIFY_CONFIG`) from the package so consumers of user-generated markdown reuse one allowlist. Disallow `<script>`, all `on*` attributes, `javascript:` URLs, `<iframe>`, and `<style>`.
- [ ] 2.10 Export a named constant `ROADMAP_LABEL_BLOCKLIST: RegExp[]` containing the 20 blocklist patterns from D16; document it in the package README.
- [ ] 2.11 i18n files `l10n/nl.json` + `l10n/en.json` for: route title, tab labels, empty states (all three roadmap degraded conditions), toast messages (success, rate-limit, PAT-not-configured, generic error), modal labels (title, body, preview, submit, cancel), validation error messages.
- [ ] 2.12 `marked` and `dompurify` wired as peer dependencies in `package.json`.
- [ ] 2.13 Storybook stories for each component, including three `RoadmapTab` fixtures: happy-path, empty + PAT-not-configured, and rate-limited.
- [ ] 2.14 Vue unit tests (Jest + @vue/test-utils) covering: `useSpecRef` with component option, `useSpecRef` with route meta fallback, alphabetical feature sort, reaction-sort of roadmap items, label blocklist filtering, XSS vectors stripped by DOMPurify, modal validation (title length, body length), modal submit POST body shape, modal success-toast link, modal rate-limit error rendering.
- [ ] 2.15 Export everything from `src/index.js`; update package README with usage example for widgets opting into `specRef` and for apps mounting the navigation link + route.
- [ ] 2.16 Bump minor version + CHANGELOG entry.
- [ ] 2.17 Run `npm run lint` and `npm test`; fix any findings including pre-existing issues in touched files.

## 3. Build tooling — @conduction/openspec-manifest (CLI only)

- [ ] 3.1 Scaffold new npm package `@conduction/openspec-manifest` with `package.json`, README, license aligned with Conduction house style. Bin entry `openspec-manifest` with subcommand `build`.
- [ ] 3.2 Implement spec discovery (`lib/discovery.js`): prefer `./openspec/specs/*/spec.md`; fall back to `./specs/*/spec.md` when `./openspec/` is absent; emit stderr warning identifying both paths when both are present; exit 0 with empty manifest when neither exists.
- [ ] 3.3 Implement frontmatter parser (`lib/parser.js`) using `gray-matter`; skip specs without frontmatter or missing `status:` key with a console warning.
- [ ] 3.4 Implement status filter: include only `status ∈ {implemented, reviewed}`; skip others without warning (they are expected to be excluded).
- [ ] 3.5 Implement extractor: first H1 after frontmatter → `title`; enclosing directory name → `slug`; first paragraph under the first `## Purpose` section → `summary`; skip with warning if either title or purpose is missing.
- [ ] 3.6 Implement docsUrl resolver: if frontmatter `docsUrl:` is set, use verbatim; else resolve git remote (`origin`) + default branch via `git symbolic-ref refs/remotes/origin/HEAD` (NOT the currently checked-out branch) and build `https://github.com/<owner>/<repo>/blob/<defaultBranch>/openspec/specs/<slug>/spec.md`. Omit + warn when remote is not GitHub or when default branch cannot be resolved.
- [ ] 3.7 Implement writer (`lib/writer.js`): sort entries alphabetically by title (locale-aware, case-insensitive), emit `docs/features.json` (NOT `src/features.json`) as pretty-printed JSON with shape `{schemaVersion: 1, generatedAt: "<ISO>", features: [...]}` and two-space indent.
- [ ] 3.8 Implement output-schema validation — fail the build with a non-zero exit code and a clear error message when the emitted object does not satisfy the shape.
- [ ] 3.9 Implement `bin/openspec-manifest.js` CLI with `build` subcommand; honor `--cwd` flag for testability.
- [ ] 3.10 Jest tests with synthetic spec fixtures: well-formed spec, missing frontmatter, missing `## Purpose`, wrong status, docsUrl frontmatter override, missing git remote, legacy `./specs/` layout, both directories present (warning emitted), slug derivation from directory, determinism (two runs byte-identical except for `generatedAt`), output schema validation fails on malformed shape.
- [ ] 3.11 Package README: explain `prebuild` integration, the `docs/features.json` location (committed!), and how it is consumed by both the app bundle and `@conduction/docusaurus-features`.
- [ ] 3.12 Publish package and wire into CI.

## 4. Docusaurus package — @conduction/docusaurus-features (new)

- [ ] 4.1 Scaffold new npm package `@conduction/docusaurus-features` with React and Docusaurus peer deps; set up TypeScript build.
- [ ] 4.2 Implement `<FeaturesPage />` React component that loads `docs/features.json` at Docusaurus build time (via a Docusaurus plugin hook or `useGlobalData`).
- [ ] 4.3 Render features in alphabetical order by title, matching the in-app component; render `summary` as plain text (no markdown) and `docsUrl` as an anchor with `target="_blank" rel="noopener noreferrer"`.
- [ ] 4.4 Visual parity with the in-app component within Docusaurus theming (card layout, capability/status badges, accessible headings).
- [ ] 4.5 Export a Docusaurus plugin function `createFeaturesPlugin(options)` that registers a `/features` route.
- [ ] 4.6 Ship nl + en translations using Docusaurus i18n.
- [ ] 4.7 Jest tests with synthetic manifest fixtures; snapshot tests for rendered output.
- [ ] 4.8 Package README with a complete `docusaurus.config.js` integration example.

## 5. Pilot integration — OpenRegister itself

- [ ] 5.1 Add `@conduction/openspec-manifest` as a devDependency; wire `"prebuild": "openspec-manifest build"` in `package.json`.
- [ ] 5.2 Verify `docs/features.json` is regenerated on `npm run build`; confirm it is committed (NOT in `.gitignore`).
- [ ] 5.3 Add `/features-roadmap` route to the Vue router; mount `FeaturesAndRoadmapView` as its component.
- [ ] 5.4 Mount `<CnFeaturesAndRoadmapLink />` as the first child of `<NcAppNavigationSettings>` in `src/navigation/MainMenu.vue`, above the existing Settings gear.
- [ ] 5.5 Bump OpenRegister's dependency on `@conduction/nextcloud-vue` to the version that ships the new component family.
- [ ] 5.6 Demo: tag 2-3 existing widgets or pages with `specRef` (either `defineOptions({ specRef })` or `meta: { specRef }`) and wire the `useSuggestFeatureAction()` helper into their `NcActions` to validate the end-to-end contract.
- [ ] 5.7 Install `@conduction/docusaurus-features` in OpenRegister's Docusaurus site; add the plugin and confirm the `/features` page renders.
- [ ] 5.8 Add a Playwright smoke test: boot OpenRegister, open the navigation sidebar, assert the Features & Roadmap row is visible above the Settings row, click it, assert both tabs render, open the Suggest modal, fill in a valid title + body, submit, assert success toast with issue link.
- [ ] 5.9 Take before/after screenshot pair of the navigation sidebar + the new route for the PR description.

## 6. Documentation + rollout prep

- [ ] 6.1 README update in OpenRegister explaining the Features & Roadmap feature: admin section (how to configure the server PAT and when user PATs are used), developer section (how to declare `specRef` on a widget/page, how to mount the link + route in a new app).
- [ ] 6.2 Migration guide under OpenRegister's `docs/` for other Conduction apps to adopt (separate PRs, not this change): add devDep, add prebuild script, bump nextcloud-vue dep, register route, mount nav link, install Docusaurus plugin, common gotchas (private repos, missing PAT, specs with status=proposed, orphan specRef).
- [ ] 6.3 Placeholder stub in `hydra/openspec/` (or note in the proposal) that ADR-019 will mandate adoption by every Conduction app — explicitly marking this change as the PILOT, not the rollout.
- [ ] 6.4 Document the label blocklist in the `@conduction/nextcloud-vue` README — the named constant, the 20 patterns, and instructions for extending it.
- [ ] 6.5 If `docs/adr-008-spec-annotations.md` exists in OpenRegister (or should be created), record that `specRef` (Vue `defineOptions` / route `meta`) is the runtime frontend equivalent of the static `@spec` PHPDoc annotation convention, and that both SHALL use the same kebab-case capability slug.
- [ ] 6.6 Announce the new component family + endpoint in the internal `#conduction-frontend` channel once the library minor is released.
