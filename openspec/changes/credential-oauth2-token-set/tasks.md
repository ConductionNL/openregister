## 1. Catalogue and schema

- [ ] 1.1 Add `kind`, `oauth2` and `baseUrlFrom` support to `ProviderCatalogue` consumers and add the six provider entries (linkedin, meta-graph, x, bluesky, mastodon, google) to `lib/Settings/credential-providers.json`, bumping `version` and recording in `$fleetComment` that a token exchange is now possible for the `oauth2-token-set` kind; verify with `npm run check:json-strict` and `ProviderCatalogueTest`
- [ ] 1.2 Add the ten non-secret properties and the `status` enumeration to `brokeredcredential` in `lib/Settings/credential_broker_register.json`, bump its version, and add the pending Mastodon seed object from design.md; verify with `npm run check:register`

## 2. Token set and custody

- [ ] 2.1 Add `OAuth2TokenSet` as an immutable value object with named constructors for a stored document and for a provider token response, and `withRefreshed()` keeping the previous refresh token when the response omits one; verify with `OAuth2TokenSetTest`
- [ ] 2.2 Add `CredentialLock` over `ICacheFactory` with an atomic acquire, a TTL, and a warning log line when no distributed cache is available; verify with `CredentialLockTest` including the contention case

## 3. Refresh on the read path

- [ ] 3.1 Add `OAuth2RefreshService` performing the refresh grant against the catalogue token endpoint, rotating through one `CredentialStore::put()` and updating the object only after the custody write succeeded; verify with `OAuth2RefreshServiceTest` against a mocked client
- [ ] 3.2 Add `CredentialRelinkRequiredException`, `CredentialRelinkRequiredEvent` and the owner notification, fired only on `invalid_grant`; verify a transient failure leaves the status `active` in `OAuth2RefreshServiceTest`
- [ ] 3.3 Replace the direct secret read in `CredentialBrokerService::request()` with a kind-aware `injectionSecret()` hook and add per-credential host resolution for `baseUrlFrom` entries; verify guard ordering and the `secret`-kind regression in `CredentialBrokerServiceTest`
- [ ] 3.4 Reject a `relink_needed` or `disabled` credential before any outbound request, and map the typed exception to HTTP 409 in `CredentialController`; verify with `CredentialControllerTest`
- [ ] 3.5 Validate and pin `instanceBaseUrl` at mint and reject a later change in `CredentialController::update()`; verify the loopback, private-range and mutation rejections in `CredentialBrokerMintTest`

## 4. Sweep and wiring

- [ ] 4.1 Add `lib/BackgroundJob/OAuth2TokenRefreshJob.php` as a `TimedJob` on a daily interval refreshing active token sets inside the window, continuing past a per-credential failure, and register it in `appinfo/info.xml`; verify with `OAuth2TokenRefreshJobTest`
- [ ] 4.2 Assert no token value reaches an object payload, a log record, an event or a notification; verify with `OAuth2NoTokenLeakTest`

## 5. Quality

- [ ] 5.1 Run `composer check:strict`, `npm run format`, `npm run lint` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`, and fix every finding in a touched file; verify by exit code

### Acceptance criteria

- An existing credential with no catalogue `kind` takes an unchanged code path, asserted by a regression test rather than by inspection.
- No access token, refresh token or client secret appears in a credential object, a log line, an event payload or an API response.
- Two concurrent brokered calls on one expiring credential perform exactly one token exchange.
- A refresh answered with `invalid_grant` flips the status once, notifies the owner once, and fails every later call closed.
