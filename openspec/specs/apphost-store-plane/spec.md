---
status: done
---

# apphost-store-plane Specification

## Purpose

Gives any AppHost-hosted app a read-only client for a remote "store" — another
OpenRegister instance that exposes installable items over its objects API — so
openbuild's application-template store, openconnector's connector store and
hermiq's agent-template store share one engine-owned implementation instead of
three app-local copies. The plane covers DISCOVERY only (configure / search /
resolve); INSTALL stays in each consuming app, because cloning an application
template, enabling a connector adapter and instantiating an agent template are
different operations with different authorization. Everything that differs
between apps is carried in a `StoreDescriptor` value object; everything else —
SSRF guarding, redirect refusal, Bearer-token handling, outcome mapping and card
normalisation — lives once in `GenericStoreService`. Implements ADR-080.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own — the store page belongs to the consuming app, and every behaviour here (SSRF rejection, redirect refusal, Bearer-only token transport, outcome mapping, card normalisation, descriptor-driven URL, slug verification) is asserted in tests/Unit/AppHost/GenericStoreServiceTest.php. Covered by PHPUnit.

## Requirements

### Requirement: A store descriptor MUST carry every per-app parameter

`StoreDescriptor` SHALL be an immutable value object holding the `appId` whose
`IAppConfig` carries the registry connection (`registry_url`, `registry_token`,
`registry_register`), the remote `schema` slug, the `defaultRegister` used when
`registry_register` is unset or empty, and a `cardFields` map of card field name
to remote object property. The default `cardFields` map SHALL be `slug`, `title`,
`description`, `category`, `version`. No other per-app parameter may be read by
the generic service.

#### Scenario: Two apps reach two different remote schemas

- **GIVEN** descriptors for `openbuild`/`application-template` and
  `openconnector`/`catalog_item`
- **WHEN** each is passed to `GenericStoreService::search()`
- **THEN** the outbound URL MUST be
  `<base>/index.php/apps/openregister/api/objects/<register>/<schema>` with the
  register and schema of that descriptor
- **AND** the register segment MUST come from the app's `registry_register`
  config, falling back to the descriptor's `defaultRegister` when that value is
  absent or empty
- **AND** both segments MUST be `rawurlencode`d

---

### Requirement: An unconfigured store MUST make no network call

`GenericStoreService::isConfigured()` SHALL report a store as configured only
when the app's `registry_url` trims to a non-empty string. When it is not
configured, `search()` MUST return outcome `not_configured` with an empty card
list and `resolve()` MUST return `null`, and neither MUST issue an HTTP request.
This is the fallback that lets a consuming app's store page render its built-in
items instead of an error.

#### Scenario: Empty registry URL short-circuits the search

- **GIVEN** `registry_url` is the empty string for the descriptor's app
- **WHEN** `search()` is called
- **THEN** the outcome MUST be `not_configured` and the card list MUST be empty
- **AND** no HTTP client MUST be constructed

---

### Requirement: Every outbound registry URL MUST be SSRF-guarded and MUST NOT follow redirects

The service SHALL pass every built URL through
`SecurityService::assertSafeFetchUrl()` before any request is issued, and SHALL
fail closed: a private, reserved, loopback or unresolvable host, and any
non-`http(s)` scheme, MUST yield outcome `store_unreachable` with no request
made. The request options MUST set `allow_redirects` to `false`, because
`assertSafeFetchUrl` validates the URL at one point in time and following a 3xx
would let a public host redirect the registry Bearer token to a private,
link-local or metadata address (or exploit DNS rebinding between validation and
connect). Connect and request timeouts MUST both be 10 seconds.

#### Scenario: Private-address registry is rejected before the request

- **GIVEN** `registry_url` is `http://192.168.1.10/`
- **WHEN** `search()` is called
- **THEN** the outcome MUST be `store_unreachable`
- **AND** no HTTP client MUST be constructed

#### Scenario: Non-http scheme is rejected fail-closed

- **GIVEN** `registry_url` is `file:///etc/passwd`
- **WHEN** `search()` is called
- **THEN** the outcome MUST be `store_unreachable` and no request MUST be issued

#### Scenario: Unresolvable host is rejected fail-closed

- **GIVEN** `registry_url` names a host that does not resolve
- **WHEN** `search()` is called
- **THEN** the outcome MUST be `store_unreachable` and no request MUST be issued

#### Scenario: Redirects are refused

- **GIVEN** a configured, publicly-addressable registry
- **WHEN** the service fetches from it
- **THEN** the request options MUST carry `allow_redirects => false`

---

### Requirement: The registry token MUST travel only as a Bearer header

When `registry_token` trims to a non-empty string it MUST be sent as an
`Authorization: Bearer <token>` request header and MUST NOT appear in the URL or
in the query parameters. The token MUST NOT be returned to callers in any
outcome, card or resolved payload.

#### Scenario: Token is absent from URL and query

- **GIVEN** a configured registry with `registry_token` set
- **WHEN** the service fetches from it
- **THEN** the `Authorization` header MUST be `Bearer <token>`
- **AND** neither the request URL nor the encoded query MUST contain the token

---

### Requirement: Upstream failures MUST map to generic outcomes, distinguishing unreachable from invalid

A transport exception, a non-2xx status and a refused/guarded URL MUST all yield
`store_unreachable`; a body that is not decodable JSON, or decodes to a
non-array, MUST yield `store_invalid_response`. The two MUST NOT be collapsed —
a misconfigured store would otherwise look offline. Upstream error detail MUST
be logged server-side and MUST NOT reach the caller. A successful body MUST be
read from its `results` key when present, and a bare JSON list MUST also be
accepted; any other successful decode MUST be treated as zero results rather
than iterated, because a caller iterating an associative array would walk its
values as if they were records.

