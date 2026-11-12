## ADDED Requirements

### Requirement: A fragment may link mail to its objects

A fragment MAY declare `emailLinks`. Each row SHALL name `object`, `subject` and either `messageId` or both `mailAccountId` and `mailMessageId`. It MAY give `sender`, `mailDate` and `linkedBy`; `linkedBy` SHALL be an account the set creates or adopts. On install the set SHALL write one email link per row, on the object's register and schema, marked with the source `example-set:<app>/<key>`. A second install SHALL write nothing new. Remove SHALL delete the link.

#### Scenario: the subsidy mail on Buurtvereniging Oost
- **GIVEN** a row on Buurtvereniging Oost: "Subsidieformulier en de lijst met documenten gestuurd.", from Fatma Yildiz's address, 6 okt 16:05, linked by Fatma
- **WHEN** the set is installed
- **THEN** the organisation's linked emails list it, and pipelinq's activity shows it as "e-mail, gekoppeld"
