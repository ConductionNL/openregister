---
status: in-progress
---

# apphost-store-plane Specification

## Purpose

Gives any AppHost-hosted app a read-only client for a remote "store" — another
OpenRegister instance that exposes installable items over its objects API — so
openbuild's application-template marketplace, openconnector's connector store and
hermiq's agent-template marketplace share one engine-owned implementation instead of
three app-local copies. The plane covers DISCOVERY only (configure / search /
resolve); INSTALL stays in each consuming app, because cloning an application
template, enabling a connector adapter and instantiating an agent template are
different operations with different authorization. Everything that differs
between apps is carried in a `StoreDescriptor` value object; everything else —
SSRF guarding, redirect refusal, Bearer-token handling, outcome mapping and card
normalisation — lives once in `GenericStoreService`. Implements ADR-080.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own — the marketplace page belongs to the consuming app, and every behaviour here (SSRF rejection, redirect refusal, Bearer-only token transport, outcome mapping, card normalisation, descriptor-driven URL, slug verification) is asserted in tests/Unit/AppHost/GenericStoreServiceTest.php. Covered by PHPUnit.

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
This is the fallback that lets a consuming app's marketplace page render its built-in
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

### Requirement: A descriptor MUST opt in to publishing by naming its fields and its groups

A `StoreDescriptor` SHALL carry, next to the parameters the descriptor requirement
already names, a `publishFields` list (the remote object properties a publish may send)
and a `publishGroups` list (the Nextcloud groups whose members may publish). Both SHALL
default to empty. A descriptor with an empty `publishFields` or no non-empty entry in
`publishGroups` cannot publish, so every descriptor written before this requirement stays
read-only.

A publish through such a descriptor MUST return outcome `not_publishable` and MUST NOT
construct an HTTP client. The refusal MUST be logged server-side with the app id, because
it is a declaration the app forgot rather than a user being told no.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own; the consuming app owns the publish button. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishRefusesADescriptorThatNamesNoGroup, testPublishRefusesADescriptorThatAllowsNoFields). Covered by PHPUnit.

#### Scenario: A read-only descriptor cannot publish

- **GIVEN** a descriptor built with only `appId`, `schema` and `defaultRegister`
- **WHEN** `publish()` is called with any payload
- **THEN** the outcome MUST be `not_publishable`
- **AND** no HTTP client MUST be constructed

#### Scenario: A descriptor that names fields but no group cannot publish

- **GIVEN** a descriptor with `publishFields` set and `publishGroups` empty
- **WHEN** `publish()` is called
- **THEN** the outcome MUST be `not_publishable`
- **AND** no HTTP client MUST be constructed

---

### Requirement: An unconfigured store MUST make no publish request

When the app's `registry_url` trims to the empty string, `publish()` MUST return outcome
`not_configured` with an empty slug and MUST NOT issue an HTTP request. This lets the
consuming app say "no registry is connected" instead of reporting a failure.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishToAnUnconfiguredStoreMakesNoRequest). Covered by PHPUnit.

#### Scenario: Empty registry URL short-circuits the publish

- **GIVEN** a publishing descriptor and an empty `registry_url`
- **WHEN** `publish()` is called
- **THEN** the outcome MUST be `not_configured` and the slug MUST be empty
- **AND** no HTTP client MUST be constructed

---

### Requirement: A publish MUST send only allowed fields and never an identity key

The body a publish sends SHALL hold the payload's `slug` plus only those payload keys the
descriptor's `publishFields` lists. Any other key MUST NOT be sent. The keys `id`, `uuid`
and `@self` MUST NOT be sent even when `publishFields` lists them: the registry's objects
API resolves its write target from the payload, so a payload carrying the id of an object
that already lives on the registry would replace that object instead of creating one.

The payload MUST carry a `slug` that is a string of lowercase letters, digits and inner
hyphens (the pattern the store's install route accepts), so that what is published can
later be resolved and installed. A payload without one MUST return `not_publishable` and
MUST NOT issue a request.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishSendsOnlyAllowedFields, testPublishNeverSendsAnIdentityKey, testPublishRefusesAPayloadWithoutAValidSlug). Covered by PHPUnit.

