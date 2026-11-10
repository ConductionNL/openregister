# unified-search-file-content Delta: unified-search-file-content

**Status**: proposed
**Scope**: openregister

## Purpose

Text OpenRegister extracts from attached files becomes findable through the
fleet's Nextcloud unified-search provider, attributed to the object that owns
the file, under the provider's existing security contract.

## MODIFIED Requirements

### Requirement: The unified-search provider MUST search extracted file text

`ObjectsProvider` SHALL pass `_content_search: true` to
`searchObjectsPaginated()`, so a `_search` term matches text extracted from
attached files as well as object fields. Results SHALL be the owning OBJECT,
never a bare chunk.

#### Scenario: A term found only inside an attached file returns its object

- **GIVEN** an object whose own fields do not contain a term, with an attached
  file whose extracted text does
- **WHEN** an entitled user searches Nextcloud for that term
- **THEN** the owning object is returned, with its deep-link URL, title and
  icon
- **AND** the same search BEFORE this change returns nothing — asserted, so the
  test measures the change rather than the fixture

#### Scenario: Object-field matches are unaffected

- **GIVEN** the searches that worked before this change
- **THEN** they return the same objects, in the same order
- **NOTE** content search widens a result set. A change that reorders or drops
  existing hits is a regression in the fleet's main search bar, and would be
  noticed as "search got worse" rather than as this change

#### Scenario: A user gets no file-text hit from an object they may not read

- **GIVEN** an attached file on an object outside the user's RBAC or tenant
  scope
- **THEN** its text produces no result
- **AND** an entitled user searching the same term DOES get it — both in one
  test, because a content search that matched nothing at all would satisfy the
  refusal by itself

### Requirement: Excerpts MUST continue to derive from the rendered object

An excerpt SHALL be derived from the object as rendered for that user, not
from chunk text. Field-level redaction SHALL therefore apply to excerpts
unchanged.

#### Scenario: A redacted field does not appear in an excerpt via file text

- **GIVEN** a user redacted out of a field, and an attached file whose
  extracted text contains that field's value
- **WHEN** the object is returned as a content-search hit
- **THEN** the excerpt does not contain the redacted value
- **NOTE** this is the one way this change could turn a widened search into a
  disclosure: the object stays correctly filtered while the excerpt leaks

## ADDED Requirements

### Requirement: Content search MUST be bounded and measured

The provider SHALL bound the chunk-candidate set and SHALL NOT issue an
unbounded keyword pass. The latency cost SHALL be measured before and after.

#### Scenario: The candidate set is capped

- **GIVEN** a term matching a very large number of chunks
- **THEN** the provider returns within its declared bound rather than scanning
  the corpus

#### Scenario: The cost is recorded, not assumed

- **GIVEN** a representative corpus
- **WHEN** the same query set runs with and without `_content_search`
- **THEN** both latencies are recorded in the change
- **AND** the comparison is like-for-like — same corpus, same warm/cold state,
  same query set. A single run against a warm cache is not a measurement, and
  this provider runs in the global search bar where regressions are felt by
  every user at once
