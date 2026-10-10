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

### Requirement: A rule may mail an address it reads through a reference (REQ-ERO-007)

An `x-openregister-notifications` rule MUST accept the recipient `{kind: email, field: <path>}`. A path of one segment names a property of the object that holds the address. A longer path reads its first segment as a reference (a uuid, a URL or path ending in one, or an object carrying `id` or `uuid`), loads that object as the system, and reads the rest of the path inside it: `supplierRef.contactEmail` mails the `contactEmail` of the supplier the object points at. The referenced object MUST be inside the firing object's tenant (`ReferenceTenantGuard`, the boundary calculations use); a reference outside it MUST NOT be followed and gets the outcome `reference-unresolved`. A value that is not a valid address gets `no-address`. Every address MUST be put to integriq before it is mailed, exactly as a party's address is (REQ-ERO-003), and the mail carries the rule's message as its body (REQ-ERO-006). The rule mails only when its channels include `email`. The validator MUST refuse a path whose first segment is not a property of the schema, with `notification-recipient-field-unknown`.

#### Scenario: A supplier's contact address is mailed through the reference

- **GIVEN** a schema with a property `supplierRef` and a rule with recipient `{kind: email, field: supplierRef.contactEmail}` on channel `email`
- **AND** an object whose `supplierRef` names a supplier in the same organisation with `contactEmail: inkoop@leverancier.nl`
- **WHEN** the rule fires
- **THEN** integriq is asked about `inkoop@leverancier.nl`, and on `send: true` that address is mailed
- @e2e exclude notification dispatcher path, covered by PHPUnit (ReferencedAddressNotifierTest)

#### Scenario: A reference into another organisation is not followed

- **GIVEN** the same rule, and a `supplierRef` that names an object of another organisation
- **WHEN** the rule fires
- **THEN** nothing is mailed and the history row reads `reference-unresolved`
- @e2e exclude notification dispatcher path, covered by PHPUnit (ReferencedAddressNotifierTest)

#### Scenario: A path that starts outside the schema is refused at save

- **WHEN** a schema without an `ownerRef` property is saved with recipient `{kind: email, field: ownerRef.contactEmail}`
- **THEN** the save is refused with code `notification-recipient-field-unknown`
- @e2e exclude schema-save validation, covered by PHPUnit
