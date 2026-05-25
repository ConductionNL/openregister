---
status: draft
---

# generic-integrations

## Purpose

Extend the generic-integrations capability with the shared HTTP REST contract
for the object-scoped integration link controllers. The provider/frontend
behavior of each integration (tab rendering, widget surfaces, provider
registration) is owned by the per-provider `integration-*` changes; what was
missing is a single requirement describing the uniform REST surface that all
eight link controllers implement identically. Reverse-specced from
`AnalyticsLinksController`, `CollectiveLinksController`, `MapLinksController`,
`OpenProjectLinksController`, `PollLinksController`, `TalkLinksController`,
`XwikiLinksController`, and `EmailLinksController`.

## ADDED Requirements

### Requirement: Object-Scoped Integration Link REST Contract

The system MUST expose a uniform object-scoped link REST surface so each
integration provider links its external resources (reports, pages, work
packages, polls, rooms, map favourites, wiki pages, mail messages) to an
OpenRegister object through one consistent contract. Every object-scoped link
controller MUST mount its routes under
`/api/objects/{register}/{schema}/{id}/{provider}` and MUST implement: a `GET`
list of linked resources, a `POST` to link an existing resource by id, an
optional `POST` create-and-link, and a `DELETE` unlink keyed by the provider's
resource id. Provider picker-source endpoints (the resources the current user
may link, plus any create-cascade parents) MUST be exposed under
`/api/integrations/{provider}/...`.

The list and picker responses MUST use the `{results, total}` envelope.
Successful link and create-and-link responses MUST return HTTP `201` with the
serialised link row; successful unlink MUST return `{success: true}`. When the
required Nextcloud app backing the provider is not installed, every endpoint
MUST short-circuit with HTTP `501` and a body `{error, code:
"APP_NOT_AVAILABLE"}`. When the target object cannot be resolved from
`(register, schema, id)`, the endpoint MUST return HTTP `404` with
`{error: "Object not found"}`. Service-layer exceptions MUST be mapped to HTTP
status by exception code (`400`/`401`/`404`/`409`/`503`), defaulting to `400`.

These controllers MUST authorize against the active user session (no admin
gate) and MUST rely on the backing service to scope resources to that user;
the contract therefore assumes a user-owned provider, and any provider whose
resources are NOT user-scoped MUST add its own authorization rather than
inherit this contract's session-only default.

#### Scenario: List linked resources for an object
- **GIVEN** an authenticated user and an object resolvable from `(register, schema, id)`
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/{id}/{provider}`
- **THEN** the response MUST be a `{results, total}` envelope listing the resources linked to that object

#### Scenario: Link an existing resource returns 201
- **GIVEN** an authenticated user and a valid resource id in the request body
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/{id}/{provider}`
- **THEN** the link row MUST be created and the response MUST be HTTP `201` carrying the serialised link

#### Scenario: Unlink returns success envelope
- **GIVEN** an existing link between an object and a provider resource
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/{id}/{provider}/{resourceId}`
- **THEN** the link MUST be removed and the response MUST be `{success: true}`

#### Scenario: Backing app not installed yields 501
- **GIVEN** the Nextcloud app backing the provider is not installed
- **WHEN** any of the provider's link endpoints is invoked
- **THEN** the response MUST be HTTP `501` with body `{error, code: "APP_NOT_AVAILABLE"}`

#### Scenario: Unresolvable object yields 404
- **GIVEN** a `(register, schema, id)` triple that does not resolve to an object
- **WHEN** any object-scoped link endpoint is invoked
- **THEN** the response MUST be HTTP `404` with `{error: "Object not found"}`

#### Scenario: Service exception codes map to HTTP status
- **GIVEN** the backing service throws an exception carrying code `409` (duplicate link)
- **WHEN** the controller catches it via its `mapException()` helper
- **THEN** the response status MUST be `409`
- **AND** an exception code outside `{400,401,404,409,503}` MUST default to HTTP `400`
