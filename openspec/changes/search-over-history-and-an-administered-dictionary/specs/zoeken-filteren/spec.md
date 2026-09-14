# zoeken-filteren

## ADDED Requirements

### Requirement: The object query filters on values a record used to hold (REQ-SHD-001)

The object query SHALL accept predicates over a property's past values: that
the property ever held a given value, that it ever held one of a set, and
that it changed within a period. The predicates SHALL be combinable with the
current-state filters of the same query, SHALL be paged and sorted like any
other result, and SHALL be compiled into the same access-filtered query, so
no record the caller may not read is matched, counted or facetted. A
predicate naming a property with no recorded history SHALL be refused,
naming the property, rather than returning an empty result.

#### Scenario: every case that ever went to bezwaar

- **GIVEN** three cases, one of which passed through `bezwaar` and is now `afgehandeld`
- **WHEN** the list is filtered on `status` having ever been `bezwaar`
- **THEN** that case is returned and the other two are not

#### Scenario: the history filter combines with a current filter

- **GIVEN** the same cases
- **WHEN** the list is filtered on `status` having ever been `bezwaar` and being `afgehandeld` now
- **THEN** only cases meeting both are returned

#### Scenario: a record the caller may not read is not matched

- **GIVEN** a case the caller has no read access to that was once in the filtered state
- **WHEN** the caller runs the history filter
- **THEN** the case is absent from the result and from the count
- @e2e exclude {access compilation, covered by unit tests}

#### Scenario: a property with no history is refused, not silently empty

- **GIVEN** a property whose history is not projected
- **WHEN** a history predicate names it
- **THEN** the query is refused naming the property
- @e2e exclude {validator, covered by unit tests}

### Requirement: The history predicate reads an indexed projection, not the trail (REQ-SHD-002)

The past values a predicate reads SHALL come from a derived, indexed
projection carrying the object, the property, the value, the moment it was
entered and the moment it was left. The projection SHALL be rebuildable from
the recorded transitions, resumable and bounded, and SHALL be pruned on the
same retention as the records it derives from. A history query SHALL NOT
scan the audit trail.

#### Scenario: a rebuild reproduces the same answers

- **GIVEN** a populated projection and the transitions it was derived from
- **WHEN** the projection is dropped and rebuilt
- **THEN** the same history filter returns the same records

#### Scenario: a rebuild resumes where it stopped

- **GIVEN** a rebuild interrupted part-way
- **WHEN** it is started again
- **THEN** it continues from its cursor rather than starting over
- @e2e exclude {background job, covered by unit tests}

### Requirement: An administrator maintains synonyms and stopwords per language (REQ-SHD-003)

The system SHALL hold synonym groups and stopwords as administered records,
per language, editable through an admin surface and readable through the
objects API. A search term SHALL be expanded with the members of every
matching synonym group of the active language before the query runs, and
declared stopwords SHALL be dropped from the term. A change to the
dictionary SHALL take effect on the next query, with no index rebuild.

#### Scenario: a citizen's word finds the municipality's record

- **GIVEN** a synonym group holding `omgevingsvergunning` and `bouwvergunning` for Dutch
- **WHEN** a citizen searches for `bouwvergunning`
- **THEN** records holding `omgevingsvergunning` are returned

#### Scenario: an edit takes effect at once

- **GIVEN** a search that returns nothing for a term
- **WHEN** an administrator adds that term to a synonym group and the search is repeated
- **THEN** the results include the group's other members

#### Scenario: the dictionary is per language

- **GIVEN** a group declared for Dutch only
- **WHEN** the same term is searched with English as the active language
- **THEN** the expansion does not apply
- @e2e exclude {language negotiation, covered by unit tests}

### Requirement: Expansion is bounded, reported, and never empties a query (REQ-SHD-004)

Term expansion SHALL be bounded by an administered cap per group and per
query, and a query that would exceed the cap SHALL be expanded up to it and
reported as capped. The response SHALL name the terms the query was expanded
to. When removing stopwords would leave no term at all, the original term
SHALL be used unchanged.

#### Scenario: the search says what it searched for

- **GIVEN** an expanded query
- **WHEN** the response is read
- **THEN** it names the terms the query was expanded to

#### Scenario: a large group is capped and says so

- **GIVEN** a synonym group larger than the administered cap
- **WHEN** a query matching it runs
- **THEN** the expansion stops at the cap and the response reports it as capped
- @e2e exclude {cap behaviour, covered by unit tests}

#### Scenario: a query of only stopwords still searches

- **GIVEN** a query consisting entirely of declared stopwords
- **WHEN** it runs
- **THEN** the original term is searched rather than an empty one
