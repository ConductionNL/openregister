# Tasks — credential broker organisation scope

## Schema
- [ ] Add `scope` (enum personal|organisation, default personal) + `organisation`
      (UUID, required when scope=organisation) to the `credential` schema in
      `lib/Settings/credential_broker_register.json`; keep it secret-free. (code exists, test missing: lib/Settings/credential_broker_register.json)
- [ ] Bump the register descriptor + confirm the Repair-step materialisation picks up
      the new properties (ADR-037: OR does not self-import its own register JSON). (code exists, test missing: lib/Repair/ImportCredentialBrokerRegister.php)

## Vault owner selection (D2)
- [x] Add a single private helper that maps a credential's scope → vault owner
      (`''` system identity for organisation, `owner` uid for personal), used at BOTH
      write and read time. (verified: lib/Service/Credential/NextcloudVaultCredentialStore.php, tests/Unit/Service/Credential/CredentialScopeIsNotAnAccessScopeTest.php)
- [x] Store/rotate organisation secrets via `ICredentialsManager` under the system
      identity keyed by the credential UUID; personal path unchanged. (verified: lib/Controller/CredentialController.php, tests/Unit/Service/Credential/CredentialBrokerMintTest.php)

## Broker guard (D3)
- [x] Dispatch the owner guard on `scope`: personal branch byte-for-byte unchanged;
      organisation branch = acting user is a member of `credential.organisation`
      (resolved via `UserService`), then the existing allowedApps/provider/host-lock
      guards run for both scopes. (verified: lib/Service/Credential/CredentialBrokerService.php, tests/Unit/Service/Credential/CredentialBrokerOrganisationScopeTest.php)
- [x] Organisation calls require a real session (no `actingUserId` sessionless
      fallback); deny when unauthenticated. (verified: lib/Service/Credential/CredentialBrokerService.php, tests/Unit/Service/Credential/CredentialBrokerOrganisationScopeTest.php::testOrganisationCallRequiresSession; sessionless inject-only path added later by openregister#450)

## Controller / API (D4, D5)
- [x] `POST /api/credentials`: accept `scope` + `organisation`; gate organisation
      creation to org-admin (or NC admin); default organisation to the caller's active
      organisation when omitted. (verified: lib/Controller/CredentialController.php, tests/Unit/Controller/CredentialControllerOrganisationTest.php)
- [ ] `PUT` / `DELETE` on an organisation credential: same org-admin gate. (code exists, test missing: lib/Controller/CredentialController.php (DELETE tested in tests/Unit/Controller/CredentialControllerOrganisationTest.php; PUT path untested))
- [x] `GET /api/credentials?scope=organisation`: list the active organisation's
      credentials (members may read metadata; secrets never returned). (verified: lib/Controller/CredentialController.php, tests/Unit/Controller/CredentialControllerOrganisationTest.php::testIndexOrganisationListsActiveOrgMetadataWithoutSecrets)
- [x] Confirm the no-scope / `?scope=personal` paths are unchanged. (verified: lib/Controller/CredentialController.php, tests/Unit/Controller/CredentialControllerOrganisationTest.php::testPersonalCreateIsUnchanged)

## Tests
- [x] Personal-path regression: a no-`scope` create/list/broker-call behaves identically
      to before (guard, storage, response). (verified: tests/Unit/Controller/CredentialControllerOrganisationTest.php, tests/Unit/Controller/CredentialControllerTest.php)
- [x] Organisation happy path: org-admin creates → member's app call resolves through
      the system vault → non-member is denied → non-allowed app is denied. (verified: tests/Unit/Controller/CredentialControllerOrganisationTest.php, tests/Unit/Service/Credential/CredentialBrokerOrganisationScopeTest.php)
- [x] Guard invariant: a personal credential is never admitted to a non-owner (the org
      branch cannot leak into the personal branch). (verified: lib/Service/Credential/CredentialBrokerService.php, tests/Unit/Service/Credential/CredentialBrokerOrganisationScopeTest.php::testPersonalCredentialNeverEntersOrganisationBranch)
- [x] Vault-owner helper: organisation secret written under system identity, personal
      under owner; delete removes the right vault entry. (verified: tests/Unit/Service/Credential/CredentialScopeIsNotAnAccessScopeTest.php, tests/Unit/Service/Credential/CredentialBrokerMintTest.php)

## Follow-ups (out of scope here)
- [ ] Finer organisation roles for credential management (beyond owner + NC admin).
- [ ] openconnector `authentication.credentialRef` documentation for org-scoped refs.
