# Tasks: credential-outside-vault-reference

## 1. Model

- [x] 1.1 `custody` and `vaultRef` on `brokeredcredential`, and the `outside-vault` kind in `credential-providers.json`; schema version bumped. Verify: `tests/Unit/Settings/CredentialBrokerRegisterTest.php` reads both properties after import.
- [x] 1.2 Mint refuses a secret on an `outside` credential and requires a `vaultRef` whose connection the caller may use. Verify: `CredentialBrokerServiceTest` for both refusals.

## 2. Reader

- [x] 2.1 `OutsideVaultReader` for HashiCorp Vault KV v2 and OpenBao with token and AppRole login, two second timeout, request-scoped cache. Verify: unit test against a fake HTTP client for login, read, a missing key and a 403.
- [x] 2.2 Branch on `custody` in `resolveInjectable()` and the proxy path after both guards; failures set `lastError` to a fixed sentence. Verify: `CredentialBrokerServiceTest` asserts the guards run before the reader and that no secret appears in logs or responses.

## 3. Page, ADR, proof and docs

- [ ] 3.1 Credential page shows "held in an outside vault", the path and the last read time. Verify: component test.
  - server half built (26 Nov, build/credential-outside-vault-reference): a read the vault answers stamps `lastReadAt` on the credential (at most once a minute, `OutsideCustody::READ_STAMP_INTERVAL`) and clears the read failure it left in `lastError`; `lastReadAt` is a `brokeredcredential` property (schema 1.6.0), so `GET /api/credentials` lists it beside `custody` and `vaultRef.path`. Tests: `tests/Unit/Service/Credential/OutsideCustodyTest.php`, `CredentialBrokerOutsideVaultTest.php`, `tests/Unit/Settings/CredentialBrokerRegisterTest.php`. Open: the page itself is `CnCredentials` in nextcloud-vue (another repository), which renders "held in an outside vault", the path and this time, with its component test there.
- [ ] 3.2 Hydra PR adding the outside-reference paragraph to ADR-064; link it here.
- [ ] 3.3 Add `tests/e2e/ci/credential-outside-vault.spec.ts` against an OpenBao container in CI: create a connection and an outside credential, call a source through the proxy, and assert the call used the vault's value.
- [x] 3.4 Document the setup in `docs/`, including the vault policy the AppRole needs.

Acceptance:
- No outside secret is ever written to Doriath, the Nextcloud vault, the database or a log.
- An app that may not use a credential cannot make the broker read its outside secret.
