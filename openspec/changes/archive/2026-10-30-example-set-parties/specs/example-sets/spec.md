# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may put parties on its objects

A fragment MAY declare `parties`. Each row has a `key` and the fields `objectUuid`, `register`, `schema`, `partyUuid` and `role`, and optionally `validFrom`, `validUntil`, `note` and `primary`. Placeholders are allowed.

The check SHALL refuse:
- any other field;
- a row without an object, a party or a role;
- a `validFrom` or `validUntil` that is not a date.

Install SHALL link the party to the object in that role through the party model, with the fragment's app as the linker, and SHALL record the link. A later install SHALL report a recorded link that is gone as missing. Remove SHALL unlink the parties the record holds, and only those.

#### Scenario: A representative with a mandate until the end of the year
- **GIVEN** a fragment declares the party J. van Wijk on the Woo case in the role `gemachtigde`, valid until 31 December
- **WHEN** the set is installed
- **THEN** the case's parties SHALL list J. van Wijk under `gemachtigde` with that end date

#### Scenario: Removing the set
- **GIVEN** the set was installed with one party link
- **WHEN** the set is removed
- **THEN** the link SHALL be gone and the report SHALL count one removed party
