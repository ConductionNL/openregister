# Integration: OpenProject (External via OpenConnector)

## Problem

Many government workflows use OpenProject for project management alongside Nextcloud for collaboration. Cases and OR objects often correspond to OpenProject work packages; today there's no linkage. Users copy IDs or URLs manually, and visibility is lost.

This is the **first external-service integration** — proving the OpenConnector-routing pattern the umbrella established.

## Context

- **Backend:** greenfield — external, routed via OpenConnector
- **Required NC app:** null (OpenProject is external; no NC app required)
- **Required OpenConnector source:** an OpenProject connector configured with OAuth2 credentials
- **Storage:** `external` (no local link table; OpenConnector pairing or query-time)
- **Depends on:** `pluggable-integration-registry`
- **First-of-kind risk:** the leaf proves both the integration registry's external path AND exposes rough edges in OpenConnector's external-service model — expect some umbrella refinements to fall out

## Proposed Solution

`OpenProjectProvider` declares `storage='external'` and references an `openproject` OpenConnector source. CRUD operations route through `ExternalIntegrationRouter` (the umbrella's dispatch helper) which invokes OpenConnector with object context. `CnOpenProjectTab` + `CnOpenProjectCard` render linked work packages with status, assignee, progress. Auth status surfaced via admin section (umbrella's unified auth UI).

## Scope

**In scope:** `OpenProjectProvider` with external storage, OpenConnector source config template, tab (link existing WP + status display), widget (4 surfaces), auth surface via admin UI, registration, tests, nl+en.

**Out of scope:** Modifying OpenConnector itself (uses as-is); OpenProject-side data modification beyond what external routing surfaces; OAuth flow implementation (OpenConnector owns).

## Acceptance criteria

- [ ] Provider appears in registry when OpenConnector source `openproject` is configured
- [ ] Admin UI shows auth status ("configured" / "missing" / "expired") with Configure button linking to OpenConnector
- [ ] Tab lists linked work packages with status/assignee/progress
- [ ] User can link an existing WP by id or URL
- [ ] Widget renders on all 4 surfaces (WP status badge style)
- [ ] Reference-property `referenceType: 'openproject'` renders WP chip
- [ ] When OpenConnector source is missing: integration hidden from registry; `health()` returns `unavailable`
- [ ] OCS capabilities advertises the integration with `authStatus` field
- [ ] Parity gate passes; nl+en done
