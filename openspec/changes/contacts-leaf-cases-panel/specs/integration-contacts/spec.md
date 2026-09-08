# integration-contacts

## ADDED Requirements

### Requirement: The contacts leaf offers a detail surface with a cases panel

The contacts leaf SHALL expose a `detail` surface for one contact that lists
every register object the contact is linked to, grouped by schema, showing
the link role, the object's title and its status when the schema declares
one. The list SHALL contain only objects the current user may read.

#### Scenario: a contact linked to two cases shows both

- **GIVEN** a contact linked to two objects of schema `case` with role `requester`
- **WHEN** the user opens the contact's detail surface
- **THEN** the cases panel lists both objects under the heading of schema `case`, each with role `requester` and its status
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/contacts-leaf-cases-panel.spec.ts when the surface ships}

#### Scenario: an object the user may not read stays out of the panel

- **GIVEN** a contact linked to an object in a register the user has no read scope on
- **WHEN** the user opens the contact's detail surface
- **THEN** that object is absent and the panel does not reveal its count
- @e2e exclude {RBAC filtering is asserted in unit tests on the provider}

### Requirement: The contacts leaf offers a name search

The contacts leaf SHALL expose an `index` surface that finds contacts by part
of a name, an e-mail address or an organisation, querying only the address
books the current user may read, and SHALL open the detail surface from a
result.

#### Scenario: a partial name finds the contact

- **GIVEN** a readable address book holding a contact named "Jansen, Piet"
- **WHEN** the user types "jans" in the name search
- **THEN** the result list shows that contact and opening it shows the detail surface
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/contacts-leaf-cases-panel.spec.ts when the surface ships}
