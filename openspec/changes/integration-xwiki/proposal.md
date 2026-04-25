# Integration: XWiki (External Knowledge)

## Problem

XWiki is widely used in European government as a structured knowledge platform. Teams have migrated from Confluence / proprietary wikis and need OR objects to reference XWiki pages (procedure documents, legal interpretations, policy notes) — the inverse of Collectives (which is native NC).

## Context

- **Backend:** greenfield — external, routed via OpenConnector
- **Required NC app:** null
- **Required OpenConnector source:** an XWiki connector with credentials (Basic auth or OAuth depending on XWiki version)
- **Storage:** `external`
- **Depends on:** `pluggable-integration-registry`
- **Relationship to `integration-collectives`**: Collectives covers the native-NC knowledge case. XWiki covers the external-knowledge-platform case. Both can coexist; consuming apps choose based on customer setup.

## Proposed Solution

`XwikiProvider` declares `storage='external'` and references an `xwiki` OpenConnector source. Tab shows linked pages with titles, breadcrumb, last-modified. Widget on detail-page renders page preview (first N chars of XWiki-rendered content). Reference-property renders page chip.

## Scope

**In scope:** `XwikiProvider`, OpenConnector source config template, tab (link by URL or page path, display with breadcrumb), widget (4 surfaces, detail-page shows preview), registration, tests, nl+en.

**Out of scope:** Page editing (goes to XWiki); XWiki-side linking; XWiki macro rendering beyond basic text preview; XWiki access control inspection.

## Acceptance criteria

- [ ] Provider appears in registry when OpenConnector source `xwiki` configured
- [ ] Tab lists linked pages with titles + breadcrumb
- [ ] Detail-page widget shows text preview of page content
- [ ] User can link by URL or wiki page path
- [ ] Reference-property `referenceType: 'xwiki'` renders page chip
- [ ] Auth expiry surfaces clearly
- [ ] Parity gate passes; nl+en done
