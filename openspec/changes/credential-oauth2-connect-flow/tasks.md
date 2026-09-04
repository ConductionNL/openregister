## 1. State and client resolution

- [ ] 1.1 Add `OAuth2StateService` issuing and verifying a signed, expiring, single-use state and holding the PKCE verifier server-side against its nonce; verify tampering, expiry and replay rejections in `OAuth2StateServiceTest`
- [ ] 1.2 Add `OAuth2ClientResolver` returning a client id and secret from a caller-supplied `credentialRef` or the administrator-set default, resolved through the existing broker guard chain; verify with `OAuth2ClientResolverTest`

## 2. Connect, callback and relay

- [ ] 2.1 Add `OAuth2ConnectService` building the authorization URL with PKCE, exchanging a code at the catalogue token endpoint, and minting or overwriting an `oauth2-token-set` credential; verify with `OAuth2ConnectServiceTest` against a mocked token endpoint
- [ ] 2.2 Add `OAuth2RelayGuard` accepting a forward target only when its origin is on the administrator-managed allow-list and its path is this application's callback path; verify the refusal cases in `OAuth2RelayGuardTest`
- [ ] 2.3 Add `CredentialOAuth2Controller` with `start`, `callback`, `disconnect` and `clientMetadata`, the callback carrying `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, `#[BruteForceProtection]` and an explicit `registerAttempt()` on every rejection branch; verify with `CredentialOAuth2ControllerTest`
- [ ] 2.4 Register the four routes in `appinfo/routes.php` and verify each resolves to an existing method with a declared auth posture
- [ ] 2.5 Implement relay forwarding in the same `callback` method, deciding by the state's own declared callback and making no token request in that branch; verify with `CredentialOAuth2RelayTest`

## 3. Provider specifics

- [ ] 3.1 Serve AT Protocol client metadata whose client identifier is the endpoint's own URL and whose redirect target is this instance's callback; verify with `CredentialOAuth2ControllerTest`
- [ ] 3.2 Register a Mastodon application at the account's server at start, storing the client id on the credential and the client secret as a separate brokered credential; verify with `OAuth2ConnectServiceTest` against a mocked registration response
- [ ] 3.3 Revoke upstream where the catalogue declares a revoke endpoint, then delete the stored secret and set `disabled` even when the revoke failed; verify both branches in `CredentialOAuth2ControllerTest`

## 4. Re-authorisation

- [ ] 4.1 Overwrite an existing credential in place on re-authorisation, preserving id, `allowedApps`, shares, scope and organisation, clearing `lastError` and returning to `active`; verify with `OAuth2ReauthorisationTest`
- [ ] 4.2 Refuse a re-authorisation that would change the credential's provider or pinned instance host, and refuse one the caller may not manage; verify both in `OAuth2ReauthorisationTest`

## 5. Frontend and e2e

- [ ] 5.1 Add `src/components/userSettings/OAuth2ConnectionsSection.vue` listing connections with status chips and Connect, Reconnect and Disconnect actions, mounted from `PersonalRoot.vue`; verify with its jest spec
- [ ] 5.2 Add `tests/e2e/credential-oauth2-connect.spec.ts` covering the connect entry point, the status chips and the reconnect action; verify it lints

## 6. Quality

- [ ] 6.1 Run `composer check:strict`, `npm run format`, `npm run lint`, `npm test` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`, and fix every finding in a touched file; verify by exit code

### Acceptance criteria

- A tampered, expired or replayed state mints nothing, and each rejection registers a throttler attempt.
- The relay never contacts a token endpoint and never resolves a client, asserted by a test that fails if it does.
- Re-authorising a `relink_needed` connection produces the same credential id in `active`, with no second credential for the same account.
- The connections panel renders an account handle, its scopes and its expiry, and no token.
