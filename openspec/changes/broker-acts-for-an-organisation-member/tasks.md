# Tasks: broker-acts-for-an-organisation-member

## 1. Membership for a named user
- [x] 1.1 Add `OrganisationService::userHasAccessToOrganisation(organisationUuid, userId)`: a Nextcloud admin, or a member of the organisation; false for an empty id or an unknown organisation
- [x] 1.2 `hasAccessToOrganisation()` delegates to it for the session user

## 2. Organisation guard
- [x] 2.1 On the sessionless path, admit an organisation credential when the asserted `actingUserId` names an existing, enabled user who passes `userHasAccessToOrganisation()` for the credential's organisation
- [x] 2.2 Keep the matching `actingOrganisationId` admit path, the session path and the personal owner guard unchanged
- [x] 2.3 Fail closed when no user manager is wired

## 3. Tests
- [x] 3.1 Sessionless member admitted through `request()` and the secret read from the organisation vault (fails on the old code)
- [x] 3.2 Sessionless non-member, unknown user, disabled user and empty user denied before any secret read
- [x] 3.3 Session caller: an asserted member never rescues a non-member session
- [x] 3.4 Personal credential: an asserted user who is not the owner is denied
- [x] 3.5 HTTP path: an unauthenticated call never reaches the broker, and an `actingUserId` body field is never forwarded
