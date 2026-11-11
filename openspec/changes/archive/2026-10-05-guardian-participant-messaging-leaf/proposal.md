---
kind: code
depends_on: []
---

## Why

Learniq's round-1 competitor findings (`9.3` direct messages teacher-to-parent, `9.15` teacher inbox with per-group audiences) both name the same root cause: `CohortTalkMembershipHandler` (learniq) syncs a class's Talk conversation membership from Nextcloud user accounts only — learners and staff. A guardian has no Nextcloud account in the fleet's model (per `po-research-2026-09-25.md`, guardians are resolved through a portal contribution, not an account), so no app can put a guardian into a live, two-way Talk conversation today; `tier-b-and-sibling.md` names this the enabling primitive both rows are blocked on ("a messaging/Talk leaf that accepts guardians, not only staff/learners, as participants... lets 9.3/9.15 resolve as a leaf instead of a bespoke portaliq build").

OpenRegister already ships the read/link half of this (`integration-talk`, status done: `TalkProvider` lists rooms linked to an object; `TalkLinkService`/`TalkLinksController`, the Tier-2 successor, link/create/unlink rooms). Neither manages *participants*. This change adds the missing half: a platform primitive that invites a participant identified only by an email address (never assuming a Nextcloud account exists), scoped to schemas that opt in, so a sibling app resolves the email (learniq's own `PortalContributionProvider`/guardian-audience data — not this change's concern) and this leaf does the one thing OpenRegister already knows how to do safely: call Talk's own participant API.

## What Changes

- **Add `TalkLinkService::inviteExternalParticipant(objectUuid, roomToken, email, ?displayName)`**, reusing the exact `ParticipantService::addUsers()` call `createAndLinkRoom()` already makes for Nextcloud users (`actorType: 'users'`), with `actorType: 'emails'` instead — no second Talk API surface, no new dependency. Talk's own participant/room ACLs continue to govern message visibility once invited (per `integration-talk`'s existing "Talk's own room ACLs govern visibility transitively" principle) — this change only adds who may be *added*, nothing about read/write scoping once added.
- **Add the `x-openregister-talk-participants` schema-configuration key** (boolean, default `false`/absent): a schema opts its objects' linked Talk rooms into external-participant invites. Registered in `Schema::ANNOTATION_VOCABULARY` (required — see design.md; an unlisted `x-openregister-*` key is silently dropped by `setConfiguration()`, the exact failure mode documented against six other annotations in that file).
- **Refuse, don't degrade, on a caller-input problem**: an unlinked room (404), a non-opted-in schema (403), or a malformed email (400) all throw — these are the caller's mistake to fix. Only Talk's own unavailability (app not installed, API surface missing on this Talk version) degrades to `{invited: false, unavailable: true, cause}` (AD-23), matching every other Talk-adjacent primitive in this codebase.
- **Existing behaviour for staff and learners is untouched.** Ordinary Nextcloud accounts continue to join a linked room exactly as today — through Talk's own UI, or via `TalkLinkService::linkRoom()`/`createAndLinkRoom()`'s existing `actorType: 'users'` invite. This change adds a second, narrower door (email-only, schema-gated) rather than replacing the first.
- Not a breaking change: no existing schema declares `x-openregister-talk-participants`, so no existing write path or Talk room is affected.

## Capabilities

### New Capabilities
- `guardian-participant-messaging-leaf`: a platform primitive letting a schema-gated Talk room accept a participant identified only by email, not a Nextcloud account, so a sibling app can put a guardian (or any other accountless party) into a live conversation without building its own Talk integration.

### Modified Capabilities
(none — `integration-talk`'s existing requirements, about listing/rendering linked rooms, are unchanged; this change adds a capability alongside it rather than editing its contract)

## Impact

- **Code**: `lib/Service/TalkLinkService.php` (+`inviteExternalParticipant()`, +`schemaAllowsExternalParticipants()`, +`SchemaMapper` constructor dependency), `lib/Db/Schema.php` (+1 entry in `ANNOTATION_VOCABULARY`), `lib/AppInfo/Application.php` (`TalkLinkService` factory gains the new dependency).
- **Tests**: `tests/Unit/Service/TalkLinkServiceTest.php` (extended: link-not-found, schema-not-opted-in, malformed-email, no-user, and the Talk-unavailable degrade path — a full Talk round-trip is out of unit-test reach in this environment, same as the file's existing `@group requires-app-spreed` tests).
- **Consumers**: learniq's `guardian-direct-messages`/`teacher-inbox-per-group` (portaliq) and `portal-contribution-guardian-audiences` (learniq) rows can resolve `9.3`/`9.15` as a leaf against this primitive instead of a bespoke Talk integration; the guardian's email itself is resolved by the caller (e.g. learniq's own guardian-audience data), never by this change.
- **Backward compatibility**: additive; no route, schema, or existing method signature (beyond the new constructor parameter, which is DI-autowired everywhere it is constructed — see design.md) changes behaviour for an existing caller.
