---
status: draft
---

# generic-integrations

## Purpose

Extend the generic-integrations capability with the uniform HTTP contract
shared by the Tier-2 integration "leaf" link controllers. The existing spec
covers the query-time `SharesProvider` and the registry surfacing; it has no
requirement for the object-scoped link-CRUD contract that every leaf
controller (Bookmarks, Cospend, Deck, Flow, Forms, Photos, TimeTracker,
Activity, Shares, Mail) implements identically. Reverse-specced from the live
`*LinksController` / `EmailsController` family. The `shares` leaf
(`ShareLinksController`) is the same object-scoped link surface over NC core
shares (the query-time `SharesProvider` documented elsewhere in this spec is
its backing provider).

This REQ is the **leaf-controller** companion to the "Object-Scoped
Integration Link REST Contract" REQ added by the sibling controller-batch
change `retrofit-2026-05-25-bw2-ctrl-1` (which covers the
Analytics/Collective/Map/OpenProject/Poll/Talk/Xwiki/Email *Links*
controllers). Both REQs describe the *same* uniform object-scoped link
contract — they were split only because the controller layer was scanned in
two batches. The two REQs partition the controller set with no overlap; the
shared HTTP semantics (route shape, `{results, total}` envelope,
`501 APP_NOT_AVAILABLE` degradation, exception-code→HTTP mapping) are kept
deliberately identical so the contract reads as one.

## ADDED Requirements

### Requirement: Tier-2 Integration Leaf Link Controller Contract

The system MUST expose object-scoped integration "leaf" link controllers that
share one uniform REST contract so a Nextcloud entity (bookmark, Cospend
project/bill, Deck card, Flow operation, Form/submission, Photos album,
TimeManager entry, Activity entry, mail message) can be linked to an
OpenRegister object. Each leaf controller MUST resolve the target object from
the `{register}/{schema}/{id}` path triple and MUST return `404` with an
`{error}` body when the object does not resolve. The contract comprises:

- a **list** verb (`index`, `GET /api/objects/{register}/{schema}/{id}/{leaf}`)
  returning `{results, total}`;
- a **link-existing** verb (`link`/`create`,
  `POST /api/objects/{register}/{schema}/{id}/{leaf}`) returning `201` with the
  link's JSON serialization, validating the required reference id in the body
  and returning `400` when it is missing;
- where the backing app supports creation, a **create-and-link** verb
  (`createNew`/`createAndLink`/`create`,
  `POST /api/objects/{register}/{schema}/{id}/{leaf}/new`) returning `201`;
- an **unlink** verb (`destroy`,
  `DELETE /api/objects/{register}/{schema}/{id}/{leaf}/{entityId}`) returning a
  success body; and
- one or more **picker-source discovery** verbs
  (`available` / `boards` / `stacks` / `operations` / `types` / `actors`,
  under `/api/integrations/{leaf}/...`) that surface candidate entities for the
  link modal without leaking the backing app's internals.

Every verb MUST degrade gracefully when the backing Nextcloud app is not
installed by returning HTTP `501` with the envelope
`{error, code: "APP_NOT_AVAILABLE"}`. Service-layer exceptions MUST be mapped
to HTTP status by exception code (`409` conflict, `404` not found, `503`
unavailable, `400` bad request). Read-only leaves (Activity) MUST expose only
the list + discovery verbs and omit link/create/unlink. Admin-gated leaves
(Flow) MUST restrict mutating verbs to admins while leaving list read-only for
all authenticated users.

#### Scenario: List linked entities for an object
- **GIVEN** an OpenRegister object resolvable from `{register}/{schema}/{id}` and the backing app installed
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/{leaf}` is called
- **THEN** the response MUST be HTTP 200 with a `{results, total}` body
- **AND** an unresolvable object MUST return HTTP 404 with an `{error}` body

#### Scenario: Link an existing entity requires the reference id
- **GIVEN** a resolvable object
- **WHEN** the link verb is called without the required reference id in the body
- **THEN** the response MUST be HTTP 400 with an `{error}` body
- **AND** a valid link request MUST return HTTP 201 with the link's JSON serialization

#### Scenario: Graceful degradation when the backing app is absent
- **GIVEN** the backing Nextcloud app (e.g. Bookmarks, Cospend, Deck) is not installed
- **WHEN** any verb on the corresponding leaf controller is called
- **THEN** the response MUST be HTTP 501 with the body `{error, code: "APP_NOT_AVAILABLE"}`

#### Scenario: Picker-source discovery surfaces candidates without internals
- **GIVEN** the backing app is installed
- **WHEN** the discovery verb (`available`/`boards`/`stacks`/`operations`/`types`/`actors`) is called
- **THEN** the response MUST return the candidate entities visible to the current user as `{results, total}` (or the verb-specific shape)
- **AND** entities the current user cannot see MUST be omitted

#### Scenario: Read-only and admin-gated leaves restrict mutating verbs
- **GIVEN** the read-only Activity leaf and the admin-gated Flow leaf
- **WHEN** a non-admin attempts a mutating verb
- **THEN** the Activity leaf MUST expose no link/create/unlink verb at all
- **AND** the Flow leaf MUST reject the mutating verb for non-admins while still serving its list verb read-only

## Non-Functional Requirements

- **i18n (ADR-007)**: These are JSON REST link controllers. The error bodies
  (`{error}`, `{error, code: "APP_NOT_AVAILABLE"}`) are machine-readable
  diagnostics keyed by a stable `code`; the human-readable text is
  developer/operator copy and exempt from translation. Linked-entity payloads
  carry only backing-app data, no app-authored user-facing strings, so nl+en
  localisation does not apply here. (ADR-007 n/a.)
- **REST/error contract (ADR-002)**: Deliberately identical to the sibling
  `retrofit-2026-05-25-bw2-ctrl-1` "Object-Scoped Integration Link REST Contract"
  so the contract reads as one — `{results, total}` list envelope, `201` on
  link/create, `400` for a missing reference id, `404` for an unresolved object,
  `501 APP_NOT_AVAILABLE` degradation, and exception-code→HTTP mapping
  (`409/404/503/400`). The full RFC 7807 problem-details shape is out of scope
  for these link controllers.

## Acceptance Criteria

- [x] Every Tier-2 leaf link controller (`*LinksController` / `EmailsController` family) carries `@spec generic-integrations#...` annotations pointing at this requirement.
- [x] List returns `{results, total}`; link/create returns `201`; a missing reference id returns `400`; an unresolved object returns `404`.
- [x] A missing backing app yields `501` with `{error, code: "APP_NOT_AVAILABLE"}` on every verb.
- [x] Read-only (Activity) and admin-gated (Flow) leaves restrict mutating verbs as specified; the contract stays byte-identical to the ctrl-1 sibling REQ with no controller overlap.
- [x] `openspec validate retrofit-2026-05-25-bw2-ctrl-2 --strict` passes.
