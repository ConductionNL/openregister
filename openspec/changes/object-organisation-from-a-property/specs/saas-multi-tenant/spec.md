---
status: proposed
---

# saas-multi-tenant

## ADDED Requirements

### Requirement: A schema can derive an object's organisation from a property (REQ-OOP-001)

`x-openregister-organisation` with `fromProperty` SHALL be an accepted schema annotation; import SHALL refuse it when the property is not declared or is neither a string nor a reference. For an object of such a schema, every create and update SHALL set `@self.organisation` to the `Organisation` the property names (a uuid, or a reference's `uuid` or `id`). A client-supplied `@self.organisation` that differs SHALL be refused with 422. A value naming no organisation SHALL be refused with 422. A caller who is neither an administrator nor a member of that organisation SHALL be refused with 403, with no fallback to the caller's active organisation. An empty property SHALL receive the organisation the save would stamp today, so the property and `@self.organisation` are equal after every save.

#### Scenario: naming the unit is what scopes the rights
- **GIVEN** the publication schema declares `fromProperty: "organization"` and an officer who is a member of both `Gemeente Voorbeeld` and `Omgevingsdienst Voorbeeld`, with `Gemeente Voorbeeld` active
- **WHEN** the officer creates a publication with `organization` set to `Omgevingsdienst Voorbeeld`
- **THEN** the publication's `@self.organisation` is `Omgevingsdienst Voorbeeld`
- **AND** a user who is only a member of `Gemeente Voorbeeld` does not see it in the list

#### Scenario: a non-member cannot plant an object in another unit
<!-- @e2e exclude Covered by PHPUnit OrganisationFromPropertyTest::testANonMemberIsRefusedWithoutFallback through SaveObject. -->

- **GIVEN** a caller who is not a member of `Omgevingsdienst Voorbeeld`
- **WHEN** they save an object whose property names it
- **THEN** the save is refused with 403 and nothing is stored

#### Scenario: a contradicting @self.organisation is refused
<!-- @e2e exclude Covered by PHPUnit OrganisationFromPropertyTest::testAContradictingSelfOrganisationIsRefused. -->

- **GIVEN** a body whose property names unit A and whose `@self.organisation` names unit B
- **WHEN** it is saved
- **THEN** the response is 422 naming both values

#### Scenario: an empty property is filled
<!-- @e2e exclude Covered by PHPUnit OrganisationFromPropertyTest::testAnEmptyPropertyIsFilledWithTheStampedOrganisation. -->

- **GIVEN** a body with no value for the property
- **WHEN** it is saved by a member of the active organisation
- **THEN** the property and `@self.organisation` both hold the active organisation

### Requirement: Existing disagreements are reported and reconciled (REQ-OOP-002)

`occ openregister:organisation:reconcile` SHALL list every object of the given schema whose property and `@self.organisation` disagree, and SHALL change nothing without `--apply`. With `--apply` it SHALL set `@self.organisation` from the property and write one audit row per changed object with cause `migration`.

#### Scenario: a dry run changes nothing
<!-- @e2e exclude occ command; covered by PHPUnit ReconcileOrganisationCommandTest::testADryRunChangesNothing. -->

- **GIVEN** three publications whose property and organisation disagree
- **WHEN** the command runs without `--apply`
- **THEN** it lists the three and no object changes
