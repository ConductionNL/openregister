## ADDED Requirements

### Requirement: Schema-scoped quality statistics
OpenRegister SHALL expose a read-only aggregation that, given a `(register, schema)` pair,
returns quality statistics over the objects of that schema. The statistics SHALL be computed
from the already-materialised `qualityScore` and `qualityStatus` fields written by the
`data-quality-scoring` on-save materialisation — the surface MUST NOT re-score objects. The
statistics SHALL include: the average `qualityScore` across the scoped set; a count per status
bucket (`good` / `fair` / `poor`), where the bucket boundaries derive from the schema's
`x-openregister-quality` `thresholds` via `QualityScorer::status()`; a score-distribution
histogram over the `[0,1]` range; and the total number of objects considered. The object set
MUST be read through `ObjectService::findAll` so it is RBAC- and tenant-scoped to the calling
user; objects the caller cannot read MUST NOT contribute to any statistic. When the scoped set
is empty, the aggregation SHALL return zeroed counts and a null (or zero) average rather than
failing.

#### Scenario: Average and buckets over a scored schema
- **WHEN** a steward requests quality statistics for a register/schema whose objects carry materialised `qualityScore` and `qualityStatus`
- **THEN** the response MUST include the average `qualityScore`, a count for each of `good`, `fair`, and `poor`, and the total object count
- **AND** the sum of the three bucket counts MUST equal the total object count

#### Scenario: Status buckets honour the schema thresholds
- **WHEN** the schema's `x-openregister-quality` annotation declares `thresholds` `{ good: 0.8, fair: 0.5 }`
- **THEN** an object scoring `0.9` MUST be counted in `good`, `0.6` in `fair`, and `0.2` in `poor`, matching `QualityScorer::status()`

#### Scenario: Score distribution histogram
- **WHEN** quality statistics are requested
- **THEN** the response MUST include a histogram of `qualityScore` frequencies across contiguous buckets spanning the `[0,1]` range
- **AND** the histogram bucket counts MUST sum to the total object count

#### Scenario: Empty schema returns zeroed statistics
- **WHEN** statistics are requested for a register/schema with no readable objects
- **THEN** the response MUST return zero bucket counts and a total of `0` without erroring

#### Scenario: RBAC and tenant scoping are respected
- **WHEN** two tenants hold objects under the same schema
- **THEN** the statistics returned to a caller MUST reflect only the objects that caller is authorised to read

### Requirement: Lowest-quality object listing
OpenRegister SHALL expose a read-only endpoint that lists the objects of a `(register, schema)`
ordered by ascending `qualityScore` (worst first), so stewards can triage the objects most in
need of attention. The listing SHALL support pagination and SHALL support filtering by
`qualityStatus` and sorting by `qualityScore` or `qualityStatus`. The listing MUST be served
through `ObjectService::findAll` (RBAC + tenant scoped); an object the caller cannot read MUST
NOT appear. Each returned item SHALL carry at least the object identifier, its `qualityScore`,
and its `qualityStatus`.

#### Scenario: Worst objects first
- **WHEN** a steward requests the lowest-quality listing for a register/schema
- **THEN** the objects MUST be returned in ascending `qualityScore` order (lowest first)

#### Scenario: Filter by status
- **WHEN** the request filters `qualityStatus=poor`
- **THEN** only objects whose materialised `qualityStatus` is `poor` MUST be returned

#### Scenario: Pagination
- **WHEN** the request supplies a page size and page offset
- **THEN** the response MUST return at most one page of items plus enough metadata (total and/or next page) to page through the full result set

#### Scenario: Listing respects RBAC
- **WHEN** the caller lacks read access to some objects of the schema
- **THEN** those objects MUST NOT appear in the listing

### Requirement: Duplicate-candidate listing
OpenRegister SHALL expose a read-only endpoint that returns duplicate-candidate pairs for a
`(register, schema)`, computed by the existing `DuplicateDetectionService::findDuplicates`. The
endpoint SHALL accept an optional similarity `threshold`; when omitted, the schema's
`x-openregister-dedup` annotation threshold (or the service default) applies. Match rules are
taken from the schema's `x-openregister-dedup` annotation. Each returned pair SHALL carry the
two object identifiers, the similarity score, and the fields matched on. Results SHALL be
ordered by descending score and SHALL support pagination. The endpoint MUST be read-only: it
MUST NOT perform, trigger, or expose any merge action. The candidate set MUST be RBAC- and
tenant-scoped (inherited from `DuplicateDetectionService`, which reads via
`ObjectService::findAll`).

#### Scenario: Candidate pairs highest score first
- **WHEN** a steward requests duplicate candidates for a register/schema declaring `x-openregister-dedup` match rules
- **THEN** the response MUST list candidate pairs ordered by descending similarity score
- **AND** each pair MUST include both object identifiers, the score, and the matched fields

#### Scenario: Threshold filters weak pairs
- **WHEN** the request supplies a `threshold` higher than the annotation default
- **THEN** only pairs scoring at or above the supplied threshold MUST be returned

#### Scenario: No merge side effects
- **WHEN** the duplicate-candidate endpoint is called
- **THEN** no objects MUST be merged, modified, or deleted; the call MUST be side-effect-free

#### Scenario: Pagination over candidate pairs
- **WHEN** the number of candidate pairs exceeds one page
- **THEN** the response MUST return one page of pairs plus metadata sufficient to retrieve the remaining pairs

### Requirement: Authenticated steward endpoints
The MDM read/aggregation endpoints SHALL be authenticated steward endpoints, not public. Every
endpoint SHALL be reachable by an authenticated Nextcloud user (declared `@NoAdminRequired`) and
MUST NOT be marked `@PublicPage`. Authorisation to individual objects MUST be enforced in the
service layer via `ObjectService`'s RBAC and multitenancy scoping — the endpoints MUST NOT
bypass or disable that scoping. Every controller method exposed SHALL be registered in
`appinfo/routes.php` and carry an explicit auth annotation.

#### Scenario: Anonymous access is rejected
- **WHEN** an unauthenticated request hits any MDM read endpoint
- **THEN** Nextcloud MUST reject it (the endpoints are not `@PublicPage`)

#### Scenario: Authenticated caller sees only authorised data
- **WHEN** an authenticated steward calls any MDM read endpoint
- **THEN** the response MUST include only objects the caller is authorised to read under `ObjectService` RBAC and multitenancy scoping

#### Scenario: Every endpoint is routed and annotated
- **WHEN** the change is applied
- **THEN** every controller method backing an MDM read endpoint MUST have a matching entry in `appinfo/routes.php` and an explicit Nextcloud auth annotation
