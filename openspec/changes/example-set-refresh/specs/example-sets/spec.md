# example-sets Delta: example-set-refresh

## ADDED Requirements

### Requirement: A refresh rewrites every object the set declares

`example-set:install --refresh` SHALL rewrite every object the set's record holds to its declared values resolved
today (dates against the install day, cast, lookups, objects). Declared fields SHALL replace the stored values; fields
the declaration does not name SHALL stay. Objects the set did not write SHALL NOT be changed, and nothing SHALL be
deleted. The report SHALL count the refreshed objects per type, and the proof SHALL check them. Without `--refresh`
the install SHALL keep a held object as before.

#### Scenario: A date counted from the install day

- **GIVEN** a held case with `start` `{{date:-2}}`, installed on 13 October
- **WHEN** the set is installed with `--refresh` on 18 October
- **THEN** its `start` is 16 October, and a field the declaration does not name keeps its value

#### Scenario: Not the set's object

- **GIVEN** a case type the set looks up but did not write
- **WHEN** the set is installed with `--refresh`
- **THEN** the case type is unchanged
