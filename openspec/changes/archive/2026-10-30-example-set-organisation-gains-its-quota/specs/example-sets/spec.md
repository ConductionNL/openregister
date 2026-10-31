# example-sets Delta: example-set-organisation-gains-its-quota

## ADDED Requirements

### Requirement: An organisation the set made gains the quota it declares later

When the set finds an organisation by its slug, and the record holds that organisation as one the set created, the loader SHALL write each declared quota field (storage, requests, bandwidth, users, objects, objectsSchema) the organisation holds none of. It SHALL NOT change a quota field the organisation holds. An organisation the set did not create SHALL NOT be written. A quota write that fails SHALL be reported as a refusal for that organisation.

#### Scenario: A tenant made before its quota was declared
- **GIVEN** the record holds Waterland as created by the set, and Waterland has no object or user quota
- **WHEN** the set declares `quota { users: 50, objects: 5000, objectsSchema: case }` and is installed again
- **THEN** Waterland holds a user quota of 50, an object quota of 5000 and the object quota schema `case`

#### Scenario: A quota somebody set stays
- **GIVEN** the record holds Waterland as created by the set, and Waterland has a user quota of 80
- **WHEN** the set declares a user quota of 50
- **THEN** Waterland keeps a user quota of 80

#### Scenario: An organisation the set did not make
- **GIVEN** an organisation with the declared slug that the record does not hold as created
- **WHEN** the set is installed
- **THEN** its quota is not written
