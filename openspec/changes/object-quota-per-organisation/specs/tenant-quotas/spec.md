# tenant-quotas

## ADDED Requirements

### Requirement: A schema MAY cap how many of its objects one organisation holds (REQ-OQP-001)

A schema MAY declare `x-openregister-quota` with a `perOrganisation` positive
integer. The annotation SHALL survive a schema save. Any other value
(absent, zero, negative, a string, a float) SHALL mean no quota.

#### Scenario: the annotation survives a save

- **WHEN** a schema is saved with `x-openregister-quota: {"perOrganisation": 4}`
- **THEN** reading the schema back returns the annotation unchanged
- @e2e exclude {schema configuration allow-list, covered by ObjectQuotaListenerTest}

#### Scenario: an invalid cap is no quota

- **GIVEN** a schema whose `perOrganisation` is 0, negative, a string or a float
- **WHEN** an object is created
- **THEN** no count is made and the create is not refused by a quota
- @e2e exclude {annotation parsing, covered by ObjectQuotaListenerTest}

### Requirement: A create past an organisation's cap is refused (REQ-OQP-002)

When a schema declares a cap and the new object belongs to an organisation,
a create SHALL be refused with code `object-quota-exceeded`, the count and
the cap, when the organisation already holds at least the cap of that
register and schema. The count SHALL be the organisation's real total,
counted without the creating user's RBAC or organisation filter. An update
SHALL never be refused by a quota, and an object in no organisation SHALL
not be counted.

#### Scenario: a create at the cap is refused

- **GIVEN** a schema with `perOrganisation: 2` and an organisation that holds 2 of its objects
- **WHEN** a user of that organisation creates a third
- **THEN** the create is refused with `object-quota-exceeded`, count 2 and limit 2
- @e2e exclude {listener on the create pipeline, covered by ObjectQuotaListenerTest}

#### Scenario: rows the creator cannot read still count

- **GIVEN** a creator who may read only their own objects of that schema
- **WHEN** they create an object
- **THEN** the count includes every object of their organisation in that register and schema
- @e2e exclude {count query shape, covered by ObjectQuotaListenerTest}

#### Scenario: an update is never refused

- **GIVEN** an organisation at its cap
- **WHEN** one of its objects is updated
- **THEN** no count is made and the update is not refused by a quota
- @e2e exclude {listener event filter, covered by ObjectQuotaListenerTest}

### Requirement: An app can read an organisation's quota status (REQ-OQP-003)

`ObjectQuotaService::status(register, schema, organisation)` SHALL return
the organisation's count, the cap (null when none) and whether the next
create would be refused.

#### Scenario: status at the cap

- **GIVEN** a schema with `perOrganisation: 3` and an organisation holding 3
- **WHEN** an app asks for the status
- **THEN** it reads `{"count": 3, "limit": 3, "atLimit": true}`
- @e2e exclude {service read, covered by ObjectQuotaListenerTest}
