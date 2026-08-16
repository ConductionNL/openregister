---
retrofit: true
---

# Generic Integrations

## Why

ADR-019 established the integration-leaf pattern, and the existing
requirements cover the shares provider. A second, distinct shape exists
in production: the Tier-2 *link services* (xWiki, Talk, OpenProject,
Bookmark, Collective) that bind a remote/peer-app entity to an OR object
through a local link table, with a lazily-resolved provider and uniform
graceful degradation. No requirement captures that shared contract; this
change anchors it so all five services (and future link leaves) conform.

## ADDED Requirements

### Requirement: Tier-2 Remote-Entity Link Service Contract
A Tier-2 link service MUST persist object↔remote-entity bindings in its own local link table, resolve its provider/router lazily so the service loads even when the backing app is absent, and expose five operations — link an existing remote entity, create-and-link a new one, unlink (binding only, never deleting the remote), list linked entities with stale-cache refresh, and browse available entities for the picker — each degrading gracefully when the backing app or upstream is unavailable.

Each link service (`XwikiLinkService`, `TalkLinkService`, `OpenProjectLinkService`, `BookmarkLinkService`, `CollectiveLinkService`) MUST:
- **link** an existing remote entity to an OR object, rejecting an empty reference (400), rejecting a duplicate binding (409), and caching the remote entity's display metadata at link time;
- **create-and-link** a new remote entity through the backing provider, then persist the binding with the returned canonical reference;
- **unlink** by removing only the local binding row (404 when no binding matches) and MUST NOT delete the remote entity itself, since other objects may still link it;
- **list** the linked entities for an object, refreshing a row's cached metadata from the upstream when a source is configured and the row's cache is older than the stale window, and MUST NOT throw when the upstream is down — stale rows MUST survive;
- **browse/picker** the available remote entities, returning a structured `{ unavailable, cause, results, total }` descriptor (rather than throwing) when the backing app is not installed or the upstream is unreachable.

Mutating operations MUST require an authenticated user (401 otherwise) and MUST surface an unconfigured-source / upstream-down condition as a 503 carrying the cause so the controller can render the integration banner.

#### Scenario: Link an existing remote entity
- **GIVEN** an authenticated user and a valid remote entity reference
- **WHEN** the link operation runs and no binding yet exists
- **THEN** a link row MUST be persisted with the canonical reference and cached display metadata
- **AND** a second identical link attempt MUST be rejected with 409

#### Scenario: Unlink leaves the remote entity intact
- **GIVEN** an existing binding between an object and a remote entity
- **WHEN** the unlink operation runs
- **THEN** only the local binding row MUST be removed
- **AND** the remote entity MUST remain available to any other linked objects

#### Scenario: Picker degrades when the backing app is absent
- **GIVEN** the backing app (or upstream) is unavailable
- **WHEN** the browse/picker operation runs
- **THEN** it MUST return `{ unavailable: true, cause, results: [], total: 0 }` without throwing

#### Scenario: List survives an upstream outage with stale rows
- **GIVEN** linked rows whose cached metadata is stale and the upstream is down
- **WHEN** the list operation runs
- **THEN** the stale rows MUST be returned as-is rather than the call failing
