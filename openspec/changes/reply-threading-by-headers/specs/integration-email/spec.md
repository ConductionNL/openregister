# integration-email

## ADDED Requirements

### Requirement: Every linked and every sent message records its RFC Message-ID

An email link SHALL carry `rfcMessageId`, read from the message headers at
link time, and `direction` (`inbound` or `outbound`). An e-mail sent by the
dispatch leaf bound to an object SHALL create an outbound link with the
minted `Message-ID`. A one-shot job SHALL backfill `rfcMessageId` on
existing links.

#### Scenario: a sent mail is part of the thread

- **GIVEN** an object and an e-mail sent from it through the dispatch leaf
- **WHEN** the object's e-mails are listed
- **THEN** the list holds the sent message with `direction` `outbound` and its `Message-ID`
- @e2e exclude {backend link write, covered by unit tests with a mail sink}

### Requirement: A reply resolves to its object by headers before anything else

`POST /api/emails/resolve` SHALL accept `messageId`, `inReplyTo` and
`references`, SHALL match `inReplyTo` first and then `references` newest
first against `rfcMessageId`, SHALL return the matching objects with the
header that matched, and SHALL return an empty list when nothing matches. A
user SHALL receive only objects they may read; a granted system caller
SHALL resolve across RBAC and the response SHALL say `scope: system`.

#### Scenario: an edited subject still threads

- **GIVEN** an outbound link with `Message-ID` `<a1@x>` on case C
- **WHEN** resolve is called with `inReplyTo: "<a1@x>"` and a subject without any tag
- **THEN** case C is returned with `matchedBy` `inReplyTo`
- @e2e exclude {proposal only; the integriq intake change adds the end-to-end mail test}

#### Scenario: a forwarded thread lands on the newest object

- **GIVEN** links `<a1@x>` on case C1 and `<a2@x>` on case C2, where `<a2@x>` is newer
- **WHEN** resolve is called with `references: ["<a1@x>", "<a2@x>"]` and no `inReplyTo`
- **THEN** C2 is returned first
- @e2e exclude {ordering, covered by controller unit tests}

#### Scenario: nothing matches, nothing breaks

- **GIVEN** headers that match no link
- **WHEN** resolve is called
- **THEN** the response is 200 with an empty list
- @e2e exclude {fallthrough, covered by unit tests}
