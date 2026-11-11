# example-sets Delta

## MODIFIED Requirements

### Requirement: A fragment may put parties on its objects

A fragment MAY declare `parties`. Each row has a `key` and the fields `objectUuid`, `register`, `schema`, `partyUuid` and `role`, and optionally `validFrom`, `validUntil`, `note` and `primary`. Placeholders are allowed.

The check SHALL refuse:
- any other field;
- a row without an object, a party or a role;
- a `validFrom` or `validUntil` that is not a date.

Install SHALL link the party to the object in that role through the party model, with the fragment's app as the linker, and SHALL record the link. It SHALL find the object and the party without RBAC, as every other example-set read does, and without elevating to the system principal, so an install without a signed-in user (`occ`) links a party on an object whose schema refuses anonymous reads. A later install SHALL report a recorded link that is gone as missing. Remove SHALL unlink the parties the record holds, and only those.

#### Scenario: An occ install on a case anonymous users may not read
- **GIVEN** a fragment declares a party on a case whose schema refuses "Anonymous"
- **WHEN** the set is installed through `occ`
- **THEN** the party SHALL be linked and not reported as "no object to put the party on"

## ADDED Requirements

### Requirement: A value the store keeps in another form is kept

The install's read-back, and the remove's check that a change is still in place, SHALL count a value as kept when the store holds the same number, the same moment in another offset, or null for an empty text.

#### Scenario: A moment stored in UTC
- **GIVEN** a change sets `agendaPublishedAt` to `2026-09-15T09:00:00+02:00` and the store keeps `2026-09-15T07:00:00+00:00`
- **WHEN** the set is installed
- **THEN** the report SHALL NOT list the field as written but not kept
