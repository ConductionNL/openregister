## ADDED Requirements

### Requirement: A fragment may give its objects timeline entries by named people

A fragment MAY declare `timelineEntries`. Each row SHALL name `object`, `kind`, `message`, `author` and `at`, and MAY give `fields` and `visibility`. The author SHALL be an account the set creates or adopts, and the kind SHALL be declared. On install the set SHALL write one entry per row, by that author, at that moment, with fields its kind accepts, and marked with the source `example-set:<app>/<key>`. A second install SHALL find it by that source and write nothing. Remove SHALL delete it.

#### Scenario: the case history
- **GIVEN** a row on case 710d44a4: kind status, "Zaak opgepakt", by Pieter Jansen, 4 okt 09:10
- **WHEN** the set is installed
- **THEN** the case's history lists it by Pieter on 4 okt, under its kind's chip