#### Scenario: A field outside the allowlist stays home

- **GIVEN** a descriptor whose `publishFields` is `["title"]`
- **AND** a payload with `slug`, `title` and `internalNote`
- **WHEN** `publish()` sends it
- **THEN** the request body MUST contain `slug` and `title`
- **AND** the request body MUST NOT contain `internalNote`

#### Scenario: An identity key never travels

- **GIVEN** a descriptor whose `publishFields` lists `id`, `uuid`, `@self` and `title`
- **AND** a payload carrying all four
- **WHEN** `publish()` sends it
- **THEN** the request body MUST NOT contain `id`, `uuid` or `@self`

#### Scenario: A payload without a valid slug is refused

- **GIVEN** a payload whose `slug` is missing, not a string, or contains an uppercase
  letter or a slash
- **WHEN** `publish()` is called
- **THEN** the outcome MUST be `not_publishable`
- **AND** no HTTP client MUST be constructed

---

### Requirement: A publish MUST travel under the plane's transport rules

A publish SHALL POST the body as JSON to
`<base>/index.php/apps/openregister/api/objects/<register>/<schema>`, built exactly as a
search builds its URL (the register from `registry_register`, falling back to the
descriptor's `defaultRegister`, both segments `rawurlencode`d). The URL MUST pass the SSRF
guard before any request, and a refused URL MUST yield `store_unreachable` with no request.
The request MUST set `allow_redirects` to `false` and a 10 second connect and request
timeout. When `registry_token` is set it MUST travel only as an `Authorization: Bearer`
header, and MUST NOT appear in the URL, the body or any returned value.

A body larger than 20 MiB MUST return `too_large` and MUST NOT be sent.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishPostsToTheDescriptorSchema, testPublishToAPrivateAddressIsRejected, testPublishNeverFollowsRedirectsAndSendsTheTokenOnlyAsBearer, testPublishRefusesAnOversizedBody). Covered by PHPUnit.

#### Scenario: A publish lands on the descriptor's schema

- **GIVEN** a descriptor for app `learniq`, schema `shared-course-package`, and
  `registry_register` set to `learniq`
- **WHEN** `publish()` sends a payload
- **THEN** the request MUST be a POST to a URL ending in
  `/index.php/apps/openregister/api/objects/learniq/shared-course-package`

#### Scenario: A private-address registry is rejected before the request

- **GIVEN** `registry_url` is `http://192.168.1.10/`
- **WHEN** `publish()` is called with a publishing descriptor and a valid payload
- **THEN** the outcome MUST be `store_unreachable`
- **AND** no HTTP client MUST be constructed

#### Scenario: Redirects are refused and the token stays in the header

- **GIVEN** a configured, publicly addressable registry with `registry_token` set
- **WHEN** `publish()` sends a payload
- **THEN** the request options MUST carry `allow_redirects => false`
- **AND** the `Authorization` header MUST be `Bearer <token>`
- **AND** neither the URL nor the request body MUST contain the token

#### Scenario: An oversized body is not sent

- **GIVEN** a payload whose JSON encoding is larger than 20 MiB
- **WHEN** `publish()` is called
- **THEN** the outcome MUST be `too_large`
- **AND** no HTTP client MUST be constructed

---

### Requirement: Publish failures MUST map to generic outcomes that name the remedy

A transport exception, a 3xx and a 5xx MUST yield `store_unreachable`. A 429 MUST yield
`rate_limited`. Any other 4xx MUST yield `store_rejected`: the registry answered and
refused this object (validation, a duplicate, a token without write rights), which is a
different remedy from a registry that is down. A 2xx whose body is not a decodable JSON
object MUST yield `store_invalid_response`. Upstream detail MUST be logged server-side and
MUST NOT reach the caller; every failure MUST return an empty slug.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishTransportFailureIsUnreachable, testPublishStatusMapsToTheRightOutcome, testPublishUnparseableBodyIsInvalid). Covered by PHPUnit.

#### Scenario: The registry refuses the object

- **GIVEN** the registry answers HTTP 422
- **WHEN** `publish()` handles it
- **THEN** the outcome MUST be `store_rejected` and the slug MUST be empty

#### Scenario: The registry is down

