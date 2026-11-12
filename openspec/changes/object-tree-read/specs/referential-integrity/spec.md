# referential-integrity

## ADDED Requirements

### Requirement: An object's tree along its declared parent property can be read (REQ-OTR-001)

OpenRegister SHALL return the descendants, the ancestors, or both of one object along
the parent property its schema declares (`x-openregister-hierarchy.parent`, else
`x-openregister-party.parentProperty`), each with its level, its parent and its name.
The walk MUST stop at the declared `maxDepth`, at the requested depth and at the
instance node cap, MUST never visit an object twice, and MUST say in `truncatedBy`
which bound stopped it when more objects exist past it. A schema without a declared
parent SHALL answer with the root alone.

#### Scenario: a holding's subsidiaries by level

- **GIVEN** an organisation schema declaring `x-openregister-party` with `parentProperty` `parentOrganisation`
- **AND** a holding with two subsidiaries, one of which has a subsidiary of its own
- **WHEN** a client reads the tree of the holding
- **THEN** it gets four nodes with levels 0, 1, 1 and 2, each with its name and parent
- @e2e exclude {API read, covered by ObjectTreeServiceTest with a real schema fragment}

#### Scenario: a cycle in the data ends the walk

- **GIVEN** three organisations whose parents form a cycle
- **WHEN** a client reads the tree of one of them
- **THEN** each organisation appears once and the answer returns
- @e2e exclude {cycle guard, covered by ObjectTreeServiceTest}

#### Scenario: a level wider than the node cap is not read in full

- **GIVEN** a holding with fifty subsidiaries and an instance node cap of ten
- **WHEN** a client reads the holding's tree
- **THEN** the level is read with a row limit of at most one more than the cap still admits, never in full
- **AND** `truncated` is true with `truncatedBy` `cap`
- @e2e exclude {work bound, covered by ObjectTreeServiceTest}

#### Scenario: a depth below the tree's height is reported

- **GIVEN** the holding of the first scenario
- **WHEN** a client reads its tree with depth 1
- **THEN** it gets the holding and its two subsidiaries
- **AND** `truncated` is true with `truncatedBy` `depth`
- @e2e exclude {bound logic, covered by ObjectTreeServiceTest}

### Requirement: The tree read honours the caller's read access (REQ-OTR-002)

Every node of the tree SHALL pass the caller's read access. A node the caller may not
read MUST be left out together with everything reached only through it, and the walk up
MUST stop at the first ancestor the caller may not read. A root the caller may not read
SHALL be answered with 404 before anything is walked.

#### Scenario: a hidden subsidiary is not returned

- **GIVEN** the holding of REQ-OTR-001 and a user who may read the holding, the second subsidiary and the grandchild, but not the first subsidiary
- **WHEN** that user reads the holding's tree
- **THEN** only the holding and the second subsidiary are returned
- @e2e exclude {RBAC pruning, covered by ObjectTreeServiceTest; a live check is task 3.1}

#### Scenario: an unreadable root is not found

- **GIVEN** an organisation the user may not read
- **WHEN** the user requests its tree
- **THEN** the answer is 404 and no level is read
- @e2e exclude {covered by ObjectRelationsControllerTreeTest}

#### Scenario: a failing access check is an incomplete tree

- **GIVEN** a holding with subsidiaries and an access check that cannot be established
- **WHEN** a client reads the holding's tree
- **THEN** no subsidiary is returned
- **AND** `truncated` is true with `truncatedBy` `unreadable`, not `null`
- @e2e exclude {fail-closed path, covered by ObjectTreeServiceTest}

#### Scenario: the topmost ancestor does not name a hidden parent

- **GIVEN** a subsidiary below a parent the caller may read, which is below a grandparent the caller may not
- **WHEN** the caller reads the subsidiary's ancestors
- **THEN** the parent is returned with `parent` null
- @e2e exclude {RBAC pruning, covered by ObjectTreeServiceTest}

### Requirement: The tree carries only what the caller may read of an object's properties (REQ-OTR-003)

The `fields` a client names SHALL be read from the object as every other read of it
renders it: a `writeOnly` property is never returned, a property whose `authorization.read`
the caller does not satisfy is never returned, and an encrypted property is returned
decrypted for a caller who may read it. This holds for the root and for every ancestor
and descendant, and `fields` is a projection of that result.

#### Scenario: a write-only secret is not returned by naming it

- **GIVEN** a schema property `secret` with `writeOnly: true` and a property the caller's groups may not read
- **WHEN** the caller reads the tree with both names in `fields`
- **THEN** neither value is returned on any node
- @e2e exclude {redaction seam, covered by ObjectTreeServiceTest}

### Requirement: The tree read honours doelbinding (REQ-OTR-004)

On a register or schema that asks for a declared purpose, the tree read (and the relation
graph and its CSV) SHALL refuse a request that names none, with the same 403 body the
object read answers, after the read check and before anything is walked.

#### Scenario: a purpose-bound schema refuses an unbound tree read

- **GIVEN** a schema that requires a declared purpose and a caller who may read the object
- **WHEN** the caller reads the tree without a purpose
- **THEN** the answer is 403 and no level is read
- @e2e exclude {covered by ObjectRelationsControllerTreeTest}
