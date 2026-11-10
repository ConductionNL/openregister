unified-search-provider
---
status: draft
---
# Unified Search Provider

## Purpose

OpenRegister provides Nextcloud unified search (top-bar magnifier) over
register objects for the entire fleet, centrally, via
`lib/Search/ObjectsProvider.php`. Leaf apps (pipelinq, procest, planix,
…) do NOT register their own `OCP\Search\IProvider`; they participate
by claiming (register, schema) pairs through the existing deep-link
registry, which supplies result URLs, icons, and display names. The
provider enforces OR RBAC, tenant isolation, the published predicate,
and schema-level search exposure in one place, and returns labeled,
excerpted, paginated results.

Supersedes the per-app search-provider suggestions for pipelinq,
procest, and planix from `FEATURE-REEVALUATION-2026-06-11/`.

@e2e exclude The unified-search surface is rendered entirely by Nextcloud's own top-bar search chrome (no OpenRegister Vue component); the provider is a server-side OCP\Search\IProvider. Its observable behaviour is covered by PHPUnit (tests/Unit/Search/ObjectsProviderTest.php) and by Newman against the OCS search endpoint (tests/integration/openregister-unified-search.postman_collection.json), per the Playwright-UI-only / Newman-for-API rule.

## ADDED Requirements

### Requirement: OpenRegister MUST be the single fleet-wide unified search provider for register objects

OpenRegister MUST register exactly one `OCP\Search\IProvider`
(`ObjectsProvider`, id `openregister_objects`) covering objects in all
registers. Consuming apps MUST NOT register their own search provider
for objects stored in OpenRegister; they declare themselves via the
deep-link registry instead.

#### Scenario: One provider serves results for objects owned by multiple apps
- GIVEN pipelinq objects (register `pipelinq`) and procest objects (register `case-management`) exist
- WHEN a user searches a term matching one object of each app in Nextcloud unified search
- THEN both results appear under the single `openregister_objects` provider section
- AND neither pipelinq nor procest contributes its own provider section

#### Scenario: Standalone install keeps working
- GIVEN OpenRegister is installed without any consuming apps
- WHEN a user searches in unified search
- THEN the provider returns matching objects with OpenRegister's own labeling and object-view URLs

### Requirement: Search results MUST respect OR RBAC, tenant isolation, and the published predicate

The provider MUST delegate to
`ObjectService::searchObjectsPaginated(query, _rbac: true,
_multitenancy: true)` and MUST NOT apply a weaker (or duplicate)
access filter of its own. The result set MUST contain only objects the
searching user may read: objects granted via RBAC scopes, plus objects
readable through the published predicate (`@self.published` set and in
the past, `@self.depublished` unset or in the future). Soft-deleted
objects MUST never be returned.

#### Scenario: User only sees objects they may read
- GIVEN user `alice` has an RBAC read grant on schema `client` but not on schema `salary`
- AND a `salary` object and a `client` object both match the term `Jansen`
- WHEN `alice` searches for `Jansen`
- THEN the `client` object is in the results
- AND the `salary` object is NOT in the results

#### Scenario: Published objects are findable without an explicit grant
- GIVEN user `bob` has no RBAC grant on schema `publication`
- AND a `publication` object matching `subsidieregeling` has `@self.published` in the past and no `depublished`
- WHEN `bob` searches for `subsidieregeling`
- THEN the published object IS in the results

#### Scenario: Unpublished and depublished objects are hidden from ungranted users
- GIVEN user `bob` has no RBAC grant on schema `publication`
- AND one matching object has `@self.published` unset and another has `@self.depublished` in the past
- WHEN `bob` searches for the matching term
- THEN neither object is in the results

#### Scenario: Tenant isolation in search results
- GIVEN organisations `gemeente-a` and `gemeente-b` each have objects matching `kerkstraat`
- WHEN a user whose active organisation is `gemeente-a` searches for `kerkstraat`
- THEN only `gemeente-a` objects are returned

#### Scenario: Soft-deleted objects never appear
- GIVEN an object matching the term has been soft-deleted
- WHEN any user searches for the term
- THEN the deleted object is NOT in the results

### Requirement: Schemas MUST control their unified-search exposure via the `searchable` flag

The provider MUST honour the existing `Schema.searchable` boolean
(default `true`). Objects of a schema with `searchable = false` MUST
NOT appear in unified search results, and the exclusion MUST be applied
inside the search query (not by post-filtering a result page). An
explicit `schema` custom filter targeting a non-searchable schema MUST
return an empty result set.

#### Scenario: Opted-out schema disappears from search
- GIVEN schema `internal-note` has `searchable = false`
- AND an `internal-note` object matches the term `reorganisatie`
- WHEN a user with full RBAC grants searches for `reorganisatie`
- THEN no `internal-note` object is in the results

#### Scenario: Default schemas remain searchable
- GIVEN a newly created schema with no explicit `searchable` value
- WHEN its objects match a search term
- THEN they appear in unified search (default is `true`)

