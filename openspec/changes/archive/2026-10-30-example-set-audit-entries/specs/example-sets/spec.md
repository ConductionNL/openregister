## ADDED Requirements

### Requirement: A fragment may give its objects a history by named people

A fragment MAY declare `auditEntries`. Each row SHALL name `object`, `action`, `user` and `at`, and MAY give `changed`. The user SHALL be an account the set creates or adopts. On install the set SHALL write one audit trail entry per row, by that user, at that moment, sealed into the hash chain, with the cause `import` and the run `example-set:<app>/<key>`. A second install SHALL find the entry by its run and write nothing. Remove SHALL leave the entries.

#### Scenario: a person looked at a document
- **GIVEN** a row on case e0b306d2: `file.downloaded` by Fatma Kaya on 6 okt, `changed.data.fileName` "medisch verslag"
- **WHEN** the set is installed
- **THEN** the case's history shows "Inzage medisch verslag", by Fatma Kaya, on 6 okt
