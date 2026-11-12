## Purpose

Lets a schema-gated Talk room accept a participant identified only by an email address, not a Nextcloud account, so a sibling app (a guardian portal, a partner organisation) can put an accountless party into a live, two-way conversation without building its own Talk integration.

## ADDED Requirements

### Requirement: A schema MAY opt into external Talk participants via `x-openregister-talk-participants`
A schema's configuration MUST be allowed to carry `x-openregister-talk-participants: true`. When absent or `false` (the default), no external-participant invite MUST be possible for that schema's objects, regardless of whether a Talk room is linked. The key MUST be declared in the schema-configuration annotation vocabulary so it round-trips through a schema save rather than being silently dropped.

#### Scenario: A schema declares the opt-in
- **WHEN** a schema's configuration is saved with `x-openregister-talk-participants: true`
- **THEN** the schema save MUST succeed and a subsequent read of the schema MUST return the key with value `true`

#### Scenario: A schema without the opt-in refuses an invite
- **GIVEN** a schema whose configuration does not carry `x-openregister-talk-participants`
- **AND** one of its objects has a linked Talk room
- **WHEN** an external-participant invite is attempted against that room
- **THEN** the system MUST refuse with HTTP 403

### Requirement: An external participant is invited by email to a room already linked to an object
Given a Talk room already linked to an object (via the existing `integration-talk` link mechanism) whose schema opts in, the system MUST be able to add a participant identified only by an email address — no Nextcloud account MUST be required to exist for that email.

#### Scenario: Inviting a guardian by email succeeds
- **GIVEN** object `learner-record-7`'s schema declares `x-openregister-talk-participants: true`
- **AND** a Talk room with token `room-abc` is linked to `learner-record-7`
- **WHEN** an invite is submitted for `guardian@example.test` with display name `"Jan de Vries"`
- **THEN** the system MUST add a participant to room `room-abc` identified by the email, not by a Nextcloud user id
- **AND** the invited party MUST be able to read and send messages in that room without holding a Nextcloud account

#### Scenario: An unlinked room is refused
- **GIVEN** object `learner-record-7` has no Talk room linked with token `room-xyz`
- **WHEN** an invite is submitted for room `room-xyz`
- **THEN** the system MUST refuse with HTTP 404 and MUST NOT contact Talk's participant API

#### Scenario: A malformed email is refused before any Talk call
- **GIVEN** a schema that opts in and a room already linked to its object
- **WHEN** an invite is submitted with email `"not-an-email"`
- **THEN** the system MUST refuse with HTTP 400 and MUST NOT contact Talk's participant API

### Requirement: Talk's own unavailability degrades rather than refuses
When Talk (the `spreed` app) is not installed, or the specific participant-invite API surface is not present on the running Talk version, the system MUST return a degraded descriptor (`{invited: false, unavailable: true, cause}`) rather than throwing — consistent with `integration-talk`'s existing "list" behaviour degrading to an empty array under the same conditions. This applies only after the caller-input checks (opt-in, room-linked, valid email) have already passed; those remain hard refusals.

#### Scenario: Talk not installed degrades rather than errors
- **GIVEN** a schema that opts in, a linked room, and a valid email
- **AND** the `spreed` app is not installed
- **WHEN** an invite is submitted
- **THEN** the system MUST return `{invited: false, unavailable: true, cause: "talk-not-available"}` and MUST NOT raise an exception

### Requirement: Existing staff and learner participation is unaffected
Adding the external-participant invite path MUST NOT change how an ordinary Nextcloud account joins a Talk room — through Talk's own UI, or through the existing room link/create flow's own participant invite.

#### Scenario: A staff member is still added the existing way
- **GIVEN** a schema that has NOT opted into `x-openregister-talk-participants`
- **WHEN** a new Talk room is created and linked to one of its objects via the existing create-and-link flow
- **THEN** the acting Nextcloud user MUST still be added as a participant exactly as before this change