#### Scenario: Explicit schema filter cannot bypass opt-out
- GIVEN schema `internal-note` (id 17) has `searchable = false`
- WHEN a search is performed with the custom filter `schema=17`
- THEN the provider returns an empty result set

### Requirement: Results MUST be labeled per owning app and register

Each `SearchResultEntry` MUST identify its owner: for a (register,
schema) pair claimed in the deep-link registry, the entry icon MUST be
the registered app icon (rendered rounded) and the subline MUST start
with `{App display name} · {Register title} · {Schema title}`. For
unclaimed pairs the entry keeps the OpenRegister icon and the subline
starts with `Open Register · {Register title} · {Schema title}`.

#### Scenario: Claimed result carries the owning app's label and icon
- GIVEN pipelinq registered `pipelinq::client` with `displayName: 'Pipelinq'`
- WHEN a search returns a client object
- THEN the entry icon is pipelinq's registered icon (rounded)
- AND the subline starts with `Pipelinq · `

#### Scenario: Display name defaults to the app id
- GIVEN procest registered `case-management::case` without a `displayName`
- WHEN a search returns a case object
- THEN the subline starts with `procest · `

#### Scenario: Unclaimed result keeps the OpenRegister label
- GIVEN no app has claimed `case-management::audit-log`
- WHEN a search returns an `audit-log` object
- THEN the entry icon is `icon-openregister`
- AND the subline starts with `Open Register · `

#### Scenario: Mixed result page labels every entry by its own owner
- GIVEN one matching object each from `pipelinq::client` (claimed), `case-management::case` (claimed), and `case-management::audit-log` (unclaimed)
- WHEN a single search returns all three
- THEN each entry carries its own owner's label and icon independently

### Requirement: Result URLs MUST deep-link to the owning app's object detail route

The provider MUST resolve each entry URL through
`DeepLinkRegistryService::resolveUrl()` using the object's `@self`
data; when no registration exists for the (register, schema) pair, the
URL MUST fall back to
`IURLGenerator::linkToRoute('openregister.objects.show', ...)`. Apps
declare their detail-route pattern exclusively via the existing
boot-time `DeepLinkRegistrationEvent`; this capability introduces no
second declaration mechanism.

#### Scenario: Claimed result links into the owning app
- GIVEN pipelinq registered `urlTemplate: '/apps/pipelinq/#/clients/{uuid}'` for `pipelinq::client`
- WHEN a search returns the client object with UUID `abc-123`
- THEN the entry URL is `/apps/pipelinq/#/clients/abc-123`

#### Scenario: Unclaimed result links to OpenRegister's object view
- GIVEN no registration exists for the matched object's (register, schema) pair
- WHEN the entry is built
- THEN its URL is the `openregister.objects.show` route for that register, schema, and UUID

### Requirement: Result sublines MUST contain an excerpt of the matched content

When the search was term-driven, the subline MUST end with an excerpt:
up to ±60 characters of context around the first case-insensitive
occurrence of the term across the object's top-level scalar string
values (in schema property order, `@self` excluded), ellipsised, with
the matched substring included verbatim. When no string field contains
the term (numeric/relational match) or the search is filter-only, the
excerpt MUST fall back to the object's `summary`, then a truncated
`description`, then be omitted. Excerpts MUST be derived from the
rendered object the user is allowed to read, so row/field-level
security applies to excerpt content.

#### Scenario: Excerpt around the matched term
- GIVEN an object whose `notes` field contains `…afspraak met mevrouw Jansen over de vergunning…`
- WHEN a user searches for `Jansen`
- THEN the entry subline ends with an ellipsised fragment containing the verbatim substring `Jansen`

#### Scenario: Fallback when the match is not in a string field
- GIVEN an object matched on a numeric field and having `summary = 'Kapvergunning eik Kerkstraat'`
- WHEN the entry is built
- THEN the subline excerpt is `Kapvergunning eik Kerkstraat`

#### Scenario: Field-level security redacts excerpt sources
- GIVEN a field hidden from the searching user by field-level security contains the matched term
- WHEN the entry is built
- THEN the excerpt MUST NOT reveal the hidden field's content
- AND the excerpt falls back per the summary/description chain

### Requirement: The provider MUST paginate results with a cursor

The provider MUST read the query limit (capped at 25 per page) and the
cursor (integer offset serialised as string) from `ISearchQuery`, pass
them as `_limit`/`_offset`, and return `SearchResult::paginated()` with
the next-offset cursor when a full page was returned, or
`SearchResult::complete()` when the page was short or empty.

#### Scenario: Full first page returns a cursor
- GIVEN 60 objects match the term
- WHEN the first search request (no cursor) is handled
- THEN 25 entries are returned with a paginated result carrying cursor `25`

#### Scenario: Requesting the next page continues without duplicates
- GIVEN the previous page returned cursor `25`
- WHEN the search is repeated with cursor `25`
- THEN entries 26–50 are returned and no UUID from the first page reappears

#### Scenario: Short page completes the result
- GIVEN 10 objects match the term
- WHEN the first page is handled
- THEN all 10 entries are returned as a complete (non-paginated) result