- **GIVEN** the registry answers HTTP 503, or the client throws
- **WHEN** `publish()` handles it
- **THEN** the outcome MUST be `store_unreachable`
- **AND** the upstream message MUST NOT appear in the returned value

#### Scenario: The registry rate limits the publisher

- **GIVEN** the registry answers HTTP 429
- **WHEN** `publish()` handles it
- **THEN** the outcome MUST be `rate_limited`

---

### Requirement: A publish MUST verify the slug the registry stored

A 2xx answer SHALL count as published only when the object the registry returned carries
exactly the slug that was sent. Otherwise the outcome MUST be `store_invalid_response`
with an empty slug, and the mismatch MUST be logged. A registry that silently renamed the
object would otherwise leave the consuming app pointing at a slug that resolves to
nothing, or to somebody else's item. On success the result SHALL be outcome `ok` and the
sent slug.

@e2e exclude Backend HTTP client with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/GenericStoreServiceTest.php (testPublishReturnsTheVerifiedSlug, testPublishRejectsAMismatchedSlug). Covered by PHPUnit.

#### Scenario: The stored slug matches

- **GIVEN** the registry answers 201 with an object whose `slug` is the sent slug
- **WHEN** `publish()` returns
- **THEN** the outcome MUST be `ok` and the slug MUST be the sent slug

#### Scenario: The stored slug differs

- **GIVEN** the registry answers 201 with an object whose `slug` differs from the sent one
- **WHEN** `publish()` returns
- **THEN** the outcome MUST be `store_invalid_response` and the slug MUST be empty

---

### Requirement: Only a user the app's named groups admit MAY publish

Who may publish is the consuming app's decision; the plane enforces only that the app made
one. `StoreActionAuthorizer::canPublish()` SHALL refuse when the descriptor's
`publishGroups` holds no non-empty entry, and SHALL log that refusal at ERROR with the app
id. When at least one group is named, the user SHALL be matched the way an ADR-023 action
matrix matches: an administrator passes, the `@authenticated` entry admits any signed-in user,
and otherwise the user MUST be a member of one of the named groups. A named group that
does not exist on this server MUST NOT admit anybody and MUST be logged at ERROR, because
a group nothing answers to would otherwise read as "nobody may publish" with no trace of
why.

An app SHOULD pass the groups its own matrix holds for its publish action (for example
`getAllowedGroups('course-package.share')`), so that the app's matrix stays the one place
an administrator changes who may publish.

The consuming app MUST ask `canPublish()` before calling `publish()`. `publish()` itself
refuses a descriptor that names no group, so an app cannot publish through the plane
without having made the decision.

@e2e exclude Backend authorization helper with no OpenRegister UI surface of its own. Asserted in tests/Unit/AppHost/StoreActionAuthorizerTest.php (testCanPublishPermitsAMemberOfANamedGroup, testCanPublishRefusesANonMember, testCanPublishRefusesWhenNoGroupIsNamed, testCanPublishLogsAGroupThatDoesNotExist, testCanPublishAdmitsAnAdministratorOnlyWhenAGroupIsNamed, testCanPublishHonoursEveryone). Covered by PHPUnit.

#### Scenario: A member of a named group may publish

- **GIVEN** a descriptor whose `publishGroups` is `["instructors"]`
- **AND** a user in `instructors`
- **WHEN** `canPublish()` is asked
- **THEN** it MUST answer true

#### Scenario: A user outside every named group is refused

- **GIVEN** a descriptor whose `publishGroups` is `["instructors"]`
- **AND** a non-administrator who is not in `instructors`
- **WHEN** `canPublish()` is asked
- **THEN** it MUST answer false

#### Scenario: A descriptor that names no group refuses everybody, administrators included

- **GIVEN** a descriptor whose `publishGroups` is empty
- **WHEN** `canPublish()` is asked for any user, an administrator included
- **THEN** it MUST answer false
- **AND** an ERROR MUST be logged naming the app

#### Scenario: A group that does not exist admits nobody

- **GIVEN** a descriptor whose `publishGroups` names only a group this server does not have
- **WHEN** `canPublish()` is asked for a non-administrator
- **THEN** it MUST answer false and an ERROR MUST be logged naming the group

