---
kind: mixed
---

## Why

The credential broker can only express one shape of secret: a single opaque string substituted into one request header. The catalogue file says so itself, in the `$fleetComment` that lists what is "STILL NOT BROKERABLE" and names a token exchange first. Every OAuth2 provider therefore has no compliant home in the broker, so an app that talks to LinkedIn, Meta, X, Bluesky, Mastodon or Google Search Console either keeps custody of the tokens itself, which ADR-064 forbids, or does not integrate at all.

The pipelinq marketing programme needs exactly that capability in its phase 0: a tenant connects a social account or a Search Console property once, and every later publish or metrics pull runs on a token the broker holds, refreshes and rotates without the app ever seeing it. This change adds the missing credential kind. The connect dance that mints one is the sibling change `credential-oauth2-connect-flow`.

## What Changes

- A second credential kind, `oauth2-token-set`, declared by the provider catalogue entry rather than by the credential object. Entries without a `kind` keep today's behaviour verbatim, so nothing existing moves.
- The stored secret for that kind is one opaque JSON document holding the access token, refresh token, expiry, token type, granted scopes, the provider account identity and the raw token response. It goes to the custody leaf whole and is never split across the object.
- New non-secret metadata on `brokeredcredential`: `kind`, `status`, `scopes`, `account`, `expiresAt`, `lastRefreshedAt`, `lastError`, `instanceBaseUrl`, `clientId` and `clientCredentialRef`. No property carries a token.
- Catalogue entries for LinkedIn, Meta Graph, X, Bluesky, Mastodon and Google, each with its authorization, token and revoke endpoints, its refresh grant shape, and allow-rules covering publishing a post, reading own post metrics, reading followers and querying Search Console.
- Per-credential host binding for the two providers whose host is per account, Mastodon and Bluesky. The catalogue declares that the host comes from connection metadata; the broker pins it onto the credential at mint and refuses to serve any other host afterwards.
- `request()` learns to refresh. Past `expiresAt` minus a margin it takes a per-credential lock, refreshes, rotates the stored set atomically, then injects `Authorization: Bearer <access token>`.
- A refresh rejected with `invalid_grant` moves the credential to `relink_needed`, emits `CredentialRelinkRequiredEvent`, notifies the owner, and makes every later `request()` fail closed with a typed exception.
- A daily `TimedJob` refreshes every `active` token set whose expiry falls inside the refresh window.
- Personal scope for a person's own account, organisation scope for a company page, per ADR-064 rule 4.

## Capabilities

### New Capabilities

- `credential-oauth2-token-set`: the OAuth2 token-set credential kind, its stored payload, refresh and rotation under a lock, the relink lifecycle, and the daily refresh sweep.

### Modified Capabilities

- `credential-broker`: `request()` gains a kind-aware injection step, the provider catalogue gains `kind`, `oauth2` and `baseUrlFrom` fields, and the host-lock is allowed to resolve from per-credential metadata for entries that declare it.

## Impact

- `lib/Service/Credential/`: new `OAuth2TokenSet`, `OAuth2TokenSetCodec`, `OAuth2RefreshService`, `CredentialLock`, `CredentialRelinkRequiredException`; `CredentialBrokerService::request()` gains one injection hook.
- `lib/Settings/credential-providers.json`: six new entries and a version bump.
- `lib/Settings/credential_broker_register.json`: ten new non-secret properties.
- `lib/BackgroundJob/OAuth2TokenRefreshJob.php` plus its `appinfo/info.xml` registration.
- `lib/Event/CredentialRelinkRequiredEvent.php`.
- Consumers: pipelinq marketing (phase 3 and 5), and any later app that needs a refreshing OAuth2 token. No existing consumer changes, because no existing credential carries a `kind`.
