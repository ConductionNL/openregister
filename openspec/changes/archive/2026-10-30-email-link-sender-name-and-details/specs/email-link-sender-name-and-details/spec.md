## ADDED Requirements

### Requirement: An email link carries the sender's display name

An email link SHALL store the sender's display name beside the address: taken from Mail's From recipient label when a Mail message is linked, or written by an example set, and returned as `senderName`.

#### Scenario: linking a Mail message
- GIVEN a Mail message from "Sanne de Vries" <sanne.devries@zuiddrecht.example>
- WHEN it is linked to a case
- THEN the link has sender `sanne.devries@zuiddrecht.example` and senderName `Sanne de Vries`

### Requirement: An email link carries the details the row shows

An email link SHALL return `details` with `direction`, `recipientName`, `attachmentCount`, `linkedVia`, `archivedAs` and `mailboxName`, read from its metadata and null when absent. An example set MAY write them; a direction other than inbound/outbound, a linkedVia other than manual/mailbox/file or an attachment count below 0 SHALL be refused at check time.

#### Scenario: the Lindelaan mail
- GIVEN an example-set link with direction inbound, attachmentCount 1, linkedVia mailbox and archivedAs pdf
- THEN its JSON details carry those values and its metadata keeps the set's source
