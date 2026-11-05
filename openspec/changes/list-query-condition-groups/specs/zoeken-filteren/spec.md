# zoeken-filteren

## ADDED Requirements

### Requirement: A list query takes a condition group

The list API SHALL accept a reserved parameter `_where` holding a condition
group: `{"type": "AND" | "OR", "children": [...]}`, where each child is a
group or a leaf `{"field", "operator", "value"}`. A leaf SHALL mean exactly
what the flat filter on the same property and operator means. The group
SHALL be ANDed with the flat filters, RBAC and organisation scoping, and
paging, the total, the count and facets SHALL be computed on the same
condition. PHP callers SHALL pass the same tree through
`searchObjectsPaginated()`, `countSearchObjects()` and `findAll()`.

#### Scenario: one property, or one of two others

- **GIVEN** clients with `place` "Zuiddrecht" and `industry` "horeca" (4), "detailhandel" (3) or "bouw" (5), and 6 horeca clients in another place
- **WHEN** a caller lists them with `_where` = AND(place eq Zuiddrecht, OR(industry in [horeca], industry in [detailhandel]))
- **THEN** the 7 Zuiddrecht clients in horeca or detailhandel are returned
- **AND** the total is 7
- @e2e exclude {specified only; task 2.1 adds the Newman case}

#### Scenario: a group cannot widen what the caller may see

- **GIVEN** a user whose organisation scope admits only organisation A
- **WHEN** the user lists with `_where` = OR(place eq Zuiddrecht, place isnull true)
- **THEN** only objects of organisation A are returned
- @e2e exclude {specified only; covered by the RBAC case of task 1.3}

#### Scenario: the count and a facet agree with the list

- **GIVEN** the tree of the first scenario
- **WHEN** the caller asks the count and the `industry` facet with the same `_where`
- **THEN** the count is 7 and the facet holds horeca 4 and detailhandel 3
- @e2e exclude {specified only; covered by task 1.4}

### Requirement: A condition group OpenRegister cannot answer is refused

The list API SHALL answer 400 with the error and the path of the failing node
when `_where` is not a valid group, a group is empty, a type is not `AND` or
`OR`, a leaf names an undeclared or encrypted property, an operator is
unknown, the tree is deeper than 5 groups or holds more than 200 leaves, or
the schema's search backend does not support condition groups. It SHALL NOT
return an empty list or an unfiltered list in those cases.

#### Scenario: an unknown property

- **WHEN** a caller sends `_where` = AND(place eq Zuiddrecht, OR(branche eq horeca)) and the schema declares no `branche`
- **THEN** the answer is 400 with path `$.children[1].children[0]`
- @e2e exclude {specified only; covered by task 1.5}

#### Scenario: a backend without support

- **GIVEN** a schema searched through Solr
- **WHEN** a caller sends any `_where`
- **THEN** the answer is 400 saying the backend does not support condition groups
- @e2e exclude {specified only; covered by task 1.5}
