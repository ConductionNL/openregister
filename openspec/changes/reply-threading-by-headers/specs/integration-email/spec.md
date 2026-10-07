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

### Requirement: Collected mail files carry their thread headers (REQ-RTH-010)

`EmlParser` SHALL read `Message-ID`, `In-Reply-To` and `References` (header names case-insensitive, angle brackets stripped) into `EmlStructure`. Text extraction of an `.eml` file SHALL store them with the sent date in `openregister_mail_headers`, one row per file, replaced on re-extraction and deleted with the file's chunks.

#### Scenario: a reply's parent is recorded
<!-- @e2e exclude Parser; covered by PHPUnit EmlThreadHeadersTest::testInReplyToAndReferencesAreRead with fixture messages. -->

- **GIVEN** an `.eml` whose `In-Reply-To` is `<a1@gemeente.nl>` and whose `References` is `<a0@gemeente.nl> <a1@gemeente.nl>`
- **WHEN** it is extracted
- **THEN** its header row holds those two ids in that order

### Requirement: A message is read with the messages around it (REQ-RTH-011)

`MailThreadAssembler` SHALL assemble threads over a set of `.eml` files by `In-Reply-To` first and `References` from its last entry, and SHALL never use the subject. A parent outside the set SHALL appear as a gap naming its `Message-ID`. `GET /api/files/{fileId}/thread` SHALL return `{thread: [{fileId?, messageId, parent, sentAt, from, subject, gap}], position}` in date order, assembled over the files of the asked file's object (or its folder when it has no object), including only files the caller may read; an unreadable file SHALL appear only as a gap without its headers. The files sidebar of an `.eml` file SHALL show the thread with the asked message marked, each other message opening on click.

#### Scenario: a reviewer opens a reply and sees the conversation
- **GIVEN** five collected mails on one object forming one conversation, where the third answers the first and the subject of the fourth was edited
- **WHEN** a reviewer opens the fourth mail's sidebar
- **THEN** the "Conversation" section lists all five in date order with the fourth marked, and the fourth sits under the message it answers

#### Scenario: a missing parent is shown, not closed up
<!-- @e2e exclude Covered by PHPUnit MailThreadAssemblerTest::testAParentOutsideTheSetIsAGap. -->

- **GIVEN** a reply whose parent was never collected
- **WHEN** its thread is assembled
- **THEN** the thread holds a gap naming the parent's `Message-ID`

#### Scenario: an unreadable message does not leak its headers
<!-- @e2e exclude Covered by PHPUnit MailThreadEndpointTest::testAnUnreadableMemberIsAGapWithoutHeaders. -->

- **GIVEN** a thread where one message sits on an object the caller may not read
- **WHEN** the caller asks for the thread
- **THEN** that message is a gap without sender, subject or date

#### Scenario: the same subject does not join two conversations
<!-- @e2e exclude Covered by PHPUnit MailThreadAssemblerTest::testEqualSubjectsWithoutHeadersStayApart. -->

- **GIVEN** two unrelated mails titled `Re: uw verzoek` with no shared headers
- **WHEN** threads are assembled
- **THEN** they are in two threads