### Requirement: A store can name this instance as its registry

A store whose `registry_url` is `local` SHALL be read from the configured register on this instance, through the object service, without an HTTP request. A register or schema that cannot be read SHALL answer `store_unreachable`.

#### Scenario: a local catalogue

- GIVEN pipelinq's `registry_url` is `local` and its register holds nine `commercial-template` objects
- WHEN the marketplace page searches
- THEN the nine items are returned as cards
- AND no HTTP request is made

#### Scenario: the schema is missing

- GIVEN `registry_url` is `local` and the register has no `commercial-template` schema
- WHEN the marketplace page searches
- THEN the outcome is `store_unreachable` and there are no cards

### Requirement: A store page can publish through one route

`POST /api/store/publish` SHALL publish `item` to the app's configured registry. It SHALL use the app's declared `publish.fields` and `publish.groups`. An anonymous caller SHALL get 401. A user `canPublish()` does not admit SHALL get 403, and nothing SHALL be sent.

#### Scenario: a member of the publish group publishes

- GIVEN pipelinq declares `publish: {fields: [title, kind, version, components], groups: [pipelinq-publishers]}`
- AND Pieter is in `pipelinq-publishers`
- WHEN Pieter publishes `{slug: woo-intake, title: Woo-intake, kind: pipeline, version: 1.0.0}`
- THEN the registry receives the slug and the declared fields only
- AND the answer is `{outcome: ok, slug: woo-intake}`

#### Scenario: someone outside the group

- GIVEN a user who is in no publish group and not an administrator
- WHEN they publish
- THEN the answer is 403 and the registry receives nothing

### Requirement: Publishing to a local registry writes in-process

When `registry_url` is `local`, publish SHALL write the filtered body into the configured register through the object service. It SHALL update the object carrying the same slug, or create one, and it SHALL make no HTTP request.

#### Scenario: a new version of an item already in the local registry

- GIVEN the local registry holds `woo-intake` at version 1.0.0
- WHEN `woo-intake` is published at version 1.1.0
- THEN that same object now reads version 1.1.0

### Requirement: An action posture MUST resolve against the declaring app

When a store declares `installAuth: "action:<name>"`, the engine MUST ask the
declaring app's own ADR-023 authorization matrix whether the signed-in user may
perform `<name>`, and MUST install only when it answers yes.

The engine MUST NOT decide an action posture itself. Assuming `admin` would
turn "the operators who hold this action" into "instance administrators", which
is the capability loss the key exists to prevent.

An administrator MUST NOT bypass the matrix. Otherwise the declaration is
decorative, and an app could never gate an install more tightly than instance
admin.

#### Scenario: The leaf matrix permits

- **WHEN** a store declares `"installAuth": "action:catalog.instantiate"`
- **AND** the declaring app's matrix permits the signed-in user
- **THEN** the install proceeds

#### Scenario: The leaf matrix declines

- **WHEN** the declaring app's matrix declines
- **THEN** the response is 403
- **AND** no component is written

#### Scenario: An administrator is still asked

- **WHEN** the caller is an instance administrator
- **AND** the declaring app's matrix declines
- **THEN** the response is 403

#### Scenario: Anonymous never reaches the matrix

- **WHEN** an anonymous caller posts an install against an action posture
- **THEN** the response is 403
- **AND** the matrix is not consulted

### Requirement: An unresolvable authorizer MUST refuse, never permit

Every failure to decide MUST be a refusal, and MUST be logged at ERROR with the
name that could not be resolved.

The lookup is duck-typed by convention, which is the shape that has repeatedly
produced silent no-ops in this fleet. A no-op here is an install that skipped
its authorization check and then reported success, so the absence of an answer
is treated as "no", never as "no objection".

#### Scenario: The service is not in the container

- **WHEN** `OCA\<Studly>\Service\ActionAuthService` cannot be resolved
- **THEN** the install is refused
- **AND** an ERROR is logged naming the action

#### Scenario: The service has no can() method

- **WHEN** the class resolves but exposes no `can()`
- **THEN** the install is refused

This is the shape a RENAME produces: the class still resolves and the method is
gone, so a check that only tested existence would sail past it.

#### Scenario: The matrix throws

