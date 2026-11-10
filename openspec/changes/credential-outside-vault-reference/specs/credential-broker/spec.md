# credential-broker

## ADDED Requirements

### Requirement: A credential can reference a secret held in an outside vault

A brokered credential MAY declare `custody: "outside"` with a `vaultRef` naming
an `outside-vault` connection credential, a secret path and a key. The broker
SHALL read that secret from the vault each time an authorized call needs it,
SHALL keep it only for the duration of the request, and SHALL NOT write it to
the custody leaf, the database, a log or a response.

#### Scenario: an administrator keeps a source password in HashiCorp Vault

- **GIVEN** an administrator who created an `outside-vault` connection to the organisation's Vault, and a source credential with `vaultRef: { path: "integriq/zaaksysteem", key: "password" }`
- **WHEN** integriq calls the source through the credential broker's proxy
- **THEN** the call carries the password read from the vault at that moment
- **AND** the credential page shows the path and the last read time, and no stored secret exists for that credential
- @e2e exclude {specified only; task 3.3 adds tests/e2e/ci/credential-outside-vault.spec.ts}

#### Scenario: the vault refuses the read

- **GIVEN** the same credential after the vault revoked the AppRole
- **WHEN** integriq calls the source
- **THEN** the broker answers with an upstream error naming the vault connection, not the source
- **AND** the credential's `lastError` holds a fixed sentence without the vault's response body
- @e2e exclude {specified only; covered by OutsideVaultReader unit test in task 2.1}

### Requirement: Authorization is decided before an outside secret is read

The broker SHALL apply the owner and membership guard and the allowed-app
guard to an outside credential exactly as to a held one, and SHALL NOT contact
the outside vault for a caller the guards refuse.

#### Scenario: an app that is not allowed gets nothing

- **GIVEN** an outside credential whose `allowedApps` lists only integriq
- **WHEN** another app asks the broker to resolve it through `resolveInjectable()`
- **THEN** the broker refuses the app and makes no request to the vault
- @e2e exclude {specified only; covered by CredentialBrokerServiceTest in task 2.2}
