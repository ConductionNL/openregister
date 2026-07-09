# Tasks — credential broker organisation scope

## Schema
- [ ] Add `scope` (enum personal|organisation, default personal) + `organisation`
      (UUID, required when scope=organisation) to the `credential` schema in
      `lib/Settings/credential_broker_register.json`; keep it secret-free.
- [ ] Bump the register descriptor + confirm the Repair-step materialisation picks up
      the new properties (ADR-037: OR does not self-import its own register JSON).

## Vault owner selection (D2)
- [ ] Add a single private helper that maps a credential's scope → vault owner
      (`''` system identity for organisation, `owner` uid for personal), used at BOTH
      write and read time.
- [ ] Store/rotate organisation secrets via `ICredentialsManager` under the system
      identity keyed by the credential UUID; personal path unchanged.

## Broker guard (D3)
- [ ] Dispatch the owner guard on `scope`: personal branch byte-for-byte unchanged;
      organisation branch = acting user is a member of `credential.organisation`
      (resolved via `UserService`), then the existing allowedApps/provider/host-lock
      guards run for both scopes.
- [ ] Organisation calls require a real session (no `actingUserId` sessionless
      fallback); deny when unauthenticated.

## Controller / API (D4, D5)
- [ ] `POST /api/credentials`: accept `scope` + `organisation`; gate organisation
      creation to org-admin (or NC admin); default organisation to the caller's active
      organisation when omitted.
- [ ] `PUT` / `DELETE` on an organisation credential: same org-admin gate.
- [ ] `GET /api/credentials?scope=organisation`: list the active organisation's
      credentials (members may read metadata; secrets never returned).
- [ ] Confirm the no-scope / `?scope=personal` paths are unchanged.

## Tests
- [ ] Personal-path regression: a no-`scope` create/list/broker-call behaves identically
      to before (guard, storage, response).
- [ ] Organisation happy path: org-admin creates → member's app call resolves through
      the system vault → non-member is denied → non-allowed app is denied.
- [ ] Guard invariant: a personal credential is never admitted to a non-owner (the org
      branch cannot leak into the personal branch).
- [ ] Vault-owner helper: organisation secret written under system identity, personal
      under owner; delete removes the right vault entry.

## Follow-ups (out of scope here)
- [ ] Finer organisation roles for credential management (beyond owner + NC admin).
- [ ] openconnector `authentication.credentialRef` documentation for org-scoped refs.
