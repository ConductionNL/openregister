# notificatie-engine

## ADDED Requirements

### Requirement: A webhook rule may take its URL from the object (REQ-NRG-020)

A non-persistent `webhook` channel MAY name `urlField` instead of a fixed `url`.
When the rule fires, the system SHALL post to the URL held in that property of the
object that fired, under the same checks as a fixed URL. When that property is
absent, empty, not a string or not a valid http or https URL, the system SHALL send
nothing, SHALL log the reason and SHALL record the delivery as failed. The
validator SHALL accept `urlField` when it is a property name and SHALL refuse it on
a persistent webhook.

#### Scenario: The outcome goes to the subscriber's own callback

- **GIVEN** a rule with `webhook.urlField: outcomeCallbackUrl`
- **WHEN** it fires on an object whose `outcomeCallbackUrl` is `https://example.org/cb/1`
- **THEN** the POST SHALL go to `https://example.org/cb/1`

#### Scenario: An object without a callback gets no call

- **GIVEN** the same rule
- **WHEN** it fires on an object whose `outcomeCallbackUrl` is empty or malformed
- **THEN** nothing SHALL be sent
- **AND** the reason SHALL be logged
