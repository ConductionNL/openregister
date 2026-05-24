# Integration: XWiki (External Knowledge)

## Problem

XWiki is widely used in European government as a structured knowledge platform. Teams have migrated from Confluence / proprietary wikis and need OR objects to reference XWiki pages (procedure documents, legal interpretations, policy notes) — the inverse of Collectives (which is native NC). Today this leaf is **partial** per the 2026-05-24 registry audit — the 524-line `XwikiProvider` backend (with full external auth surface) works and returns real linked XWiki pages, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnXwikiTab` / `CnXwikiCard` (page tree, breadcrumbs). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and Decidesk have no working integration UI path and reinvent external-wiki linkage locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: 524-line `XwikiProvider`, full external auth surface — returns real XWiki pages via OpenConnector
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (page tree, breadcrumbs)
- **Target NC class(es)**: external — routed via OpenConnector source `xwiki`; `XwikiProvider::isEnabled()` mirrors `IAppManager::isInstalled('openconnector')`
- **Storage strategy**: `external`
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Required NC app:** `openconnector` (it carries the `xwiki` source + credentials). The original spec said `null`; changed to `openconnector` so the admin Integrations page reports it accurately. `ExternalIntegrationRouter` still degrades gracefully if OpenConnector is absent or the source is missing.
- **Required OpenConnector source:** an XWiki connector with credentials (Basic auth or OAuth depending on XWiki version) — import the template at `docs/Integrations/xwiki-openconnector-source.yaml`
- **Relationship to `integration-collectives`**: Collectives covers the native-NC knowledge case. XWiki covers the external-knowledge-platform case. Both can coexist; consuming apps choose based on customer setup.

## Proposed Solution

`XwikiProvider` declares `storage='external'` and references an `xwiki` OpenConnector source. Tab shows linked pages with titles, breadcrumb, last-modified. Widget on detail-page renders page preview (first N chars of XWiki-rendered content). Reference-property renders page chip. Provider continues to mirror `IAppManager::isInstalled('openconnector')` for `isEnabled()` and falls back to `IntegrationHealth::missingApp('openconnector')` when the OpenConnector app is absent or the `xwiki` source is unconfigured.

## Scope

**In scope:** `XwikiProvider`, OpenConnector source config template, tab (link by URL or page path, display with breadcrumb), widget (4 surfaces, detail-page shows preview), registration, tests, nl+en.

**Out of scope:** Page editing (goes to XWiki); XWiki-side linking; XWiki macro rendering beyond basic text preview; XWiki access control inspection.

## Acceptance criteria

- [x] Tab lists linked pages with titles + breadcrumb — `CnXwikiTab.spec.js` (list fetch + breadcrumb rendering; `breadcrumb` drops the last element which is the title)
- [x] Detail-page widget shows text preview of page content — `CnXwikiCard.spec.js` (detail-page surface: HTML stripped to text, `<script>` body removed, macro markup inert, ~500-char truncation, "Open in XWiki" link)
- [x] User can link by URL or wiki page path — `CnXwikiTab.spec.js` (POST body is `{ reference: '<full URL>' }`); the OpenConnector source's `create` endpoint resolves it to a canonical `Space.Page`
- [x] Reference-property `referenceType: 'xwiki'` renders page chip — `CnXwikiCard.spec.js` (`single-entity` surface renders a title+breadcrumb chip from `value`, with a minimal-chip fallback on lookup failure)
- [x] Auth expiry surfaces clearly — `CnXwikiTab.spec.js` (a 503 with an auth `reason` → reconnect banner; a 503 without → generic unavailable banner)
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real OCA/OpenConnector imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only) — this provider routes external via OpenConnector; the import is the OpenConnector source-call helper, not an `OCA\Xwiki\…` namespace which does not exist
- [ ] `health()` returns `IntegrationHealth::missingApp('openconnector')` when OpenConnector absent (or the `xwiki` source missing); never throws
- [ ] PHPUnit tests cover: happy-path (OpenConnector + source configured + linked), absent-app (graceful empty), empty-result (source configured, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnXwikiTab` + `CnXwikiCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
- [ ] Provider appears in registry when OpenConnector source `xwiki` configured — verified at the unit level (`XwikiProviderTest`); the **live** runtime check (registry populated, admin Integrations row, Articles tab) is deferred until the umbrella + leaf PRs merge and land in a deployed Nextcloud with OpenConnector + an `xwiki` source configured
