## Purpose

Delta against the `credential-broker` capability: in addition to the OR app-key
onboarding (`CredentialAppTokenService::registerApp`, `credential-doriath-leaf`
D-G), each consuming app that onboards to the broker SHALL register its OWN
Doriath `Application` — manifest-driven, idempotent, pending by default — so it
appears in Doriath's Applications list with its own identity and approval state.
This is **identity-only**: secret custody is unchanged and still lives under
OpenRegister's single self-registered Doriath application vault
(`credential-doriath-leaf` D-B/D-C/D-F). The four ordered broker guards, the
credential metadata schema, the provider catalogue, and the HTTP contract are
unchanged.

NOTE (as in `credential-doriath-leaf`): the base `credential-broker` spec still
lives in its active head change
(`openspec/changes/credential-broker/specs/credential-broker/spec.md`);
`openspec/specs/credential-broker/` does not exist yet, and the self-registration
+ manifest-driven onboarding requirements this delta builds on live in the
`credential-doriath-leaf` delta.

## ADDED Requirements

### Requirement: Per-app Doriath application registration

OpenRegister SHALL register each consuming app that onboards to the credential
broker as its OWN Doriath `Application`, in addition to the existing OR app-key
registration (`CredentialAppTokenService::registerApp`). Onboarding means an
AppHost leaf initialising, or a virtual-app manifest declaring a non-empty
`credentials[]` being registered. Registration SHALL reuse Doriath's
`ApplicationService::register` in-process (resolved via `class_exists` +
`OCP\Server::get`, no compile-time dependency on Doriath), with the application
name equal to the consuming appId, description drawn from the app's manifest,
and type `internal`. Because this is identity-only, the registration SHALL NOT
supply a CSR and SHALL NOT provision an EncryptionSuite; brokered secret custody
SHALL remain under OpenRegister's single self-registered Doriath application
vault, unchanged.

The registration SHALL be **pending by default** (Doriath's non-admin
registration path), so an administrator approves the app before it is active.
The registration SHALL be **idempotent**: OpenRegister SHALL persist the
Doriath-assigned application UUID in `IAppConfig` under a per-app key (namespaced
by appId and distinct from OpenRegister's own application UUID), and SHALL skip
when that UUID is set and the Doriath row still exists — it SHALL NEVER
re-register or rotate an existing application. When Doriath is absent, disabled,
or its `ApplicationService` is unloadable, the step SHALL degrade (warn, never
throw); the app's OR app-key onboarding and the broker SHALL continue unchanged.

#### Scenario: Onboarding app registers its own Doriath application

- **WHEN** an app whose manifest declares a non-empty `credentials[]` onboards to the broker on an instance with an eligible Doriath
- **THEN** OpenRegister registers a Doriath `Application` named after the consuming appId, description from the manifest, type `internal`, with no CSR
- **AND** the app appears in Doriath's Applications list with its own identity, separate from OpenRegister's own application

#### Scenario: Per-app registration is pending until an admin approves

- **WHEN** the per-app Doriath `Application` is registered via the non-admin path
- **THEN** it is created with status `pending` and an admin-approval notification is dispatched
- **AND** the app becomes active only after an administrator approves it in Doriath

#### Scenario: Registration is idempotent

- **WHEN** the onboarding hook runs again after a successful per-app registration and the persisted application UUID still matches a live Doriath row
- **THEN** OpenRegister makes no new registration call and does not rotate or mutate the existing application

#### Scenario: Custody is unchanged

- **WHEN** a per-app Doriath `Application` is registered
- **THEN** no brokered secret is moved, re-keyed, or re-encrypted
- **AND** brokered secret custody remains under OpenRegister's single self-registered Doriath application vault

#### Scenario: Doriath unavailable degrades

- **WHEN** the onboarding hook runs while Doriath is absent, disabled, or its `ApplicationService` is unloadable
- **THEN** it logs/outputs a warning and completes without error
- **AND** the app's OpenRegister app-key onboarding and the credential broker continue to operate unchanged
