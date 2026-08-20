# Integration: Shares

## Problem

NC Shares (file/folder shares, public links) pertain to case files but are invisible from the object. "Who has access to this case?" requires clicking into Files, finding the folder, opening the share panel.

## Context

- **Backend:** greenfield — wrap NC Share Manager (`OCP\Share\IManager`)
- **Required NC app:** `null` (Shares is NC core, always available)
- **Storage:** `query-time` (shares are queried live from Share Manager filtered by object's linked files)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`ShareService` + `SharesController` + `SharesProvider` + `CnSharesTab` + `CnSharesCard`. Tab aggregates shares across object's linked files — shows who has access, with what permissions, via what mechanism (user share, group share, public link, federated). Quick revoke action.

## Scope

**In scope:** Backend service querying Share Manager for all shares on object's linked files, provider with `query-time` storage, tab, widget, revoke action, registration, tests, nl+en.

**Out of scope:** Creating new shares (NC Files UI owns); share-expiry management UI (NC Files UI); federated share negotiation.

## Acceptance criteria

- [ ] Shares tab always appears (no required app) when schema has `shares` in linkedTypes
- [ ] Tab aggregates shares across all object's linked files
- [ ] User can revoke a share from the tab (delegated to Share Manager)
- [ ] Widget shows share count on dashboards
- [ ] Reference-property `referenceType: 'shares'` renders share chip
- [ ] Parity gate passes; nl+en done
