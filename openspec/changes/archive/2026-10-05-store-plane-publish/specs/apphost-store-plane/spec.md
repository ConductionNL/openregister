## ADDED Requirements

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