#### Scenario: Transport failure yields a generic outcome

- **GIVEN** the HTTP client throws while fetching
- **WHEN** `search()` handles it
- **THEN** the outcome MUST be `store_unreachable` with an empty card list
- **AND** the upstream message MUST NOT appear in the returned value

#### Scenario: Non-2xx status is unreachable, not an empty success

- **GIVEN** the registry answers HTTP 503
- **WHEN** `search()` handles it
- **THEN** the outcome MUST be `store_unreachable`

#### Scenario: Unparseable body is distinguishable from an empty one

- **GIVEN** the registry answers 200 with a non-JSON body
- **WHEN** `search()` handles it
- **THEN** the outcome MUST be `store_invalid_response`

---

### Requirement: Search MUST return normalised cards that never carry the install payload

`search()` SHALL request at most 50 items, SHALL forward a trimmed non-empty
`$query` as `_search` and a trimmed non-empty `$kind` as `kind`, and SHALL
flatten each returned object to a card using ONLY the descriptor's `cardFields`
map plus a `kind` field. A field whose remote property is missing MUST be
rendered as the empty string rather than omitted, so the frontend never has to
null-check a card. Any remote property outside the map — including a `manifest`
or a credential-shaped field — MUST NOT appear on the card.

#### Scenario: Cards carry the descriptor's fields plus kind

- **GIVEN** a registry returning an object with `slug`, `title`, `description`,
  `category`, `version` and `kind`
- **WHEN** `search()` normalises it
- **THEN** the card MUST carry each descriptor field and `kind`

#### Scenario: Fields outside the descriptor are dropped

- **GIVEN** a registry returning an object that also carries `manifest` and
  `token`
- **WHEN** `search()` normalises it
- **THEN** the card MUST NOT contain `manifest` and MUST NOT contain `token`

---

### Requirement: Resolve MUST verify the returned slug and return the full payload

`resolve()` SHALL request `slug=<slug>` with a limit of 1 and SHALL return an
item only when the object the registry actually returned carries that exact
`slug`; otherwise it MUST return `null`. A registry that ignores an unknown
query parameter would otherwise hand back an arbitrary first row. On success the
FULL remote object MUST be returned — unlike a search card — because the calling
app's install action needs the payload.

#### Scenario: A mismatched slug resolves to null

- **GIVEN** the registry returns an object whose `slug` is not the requested one
- **WHEN** `resolve()` inspects it
- **THEN** the result MUST be `null`

#### Scenario: A matching slug returns the untrimmed object

- **GIVEN** the registry returns an object with the requested `slug` and a
  `manifest` property
- **WHEN** `resolve()` returns it
- **THEN** the returned array MUST still contain `manifest`

### Requirement: A leaf app MUST declare its store rather than implement one

An adopting app SHALL declare a `store` block in `src/manifest.json` and SHALL
NOT ship a store controller. The engine hosts `/api/store/items` and
`/api/store/items/{slug}/install`, which the app aliases at
`GenericStoreController` the same way it already aliases `/api/health` and
`/api/metrics`.

This amends ADR-080 Decision 3, which kept `install` per app. That decision
rejected a cross-app controller BASE CLASS, whose three documented failures are
all consequences of `extends` being resolved by the autoloader rather than the
container. Route aliasing uses no inheritance, so none of them apply. The other
half of Decision 3, that install semantics differ per app, is answered by making
the difference data: the only thing that varied was which schemas an install may
write.

An app that aliases the routes but declares no block MUST report
`not_configured`, not `404`. The page then renders its own items rather than
reading as a broken endpoint.

#### Scenario: An app with no store block reports not_configured

- **GIVEN** an app whose manifest has no `store` key
- **WHEN** `GET /api/store/items` is called by a signed-in user
- **THEN** the response MUST be `200` with outcome `not_configured`
- **AND** no network call MUST be made

### Requirement: An install MUST refuse every schema the manifest does not allow

The `installable` list is an allowlist and a security boundary. A registry is a
third-party server, so an install MUST write only into the schema slugs the
calling app declares. An absent or empty list MUST refuse **every** component
rather than permit every component: an app that declares a store and omits the
allowlist gets refusals, not an open door.

A refusal MUST NOT abort the install. The remaining components still arrive and
the per-component report names what did not, because an item that is half
configuration and half records is the registry's mistake rather than a reason to
deny an administrator the half they may have.

#### Scenario: A component naming an undeclared schema is refused

- **GIVEN** a manifest whose `installable` is `["caseType"]`
- **WHEN** an item declares a component for schema `case`
- **THEN** nothing MUST be written for it
- **AND** the report MUST mark it `refused`

#### Scenario: An empty allowlist refuses everything

- **GIVEN** a manifest whose `store` block declares no `installable`
- **WHEN** any component is installed
- **THEN** every component MUST be refused

### Requirement: An install MUST create a new object, never replace one

Every identity key the remote payload carries (`id`, `uuid`, `@self`) MUST be
stripped before the write. `ObjectService::saveObject()` resolves its target
FROM the payload and the write is PUT-semantic, so a component carrying the uuid
of a live local object would replace it and null every key the payload omits.

The schema allowlist does not cover this: it governs which schema a component
may write, never whether the write creates or replaces, so an entirely
legitimate component is the attack.

#### Scenario: A payload carrying a local uuid still creates

- **GIVEN** a component whose object carries `id`, `uuid` and `@self`
- **WHEN** it is installed
- **THEN** the object handed to `saveObject()` MUST carry none of them
