# Tasks: credential-outside-vault-reference

## 1. Model

- [ ] 1.1 `custody` and `vaultRef` on `brokeredcredential`, and the `outside-vault` kind in `credential-providers.json`; schema version bumped. Verify: `tests/Unit/Settings/CredentialBrokerRegisterTest.php` reads both properties after import.
- [ ] 1.2 Mint refuses a secret on an `outside` credential and requires a `vaultRef` whose connection the caller may use. Verify: `CredentialBrokerServiceTest` for both refusals.

## 2. Reader

- [ ] 2.1 `OutsideVaultReader` for HashiCorp Vault KV v2 and OpenBao with token and AppRole login, two second timeout, request-scoped cache. Verify: unit test against a fake HTTP client for login, read, a missing key and a 403.
- [ ] 2.2 Branch on `custody` in `resolveInjectable()` and the proxy path after both guards; failures set `lastError` to a fixed sentence. Verify: `CredentialBrokerServiceTest` asserts the guards run before the reader and that no secret appears in logs or responses.

## 3. Page, ADR, proof and docs

- [ ] 3.1 Credential page shows "held in an outside vault", the path and the last read time. Verify: component test.
- [ ] 3.2 Hydra PR adding the outside-reference paragraph to ADR-064; link it here.
- [ ] 3.3 Add `tests/e2e/ci/credential-outside-vault.spec.ts` against an OpenBao container in CI: create a connection and an outside credential, call a source through the proxy, and assert the call used the vault's value.
- [ ] 3.4 Document the setup in `docs/`, including the vault policy the AppRole needs.

Acceptance:
- No outside secret is ever written to Doriath, the Nextcloud vault, the database or a log.
- An app that may not use a credential cannot make the broker read its outside secret.
