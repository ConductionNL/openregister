# external-recipient-opt-out Specification

## Purpose
Before OpenRegister mails or texts an external address (a flow's send-email step or a parties notification), it asks integriq once whether that address opted out of the message category, skips the refused addresses, and puts integriq's unsubscribe link and headers on every mail it does send.

## Requirements

### Requirement: The send-email flow step asks integriq before it mails an external address (REQ-ERO-001)

Before the `openregister.send-email` step mails an address outside Nextcloud, OpenRegister MUST ask integriq once for all of the step's external addresses, through `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent` named by string and guarded with `class_exists()`. An address whose decision reads `send: false` MUST NOT be mailed. It MUST appear in the step outcome under `optedOut`, or under `authorityUnavailable` when integriq did not answer. A skipped address MUST NOT consume the rate limiter and MUST NOT raise `FlowEmailSentEvent`. This implements ConductionNL/hydra `openspec/changes/opt-out-before-send` REQ-CMO-002 and REQ-CMO-005.

#### Scenario: An opted-out address is skipped

- **GIVEN** integriq holds an instance-wide opt-out for `jan@example.nl`
- **AND** a flow step with `externalRecipients: any` and `messageCategory: service` names `jan@example.nl` and `piet@example.nl`
- **WHEN** the step runs
- **THEN** only `piet@example.nl` is mailed
- **AND** the outcome lists `jan@example.nl` under `optedOut`
- **AND** no `FlowEmailSentEvent` is raised for `jan@example.nl`
- @e2e exclude flow engine path, covered by PHPUnit

#### Scenario: integriq is not installed

- **GIVEN** an instance without integriq
- **WHEN** a flow step mails an external address with `messageCategory: service`
- **THEN** no mail is sent
- **AND** the outcome lists the address under `authorityUnavailable`
- @e2e exclude needs an instance without integriq, covered by PHPUnit

#### Scenario: An exempt step mails without integriq

- **GIVEN** an instance without integriq
- **WHEN** a flow step mails an external address with `messageCategory: besluit`
- **THEN** the mail is sent without an unsubscribe link
- @e2e exclude needs an instance without integriq, covered by PHPUnit

### Requirement: The send-email step declares a message category (REQ-ERO-002)

The `openregister.send-email` step MUST accept an optional `messageCategory` from the fleet list: `besluit`, `statutory`, `account`, `security`, `case-update`, `reminder`, `service`, `marketing`. An absent value MUST read as `service`. Saving a step with any other value MUST be refused with a message that names the field.

#### Scenario: An unknown category is refused at save

- **WHEN** an admin saves a send-email step with `messageCategory: nieuwsbrief`
- **THEN** the save is refused
- **AND** the message names `messageCategory` and lists the allowed values

#### Scenario: An old step keeps working

- **GIVEN** a send-email step saved before this change, with no `messageCategory`
- **WHEN** it runs
- **THEN** it asks integriq with category `service`
- @e2e exclude flow engine path, covered by PHPUnit

### Requirement: A parties notification asks integriq before it mails a party (REQ-ERO-003)

Before `PartyNotificationService` mails a party's address, OpenRegister MUST ask integriq once for all of the rule's party addresses. A party whose decision reads `send: false` MUST NOT be mailed and MUST get the outcome `refused-opted-out`, or `authority-unavailable` when integriq did not answer. A party indicator refusal MUST still come first. An `x-openregister-notifications` rule MUST accept an optional `messageCategory` from the fleet list, and the validator MUST refuse any other value with `notification-bad-message-category`.

#### Scenario: An opted-out party is not mailed

- **GIVEN** a case with two parties, one of whom opted out in integriq
- **WHEN** a `parties` notification rule fires with `messageCategory: case-update`
- **THEN** only the other party is mailed
- **AND** the opted-out party's outcome reads `refused-opted-out`
- @e2e exclude notification dispatcher path, covered by PHPUnit

#### Scenario: A bad category is refused at schema save

- **WHEN** a schema is saved with a notification rule whose `messageCategory` is `nieuwsbrief`
- **THEN** the save is refused with code `notification-bad-message-category`

### Requirement: An external mail carries the unsubscribe link (REQ-ERO-004)

A non-exempt mail to an external address MUST carry integriq's unsubscribe link in its body. `EmailSender` MUST accept optional headers and MUST set `List-Unsubscribe` and `List-Unsubscribe-Post` when the message exposes the underlying mail object. When it does not, it MUST send without the headers and MUST keep the body link. An exempt mail MUST carry neither.

#### Scenario: A service mail carries the link

- **GIVEN** integriq is installed and the address has no opt-out
- **WHEN** a flow step mails it with `messageCategory: service`
- **THEN** the body ends with integriq's unsubscribe line
- @e2e exclude mail rendering, covered by PHPUnit

#### Scenario: A besluit mail carries no link

- **WHEN** a flow step mails an external address with `messageCategory: besluit`
- **THEN** the body has no unsubscribe line and the message has no `List-Unsubscribe` header
- @e2e exclude mail rendering, covered by PHPUnit

### Requirement: OpenRegister owns one shared List-Unsubscribe helper (REQ-ERO-005)

OpenRegister MUST ship a public `UnsubscribeHeaders` service that sets `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` from integriq's unsubscribe material on an `OCP\Mail\IMessage`. It MUST reach the underlying mail object only behind a `method_exists()` guard. When it cannot set the headers, it MUST return false and MUST NOT throw, so the caller still sends with the body link. `EmailSender` MUST use it. It is the one helper dossiq and pipelinq call. Approved by Ruben on 2026-10-05.

#### Scenario: The helper sets both headers

- **GIVEN** a message that exposes the underlying mail object
- **WHEN** a caller applies integriq's material with one-click URL `https://nc.example/u/abc`
- **THEN** the message has `List-Unsubscribe: <https://nc.example/u/abc>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
- @e2e exclude mail header path, covered by PHPUnit

#### Scenario: A mailer without headers still sends

- **GIVEN** a message that does not expose the underlying mail object
- **WHEN** a caller applies the material
- **THEN** the helper returns false and does not throw
- **AND** the caller sends the mail with the body link
- @e2e exclude mail header path, covered by PHPUnit

### Requirement: A party mail carries the rule's message as its body (REQ-ERO-006)

A `parties` notification MUST send the rule's resolved `message` as the mail body. It MUST fall back to the subject only when the rule declares no `message`. This fixes the defect where `dispatchToParties()` passed `body: $subject` to `notifyParties()`.

#### Scenario: The body is the message, not the subject

- **GIVEN** a rule with subject `Your case changed` and message `Open the portal to see the new status`
- **WHEN** the rule fires for a party with an address
- **THEN** the mail's subject is `Your case changed`
- **AND** its body starts with `Open the portal to see the new status`
- @e2e exclude notification dispatcher path, covered by PHPUnit

#### Scenario: A rule without a message keeps today's body

- **GIVEN** a rule with a subject and no message
- **WHEN** the rule fires for a party
- **THEN** the mail's body starts with the subject
- @e2e exclude notification dispatcher path, covered by PHPUnit

### Requirement: An email recipient reads its address through a reference (REQ-ERO-007)

An `x-openregister-notifications` rule MAY address `{kind: "email", field: "<path>"}`. The path's last segment names the address; every segment before it names a reference that OpenRegister MUST follow to the referenced object, at most three references deep. OpenRegister MUST read the referenced object as the system, so the rule reaches the address even when the person whose save fired the rule may not read that object, and MUST refuse a referenced object that belongs to another organisation than the triggering object (outcome `refused-other-tenant`). A value that is not an e-mail address MUST NOT be mailed (`not-an-address`); a missing reference or object MUST be named (`no-reference`, `reference-not-found`). A resolved address MUST be put to integriq like a party address (REQ-ERO-003) and carry the unsubscribe link (REQ-ERO-004). Every entry MUST leave a history row with its outcome, recorded against the path and not the address. The validator MUST refuse an `email` entry without a `field` (`notification-recipient-email-field-missing`), whose first segment is not a declared property (`notification-recipient-field-unknown`), that follows more than three references (`notification-recipient-email-path-too-deep`), or on a rule without the `email` channel (`notification-recipient-email-needs-email-channel`).

#### Scenario: A supplier message mails the supplier's contact address

- **GIVEN** a `supplierMessage` schema with the rule `{kind: email, field: supplierRef.contactEmail}` on the `email` channel
- **AND** a message whose `supplierRef` names a supplier of the same organisation with `contactEmail: info@bouw.example`
- **WHEN** the message is created
- **THEN** `info@bouw.example` gets the rule's subject and message, with integriq's unsubscribe link
- @e2e exclude notification dispatcher path, covered by PHPUnit `ReferencedAddressNotifierTest` and `AnnotationNotificationDispatcherTest::testAnEmailRuleIsSentThroughTheReferencedAddressNotifier`

#### Scenario: A reference into another tenant is refused

- **GIVEN** the same rule and a message whose `supplierRef` names a supplier of another organisation
- **WHEN** the message is created
- **THEN** nothing is mailed and the history row reads `refused-other-tenant`
- @e2e exclude tenant boundary in the dispatcher, covered by PHPUnit `ReferencedAddressNotifierTest::testAnUnresolvableAddressIsNamedAndNothingIsSent`

#### Scenario: An email entry on a rule without the email channel is refused at save

- **WHEN** a schema is saved with an `email` recipient on a rule whose channels are `["nc-notification"]`
- **THEN** the save is refused with code `notification-recipient-email-needs-email-channel`
- @e2e exclude schema validator, covered by PHPUnit `NotificationAnnotationValidatorTest::testAnEmailRecipientNeedsADeclaredFieldAndTheEmailChannel`
