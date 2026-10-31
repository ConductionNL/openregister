# generic-integrations

## ADDED Requirements

### Requirement: A consuming app reads an object's linked emails through a published contract

OpenRegister SHALL publish `EmailLinkReaderInterface`. Its `getLinkedEmails(objectUuid, cursor, limit)` SHALL return the emails linked to the object, newest mail first, as `items`, `total` and `nextCursor`. The container SHALL answer the interface with the email link service. The read applies no object rights; the caller SHALL check access to the object before it reads.

#### Scenario: a client's linked mail in pipelinq
- **GIVEN** a mail "Subsidieformulier en de lijst met documenten" linked to Buurtvereniging Oost
- **WHEN** pipelinq asks the reader for that client's linked emails
- **THEN** it gets the mail with its subject, sender and mail date
