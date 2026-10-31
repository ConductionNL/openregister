# Tasks: broker-acts-for-an-organisation-member

## 1. Membership for a named user
- [x] 1.1 Add `OrganisationService::userHasAccessToOrganisation(organisationUuid, userId)`: a Nextcloud admin, or a member of the organisation; false for an empty id or an unknown organisation
- [x] 1.2 `hasAccessToOrganisation()` delegates to it for the session user

## 2. Organisation guard
- [x] 2.1 On the sessionless path, admit an organisation credential when the asserted `actingUserId` names an existing, enabled user who is a real member (`isMemberOfOrganisation()`, no administrator pass) of the credential's organisation
- [x] 2.2 Keep the matching `actingOrganisationId` admit path, the session path and the personal owner guard unchanged
- [x] 2.3 Fail closed when no user manager is wired
- [x] 2.4 `requestForBackgroundUser()`: PHP-internal, not routed; the guards ignore the session; refuses a call naming no user

## 3. Show and choose the organisation
- [x] 3.1 `GET /api/credentials/organisations`: the organisations the caller may manage, active one flagged
- [x] 3.2 `GET /api/credentials?scope=organisation&organisation=<uuid>`: 403 without access to that organisation
- [x] 3.3 Create keeps re-checking `isOrganisationAdmin()` for the organisation the client names

## 4. Tests
- [x] 4.1 Sessionless member admitted through `request()` and the secret read from the organisation vault (fails on the old code)
- [x] 4.2 Sessionless non-member, unknown user, disabled user and empty user denied before any secret read
- [x] 4.3 Session caller: an asserted member never rescues a non-member session
- [x] 4.4 Personal credential: an asserted user who is not the owner is denied
- [x] 4.5 HTTP path: an unauthenticated call never reaches the broker, and an `actingUserId` body field is never forwarded
- [x] 4.6 Sessionless administrator who is not a member is denied (fails on the first version, which used the session rule)
- [x] 4.7 Picker endpoint, chosen-organisation listing and its 403 (fail on the old code)
- [x] 4.8 `requestForBackgroundUser()` refuses a switched-in administrator who is not a member, admits a real member inside another session, refuses nobody and a personal non-owner; `request()` keeps the session rule
