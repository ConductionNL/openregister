# Integration: XWiki (External Knowledge)

## Problem

XWiki is widely used in European government as a structured knowledge platform. Teams have migrated from Confluence / proprietary wikis and need OR objects to reference XWiki pages (procedure documents, legal interpretations, policy notes) — the inverse of Collectives (which is native NC).

## Context

- **Backend:** greenfield — external, routed via OpenConnector
- **Required NC app:** `openconnector` (it carries the `xwiki` source + credentials; `XwikiProvider::isEnabled()` mirrors `IAppManager::isInstalled('openconnector')`). The original spec said `null`; changed to `openconnector` so the admin Integrations page reports it accurately. `ExternalIntegrationRouter` still degrades gracefully if OpenConnector is absent or the source is missing.
- **Required OpenConnector source:** an XWiki connector with credentials (Basic auth or OAuth depending on XWiki version) — import the template at `docs/Integrations/xwiki-openconnector-source.yaml`
- **Storage:** `external`
- **Depends on:** `pluggable-integration-registry`
- **Relationship to `integration-collectives`**: Collectives covers the native-NC knowledge case. XWiki covers the external-knowledge-platform case. Both can coexist; consuming apps choose based on customer setup.

## Proposed Solution

`XwikiProvider` declares `storage='external'` and references an `xwiki` OpenConnector source. Tab shows linked pages with titles, breadcrumb, last-modified. Widget on detail-page renders page preview (first N chars of XWiki-rendered content). Reference-property renders page chip.

## Scope

**In scope:** `XwikiProvider`, OpenConnector source config template, tab (link by URL or page path, display with breadcrumb), widget (4 surfaces, detail-page shows preview), registration, tests, nl+en.

**Out of scope:** Page editing (goes to XWiki); XWiki-side linking; XWiki macro rendering beyond basic text preview; XWiki access control inspection.

## Acceptance criteria

- [x] Tab lists linked pages with titles + breadcrumb — `CnXwikiTab.spec.js` (list fetch + breadcrumb rendering; `breadcrumb` drops the last element which is the title)
- [x] Detail-page widget shows text preview of page content — `CnXwikiCard.spec.js` (detail-page surface: HTML stripped to text, `<script>` body removed, macro markup inert, ~500-char truncation, "Open in XWiki" link)
- [x] User can link by URL or wiki page path — `CnXwikiTab.spec.js` (POST body is `{ reference: '<full URL>' }`); the OpenConnector source's `create` endpoint resolves it to a canonical `Space.Page`
- [x] Reference-property `referenceType: 'xwiki'` renders page chip — `CnXwikiCard.spec.js` (`single-entity` surface renders a title+breadcrumb chip from `value`, with a minimal-chip fallback on lookup failure); the `referenceType`-routing itself is covered by the umbrella's `CnFormDialog` / `CnDetailGrid` tests
- [x] Auth expiry surfaces clearly — `CnXwikiTab.spec.js` (a 503 with an auth `reason` → reconnect banner; a 503 without → generic unavailable banner)
- [x] Parity gate passes; nl + en done — `tab` + `widget` both present (the registry throws at `register()` otherwise; `tests/integrations/xwiki.spec.js` asserts the descriptor shape); all user-facing strings wrapped (`$l->t(...)` / `t('nextcloud-vue', ...)` — `l10n/*.json` are build-extracted per repo convention)
- [ ] Provider appears in registry when OpenConnector source `xwiki` configured — verified at the unit level (`XwikiProviderTest`: metadata, `isEnabled()` mirrors `IAppManager::isInstalled('openconnector')`, delegation to `ExternalIntegrationRouter`); the **live** runtime check (registry populated, admin Integrations row, Articles tab) is deferred until the umbrella + leaf PRs merge and land in a deployed Nextcloud with OpenConnector + an `xwiki` source configured