- **WHEN** `can()` throws
- **THEN** the install is refused

ADR-023's own `requireAction()` throws to DENY, so anything propagating out of
the matrix must not be read as consent.

### Requirement: A store MUST declare its discovery source, defaulting to OpenRegister

The `store` block MAY carry a `source` key. Its value MUST be one of
`openregister` or `github`. When the key is absent the source MUST be
`openregister`, so every store declared before this change keeps its behaviour
exactly.

An unknown `source` MUST be treated as a malformed block: the manifest is
DISABLED and the store does not appear. Falling back to `openregister` would
silently point an app at a registry it never asked for.

#### Scenario: An absent source defaults to OpenRegister

- **WHEN** a `store` block declares no `source`
- **THEN** the manifest reports source `openregister`
- **AND** discovery uses the registry URL configured for the app

#### Scenario: An unknown source disables the store

- **WHEN** a `store` block declares `"source": "npm"`
- **THEN** the manifest reports `enabled: false`
- **AND** no marketplace entry may be rendered for that app

### Requirement: A GitHub source MUST discover by topic against a compile-time host

When `source` is `github` the block MUST carry a non-empty `topics` list. Each
topic is searched as `topic:<topic>` against the GitHub repository search API.

The host MUST be a compile-time constant. A `github` source MUST NOT read the
app's `registry_url`, and an app MUST NOT be able to influence the host it
reaches. This is deliberately stricter than the `openregister` source, where the
URL is admin-configured and therefore SSRF-guarded at request time: here there
is no URL to guard.

A `github` source MUST NOT be reported as `not_configured` for want of a
registry URL. It is configured by its topics.

#### Scenario: Topics are searched and results merged

- **WHEN** a store declares `"source": "github"` with two topics
- **THEN** each topic is searched
- **AND** the results are merged into one card list, de-duplicated by
  `owner/repo`

#### Scenario: A GitHub source with no topics is malformed

- **WHEN** a store declares `"source": "github"` and an empty `topics` list
- **THEN** the manifest reports `enabled: false`

### Requirement: Search MUST distinguish rate limiting from unreachability

The outcome vocabulary gains `rate_limited`. A source that answers with an
explicit rate-limit signal MUST map to `rate_limited`, never to
`store_unreachable`.

The two are not interchangeable to a reader: `rate_limited` means wait, or add
a credential to raise the limit; `store_unreachable` means the network or the
registry is broken. Reporting the first as the second sends someone to debug a
network that is fine.

A rate-limited or unreachable search MUST still answer HTTP 200 with an empty
card list, as today. A store that cannot reach its registry is not a server
error in the app that hosts the page.

#### Scenario: GitHub rate limiting is reported as itself

- **WHEN** the GitHub API answers 403 with a rate-limit signal
- **THEN** the outcome is `rate_limited`
- **AND** the HTTP status is 200
- **AND** the card list is empty

#### Scenario: A network failure stays unreachable

- **WHEN** the request to the source throws a connection error
- **THEN** the outcome is `store_unreachable`

### Requirement: Discovery MUST be cached per source

A source MUST declare a cache lifetime for search results, and the engine MUST
NOT issue an upstream request while a cached answer for the same query is live.

Without this, every keystroke past the debounce is an upstream call, and the
GitHub search API's unauthenticated limit is low enough that a single user
typing exhausts it — turning a working store into a `rate_limited` one for
everybody on the instance.

#### Scenario: A repeated query inside the cache window issues no request

- **WHEN** the same query is searched twice inside the cache lifetime
- **THEN** exactly one upstream request is made
- **AND** both answers carry the same cards

### Requirement: A store MUST declare who may install, defaulting to admin

The `store` block MAY carry an `installAuth` key. Its value MUST be one of
`admin`, `authenticated`, or `action:<name>`. When the key is absent the
posture MUST be `admin`, so every store declared before this key existed keeps
the posture it has.

An unrecognised value MUST disable the block. Falling back to `admin` would
silently REMOVE a capability from an app that asked for a weaker gate, which is
as wrong as granting one it did not ask for and considerably harder to notice:
the store still works, for fewer people, for no stated reason.

#### Scenario: An absent installAuth stays admin-only

