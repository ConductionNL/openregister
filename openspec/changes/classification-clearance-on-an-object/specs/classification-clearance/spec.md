# classification-clearance

## ADDED Requirements

### Requirement: A schema declares its own ordered classification vocabulary

A schema MAY declare `x-openregister-classification` naming `property`, the
object property that carries the label, and `levels`, the labels in order from
least to most restrictive.

The order SHALL be the schema's. OpenRegister SHALL NOT hold a built-in
vocabulary, so a ZGW confidentiality scale, a security marking and a
three-step internal scale are all one mechanism configured differently.

Schema save SHALL refuse a block with fewer than two levels, a duplicate level,
or a `property` the schema does not declare.

#### Scenario: a schema declares eight ZGW levels and they order correctly

- **GIVEN** a schema declaring `property: vertrouwelijkheidaanduiding` and the eight ZGW levels in order
- **WHEN** an object carries `zaakvertrouwelijk`
- **THEN** it ranks above `intern` and below `geheim`
- @e2e exclude {ordering is a unit test on the comparator, not a browser path}

#### Scenario: a duplicate level is refused at schema save

- **WHEN** a schema is saved whose `levels` names one label twice
- **THEN** the save is refused naming the duplicated label
- @e2e exclude {schema validation is unit-tested}

### Requirement: A principal's clearance comes from declared group mappings

The classification block MAY declare `clearances`, a list of `{ group, level }`
entries, and `default`, the level a principal no entry names receives.

OpenRegister SHALL resolve a principal's clearance as the highest level among
the groups they hold, falling back to `default`, and falling back to the lowest
declared level when no `default` is declared. An administrator SHALL receive
the highest declared level.

#### Scenario: the highest mapped group wins

- **GIVEN** a user in two groups mapped to `intern` and `geheim`
- **WHEN** their clearance is resolved
- **THEN** it is `geheim`
- @e2e exclude {unit-tested on the clearance resolver}

#### Scenario: an unmapped user takes the declared default

- **GIVEN** a block declaring `default: intern` and a user in no mapped group
- **WHEN** their clearance is resolved
- **THEN** it is `intern`
- @e2e exclude {as above}

### Requirement: Read above a caller's clearance is refused on both layers

An object whose label ranks above the caller's clearance SHALL be refused on
the find path and SHALL be absent from the list and aggregation paths.

The list path SHALL apply the comparison inside the query, not after it, so the
page size, the total and any count reflect only what the caller may see.

#### Scenario: a page of results is not silently short

- **GIVEN** a register of forty objects of which twelve rank above the caller's clearance
- **WHEN** the caller lists with a page size of twenty
- **THEN** the page holds twenty objects and the total is twenty-eight
- @e2e exclude {specs only in this change; task 5.2 adds tests/e2e/api-direct/classification-clearance.spec.ts when the comparison ships}

#### Scenario: find and list agree on one object

- **GIVEN** an object ranking above the caller's clearance
- **WHEN** the caller finds it by uuid and then searches for it
- **THEN** the find is refused and the search does not return it
- @e2e exclude {the layer-agreement assertion is a unit test over both paths}

### Requirement: An unknown label is most restrictive and an unresolvable clearance is the default

An object whose label is absent, empty, or not in the declared `levels` SHALL
rank at the most restrictive declared level.

A clearance that cannot be resolved SHALL be the declared `default`.

Both SHALL be logged at warning, naming the object or the principal and the
value that could not be placed.

#### Scenario: an unlabelled object is hidden from everyone below the top level

- **GIVEN** an object whose classification property is empty
- **WHEN** a caller cleared to the second-highest level lists
- **THEN** the object is absent and a warning names it
- @e2e exclude {specs only in this change; task 5.2 covers it}

### Requirement: A ceiling refuses a scope outright

The classification block MAY declare `ceilings`, a list of
`{ scope, atOrAbove }` where `scope` is a principal from the existing
vocabulary.

An object ranking at or above `atOrAbove` SHALL NOT be reachable by that
principal, whatever a schema rule, a per-object grant or a share link would
otherwise permit. A ceiling refuses; it does not narrow.

#### Scenario: a share link cannot expose a document above the ceiling

- **GIVEN** a ceiling of `{ scope: public, atOrAbove: vertrouwelijk }` and an object labelled `geheim`
- **WHEN** a share link is minted for it and opened anonymously
- **THEN** the link is refused
- @e2e exclude {specs only in this change; task 5.2 probes with the anonymous principal}

#### Scenario: a grant does not beat a ceiling

- **GIVEN** the same object and an explicit per-object read grant to `public`
- **WHEN** an anonymous caller reads it
- **THEN** the read is refused and the refusal names the ceiling
- @e2e exclude {as above}

### Requirement: A write may raise a classification and not lower it below its floor

The classification block MAY declare a `floor`, a property holding the least
restrictive level an object of this kind may carry, read from the object's own
schema or from one declared reference.

A save that sets a label ranking below the floor SHALL be refused, naming the
requested level and the floor. A save that raises the label SHALL be allowed.

#### Scenario: lowering below the type default is refused

- **GIVEN** an object whose type declares a floor of `zaakvertrouwelijk`
- **WHEN** a caller saves it with `openbaar`
- **THEN** the save is refused naming both levels
- @e2e exclude {specs only in this change; task 5.2 covers the refusal}

#### Scenario: raising above the floor is allowed

- **GIVEN** the same object
- **WHEN** a caller saves it with `geheim`
- **THEN** the save succeeds
- @e2e exclude {as above}
