---
kind: code
depends_on:
  - credential-oauth2-token-set
---

## Why

`credential-oauth2-token-set` gives the broker a credential kind it can refresh, but nothing can create one. An OAuth2 token set is minted by a user consenting at the provider, and every network except Bluesky keeps an exact-match allow-list of callback URLs. A tenant's own Nextcloud domain can never be the callback of a Conduction-owned developer app, and the caps are small: X allows ten callbacks per app, Google a hundred. Without a relay, connecting an account means every tenant filing its own developer application.

This change adds the authorise, callback and mint dance to the broker, and puts the relay and the receiving instance in one controller so a Conduction-hosted relay runs the same code every tenant runs.

## What Changes

- `POST /api/credentials/oauth2/start`, authenticated. Takes a provider, the scopes to ask for, the desired credential scope, an optional tenant client override, an optional instance host for Mastodon and Bluesky, and a return URL. Returns the provider's authorization URL with PKCE and a signed, short-lived `state`.
- `GET /oauth2/callback`, a throttled `PublicPage`. Verifies `state`, exchanges the code, mints or overwrites the token-set credential, and redirects to the return URL declared at start.
- Relay mode in the same handler. When `state` names a callback on another instance, the relay validates the target against an admin-set allow-list and forwards the code there without ever exchanging it.
- `GET /oauth2/client-metadata.json`, a `PublicPage` serving the instance's own AT Protocol client metadata, so Bluesky needs no relay.
- Mastodon app registration at connect time, storing the client id on the credential and the client secret as a separate brokered credential.
- `DELETE /api/credentials/oauth2/{id}`, which revokes upstream where the provider has a revoke endpoint and then disables the credential.
- A "Connect account" panel in OpenRegister's personal settings, showing a status chip per connection and a Reconnect action that re-runs the flow onto the same credential id.

## Capabilities

### New Capabilities

- `credential-oauth2-connect`: the start, callback, relay, revoke and reconnect surface that mints and re-mints an `oauth2-token-set` credential.

### Modified Capabilities

- `credential-oauth2-token-set`: re-authorisation of an existing credential overwrites the same credential id and returns it to `active`, so every reference to it keeps working.

## Impact

- `lib/Controller/CredentialOAuth2Controller.php` and four `appinfo/routes.php` entries.
- `lib/Service/Credential/`: `OAuth2StateService`, `OAuth2ConnectService`, `OAuth2RelayGuard`, `OAuth2ClientResolver`.
- `src/components/userSettings/OAuth2ConnectionsSection.vue`, mounted from the existing `PersonalRoot.vue`.
- Admin configuration: the relay target allow-list and the per-provider default client credentialRef.
- Consumers: pipelinq marketing phase 3 and phase 5 connect their accounts through this surface. No existing endpoint changes.