- **WHEN** a `store` block declares no `installAuth`
- **AND** a signed-in non-administrator posts an install
- **THEN** the response is 403

#### Scenario: An authenticated store admits a non-administrator

- **WHEN** a `store` block declares `"installAuth": "authenticated"`
- **AND** a signed-in non-administrator posts an install
- **THEN** the install proceeds

#### Scenario: Anonymous is refused whatever the posture

- **WHEN** a `store` block declares `"installAuth": "authenticated"`
- **AND** an anonymous caller posts an install
- **THEN** the response is 403, on the same guard as a permitted-but-unauthorised
  user

`authenticated` is the weakest posture the vocabulary offers and it still means
signed in. Anonymous and not-permitted share one guard deliberately, which is
the contract the endpoint already had.

#### Scenario: An unknown posture disables the store

- **WHEN** a `store` block declares `"installAuth": "everyone"`
- **THEN** the manifest reports `enabled: false`

### Requirement: A loosened install posture MUST NOT widen the allowlist

`installAuth` decides WHO may install. `installable` decides WHAT an install
may write. The two MUST remain independent: an `authenticated` install MUST
refuse every schema the allowlist omits, exactly as an `admin` install does,
and an empty allowlist MUST still refuse everything.

This is stated as its own requirement because the two keys sit side by side in
the same block and read like a pair. They are not one: relaxing the gate on the
door does not enlarge the room.

#### Scenario: A non-administrator cannot write a schema the allowlist omits

- **WHEN** a store declares `"installAuth": "authenticated"` and an
  `installable` list that omits `case`
- **AND** a signed-in non-administrator installs an item whose component names
  `case`
- **THEN** that component is refused
- **AND** the refusal is reported per component, as for an administrator

### Requirement: A component MUST declare its install operation, defaulting to writing an object

A component MAY carry an `op` key. Its value MUST be one of `writeObject` or
`setAppConfig`. Absent, it MUST be `writeObject`, so every item that installs
today installs unchanged.

An unrecognised `op` MUST be refused for that component and reported, and MUST
NOT abort the remaining components. A component whose operation this server
does not understand is the registry's mistake, not a reason to deny an
administrator the components it does understand.

#### Scenario: A component with no op writes an object

- **WHEN** a component declares no `op`
- **THEN** it is installed through the object write path
- **AND** the `installable` allowlist applies to it

#### Scenario: An unknown op is refused, and the rest still install

- **WHEN** an item carries one component with `"op": "summonDaemon"` and one
  ordinary object component
- **THEN** the unknown component is reported as refused
- **AND** the object component is still installed

### Requirement: A config write MUST be allowlisted by key and scoped to the declaring app

`setAppConfig` MUST write only into the declaring app's own config namespace,
and only for a key named in the store block's `configurable` list.

An empty or absent `configurable` list MUST refuse every key.

🔴 This is a second security boundary, not a convenience. `installable` stops a
remote registry naming any schema the app owns; an app's config namespace holds
registry URLs, tokens and feature flags, so an unallowlisted config write is a
remote actor toggling whatever it names. The default must therefore be "refuse
everything", exactly as it is for schemas.

#### Scenario: An allowlisted key is written

- **WHEN** a store declares `"configurable": ["enableSoapAdapter"]`
- **AND** a component sets that key
- **THEN** the value is written to the declaring app's config

#### Scenario: A key outside the allowlist is refused

- **WHEN** a component sets a key the `configurable` list omits
- **THEN** the component is refused and reported

#### Scenario: An absent configurable list refuses everything

- **WHEN** a store declares no `configurable` list
- **AND** a component declares `setAppConfig`
- **THEN** the component is refused

#### Scenario: A config write cannot address another app

- **WHEN** a component's key names another app's namespace
- **THEN** the key is treated as a plain key of the declaring app, never as a
  cross-app write

### Requirement: A config value MUST be a scalar

`setAppConfig` MUST accept only a boolean, string or number. An array or object
MUST be refused.

Config is a flat key-value store, and silently serialising a structure into it
produces a value the app will later read back as a string it never wrote.

#### Scenario: A structured value is refused

- **WHEN** a component sets a key to an object
- **THEN** the component is refused and reported
