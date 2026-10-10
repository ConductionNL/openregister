---
kind: code
depends_on: []
---

## Why

Hermiq answers Nextcloud Assistant tasks (text2text, summary, headline, contextagent interaction)
on Claude through the credential broker. Nextcloud runs those tasks from cron, in
`SynchronousBackgroundJob`, with no user session. An admin who stores one Anthropic API key as an
**organisation** credential, so every member can use it, then finds that every Assistant task fails:
on the sessionless path `request()` passes `actingOrganisationId: null`, and the organisation guard
admits a sessionless caller only on a matching organisation assertion. The task's user is known
(Nextcloud hands it to the provider), and hermiq already forwards it as `actingUserId`, but the
organisation guard never looks at it.

The two existing answers are both wrong for this case. Letting `request()` accept an asserted
organisation would let any in-process caller spend any organisation's key without naming a person.
Storing the key as a personal credential works for its owner only.

Found by the hermiq Claude provider lane of the pipelinq review (round 5), before hermiq goes to the
App Store.

## What changes

- The organisation guard gains one sessionless admit path: an in-process caller that asserts an
  `actingUserId` is admitted to an organisation credential only when that user exists, is enabled,
  and is a REAL member of the credential's organisation (`OrganisationService::isMemberOfOrganisation()`).
  A Nextcloud administrator gets no blanket pass on this path (Ruben, 2026-10-10); the session path
  keeps admitting administrators.
- `OrganisationService::userHasAccessToOrganisation(organisationUuid, userId)` is the session rule
  for a named user (member or administrator). `hasAccessToOrganisation()` delegates to it, so the
  two cannot drift. The background path does not use it.
- The organisation-credential form can show and choose its organisation (Ruben, 2026-10-10):
  `GET /api/credentials/organisations` lists what the caller may manage with the active one
  flagged, and `GET /api/credentials?scope=organisation&organisation=<uuid>` lists a chosen
  organisation after checking the caller's access. Create already re-checks
  `isOrganisationAdmin()` for the organisation the client names; that stays the authority.
- Nothing changes for a session caller: the session stays authoritative and an asserted user is
  ignored. Nothing changes on the HTTP path: both broker endpoints still require a session and still
  never forward an acting user. A personal credential still admits only its owner. The provider
  allow-rules and host-lock are untouched.

## Out of scope

- An "instance" credential scope. Credentials stay `personal` or `organisation`.
- Letting `request()` accept an asserted organisation (openregister#450 keeps that on the
  non-routed `resolveInjectable()` only).
- Hermiq's own changes (it forwards the task user and lists organisation credentials in its
  settings dialog); those ship in the hermiq repo.

## Impact

- `lib/Service/Credential/CredentialBrokerService.php` (organisation guard)
- `lib/Service/OrganisationService.php` (three new public methods, one delegation)
- `lib/Controller/CredentialController.php`, `appinfo/routes.php` (organisation picker endpoint, chosen-organisation listing)
- `tests/Unit/Controller/CredentialControllerOrganisationTest.php`
- `tests/Unit/Service/Credential/CredentialBrokerActsForOrganisationMemberTest.php`
- `tests/Unit/Service/OrganisationServiceGapTest.php` (membership for a named user)
