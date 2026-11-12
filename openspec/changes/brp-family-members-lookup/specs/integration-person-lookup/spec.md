# integration-person-lookup Specification (delta)

## ADDED Requirements

### Requirement: The BRP provider answers a resident's partner and children, minimised (REQ-BRP-FAM-001)

`BrpPersonProvider` MUST offer `lookupFamily(bsn, relations, sameAddressOnly)` that answers the resident's current partner and living children, each with an opaque reference, the display name, the relation, the birth year and whether they live on the resident's address. It MUST drop a dissolved partnership and a deceased relative, MUST drop relatives on another address when `sameAddressOnly` is true, and MUST NOT return a relative's BSN, full birth date or address. It MUST return the Wet-BRP audit metadata, and MUST degrade as `lookupByBsn()` does when the source is unavailable. `resolveFamilyReference(bsn, reference)` MUST answer the relative's BSN only when the reference belongs to a current relative of that same resident.

#### Scenario: a resident who moves with her partner and two children
- **WHEN** portaliq asks the family of BSN 999990627 with relations partner and children, same address only
- **THEN** three people are returned with name, relation and birth year, each with `sameAddress` true, and no BSN
- @e2e exclude backend provider call; covered by PHPUnit against a recorded Haal Centraal answer and against the mock register

#### Scenario: a former partner and a child living elsewhere
- **WHEN** the resident's partnership has ended and one child lives on another address, and `sameAddressOnly` is true
- **THEN** neither the former partner nor that child is returned
- @e2e exclude backend provider call; covered by PHPUnit

#### Scenario: a reference from another resident's family
- **WHEN** `resolveFamilyReference` is called with a reference from a different resident's answer
- **THEN** it answers null
- @e2e exclude backend provider call; covered by PHPUnit

#### Scenario: the BRP source is down
- **WHEN** the Haal Centraal source is unreachable
- **THEN** the answer is `{ unavailable: true, cause: "upstream-service-down", results: [], total: 0 }` and nothing is thrown
- @e2e exclude backend provider call; covered by PHPUnit
