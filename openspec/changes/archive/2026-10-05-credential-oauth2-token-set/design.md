## Context

See proposal.md for motivation. The constraints that shape the approach are already in the tree.

`CredentialBrokerService::request()` is a fixed guard chain: owner or organisation membership, allowed apps, provider allow-rules, host-lock, then read the secret and substitute it into one header template. `ProviderCatalogue` reads `lib/Settings/credential-providers.json` from disk and nothing writes it. `CredentialStore` is a three-method leaf, `put` / `get` / `delete`, keyed by the credential UUID and its scope, with a categorical docblock forbidding logging, persistence to an object, export, or return in an API response. The catalogue's own `$fleetComment` states that the broker "cannot do a token exchange", and lists BRP as the first casualty.

ADR-064 makes three of the boundaries non-negotiable: no secret on an object, the proxy is preferred over injection, and infrastructure credentials use organisation scope. The value of the proxy for OAuth2 is unusually high, because a refresh token is a long-lived credential that an app that never sees it cannot lose.

## Goals / Non-Goals

**Goals:**

- Add one credential kind without changing what any existing credential does at runtime.
- Keep the host-lock meaningful for providers whose host is per account.
- Make refresh a property of the read path, so a consumer never has to think about expiry.
- Make a dead grant loud once and silent afterwards, rather than a repeated failing call.

**Non-Goals:**

- The authorise and callback dance. That is `credential-oauth2-connect-flow`.
- DPoP-bound tokens. Bluesky's AT Protocol OAuth requires them, and this change stops at the catalogue entry and the token-set plumbing; the DPoP proof layer is a follow-up.
- Client-credentials grants, mTLS, and signing operations. They remain unbrokerable and stay named in the catalogue comment.
- Provider-specific request shaping. That belongs to the consuming app's OpenConnector adapters, per ADR-067 and ADR-091.

## Decisions

### D1: kind lives on the catalogue entry, not on the credential object

The alternative is a `kind` property the minting caller sets. It was rejected because the catalogue is the security control: it is read-only at runtime and reviewed on release, and the object is writable by its owner. If the object chose the injection path, an owner could flip a proxy credential's behaviour with an ordinary object write. The object still mirrors `kind` so the settings UI and a listing can show it without loading the catalogue, and the broker treats a disagreement as a stale mirror rather than as input.

### D2: the token set is one opaque secret, not several

Storing the access token and the refresh token as two `CredentialStore` entries would make rotation a two-write operation with a window in which the pair does not agree. One serialised document gives an atomic swap for free: a `put` either lands the whole new set or leaves the whole old one. `OAuth2TokenSet` is an immutable value object with a private constructor, named constructors for decoding stored JSON and for parsing a provider token response, and no `__toString`. It carries a `withRefreshed()` that returns a new instance and keeps the previous refresh token when the response omits one, which is the majority behaviour among the six providers here.

### D3: a per-account host is pinned to the credential, so the lock moves rather than weakens

Mastodon and Bluesky have no single API host. A catalogue entry for them cannot carry a `baseUrl`, and the obvious workaround, making them `inject_only`, would hand the refresh token to the calling app and lose the whole point.

The chosen shape is `baseUrlFrom: "instanceBaseUrl"`. It changes where the lock's value comes from, not whether there is one:

- The catalogue still declares the provider's allow-rules, so the permitted method and path set is fixed in a reviewed release exactly as for every other entry.
- The host is validated once, at mint, against a syntactic and network safety rule: absolute `https`, no userinfo, no query, no fragment, a registrable domain rather than an IP literal, and not a loopback or private-range name. This is the same class of check the webhook target guard makes, and it exists to stop the broker being turned into an SSRF tool against the instance's own network.
- The value is then immutable. `CredentialController::update()` rejects a write that changes it. A credential is therefore locked to one host for its whole life, which is a stronger statement than the catalogue can make for a shared provider, because it is per credential rather than per entry.

The rejected alternative was an admin-approved per-install provider registration, which the catalogue's comment already names as "a broker design change". It is the right answer for OpenConnector's arbitrary sources and the wrong size for this: here the host is not arbitrary, it is the account's own server, discovered during the connect flow and never chosen by hand.

### D4: refresh happens inside `request()`, behind one new hook

`request()` gains exactly one new call, replacing the direct `credentialStore->get()`:

```php
$injectable = $this->injectionSecret(credential: $credential, provider: $provider, scope: $scope);
```

`injectionSecret()` dispatches on the catalogue kind. For `secret` it is the old two lines verbatim. For `oauth2-token-set` it delegates to `OAuth2RefreshService::accessTokenFor()`, which decodes, checks the margin, refreshes under the lock when needed, and returns the access token. The guard chain above it is untouched, and the guard-ordering test asserts that a denial happens before any token endpoint is contacted.

The margin defaults to 120 seconds. It is a constant rather than a config value, because a per-install margin is one more thing that can be set wrong and no provider here issues a token shorter than five minutes.

### D5: the lock is a distributed-cache compare-and-set, and its absence is not silent

`CredentialLock` wraps `ICacheFactory::createDistributed()`. When the cache is an `IMemcache`, `add()` gives an atomic acquire, and the lock carries a TTL so a crashed holder does not wedge the credential. A waiter polls briefly and then re-reads the stored token set rather than refreshing itself: after a successful refresh by the holder, the re-read finds a token outside the margin and no second exchange happens.

On an install with no distributed cache, `ICacheFactory::isAvailable()` is false and there is no atomic primitive available. The lock then degrades to advisory and says so in a warning log line, once per acquire. That is a real weakening, and it is the reason the log line exists: "we take a lock" must be a claim someone can substantiate, exactly as ADR-064 requires of "secrets are in Doriath". The worst case is a duplicate refresh, which every provider here tolerates, not a lost token, because the custody write is a single atomic `put` either way.

### D6: `relink_needed` is a distinct, typed failure that existing catch blocks still fail closed on

`CredentialRelinkRequiredException extends CredentialAccessDeniedException`. That gives callers a type they can catch specifically to offer a reconnect, while every existing `catch (CredentialAccessDeniedException)` in the tree keeps failing closed rather than falling through to an untyped error. The controller maps it to HTTP 409 with a static message, so the client learns "reconnect this credential" without learning which guard or which provider error produced it.

`invalid_grant` is the only refresh error treated as terminal. Everything else, including a 500 or a timeout from the token endpoint, is an upstream failure that leaves the status `active`. Treating a provider outage as a revoked grant would relink every connection on the instance during someone else's incident.

### D7: the notification is a Nextcloud notification to the owner, not an email

The owner is a Nextcloud user, the action needed is inside Nextcloud, and `IManager::notify()` already deduplicates by object id. Rate limiting is inherent: the status flips to `relink_needed` on the first failure, and a `relink_needed` credential never reaches the refresh path again, so the notification is produced once per break rather than once per attempt.

### D8: the job is a `TimedJob` in `lib/BackgroundJob/`, registered in `info.xml`

Per ADR-069 rules 1, 2 and 3. Interval 86400 seconds, refresh window 48 hours, so a token with a one-hour life is still refreshed on read and a token with a 60-day life is refreshed by the job well before it lapses. The job holds the same lock as the read path, so a sweep and a live call never both exchange.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
| --- | --- | --- |
| `status` vocabulary and metadata shape | Declarative, `credential_broker_register.json` | It is a schema statement about a stored object, and the enumeration belongs where every other property constraint lives. |
| Refresh, rotation, lock, relink | Imperative, `lib/Service/Credential/` | ADR-031's external-integration exception. This is an HTTP exchange with an external provider under a lock, with an error taxonomy that decides a lifecycle transition. No schema grammar expresses it. |
| Daily sweep | Imperative `TimedJob` | ADR-031's scheduled-bulk-work exception, and ADR-069 rule 3. |
| Owner notification | Imperative | It is emitted from the refresh failure path, which is imperative already; a declarative notification cannot observe an OAuth2 error code. |

## Seed data (ADR-001)

The `credential-broker` register already ships two example objects with the nil UUID as owner. This change adds one more in the same shape, so the new metadata properties have a worked example:

- `Reisbureau Example, Mastodon company account`: provider `mastodon`, kind `oauth2-token-set`, scope `organisation`, `instanceBaseUrl` `https://mastodon.example`, `status` `pending`, `scopes` `["read:accounts", "write:statuses"]`, `account` with an empty handle, `owner` the nil UUID, no secret. A `pending` seed carries no token set by construction, which keeps the seed honest: the register JSON has no place to put one and should not.

## Risks / Trade-offs

- A per-credential host is a smaller blast radius than a shared host but a larger surface to review → the validation runs at mint, the value is immutable afterwards, and the rejection cases are unit tested rather than assumed.
- No distributed cache means no true lock → the degradation is logged on every acquire, and the atomic single-`put` rotation means the failure mode is a wasted exchange rather than a corrupted token set.
- A provider that rotates refresh tokens on every use and then fails mid-write would strand the credential → the custody write happens before the object update, so the leaf always holds the newest set the provider issued, and the next read decodes it.
- Six catalogue entries written against published documentation cannot all be verified without six live developer accounts → each entry's allow-rules are scoped to the calls the marketing programme names, and a wrong path fails closed with a 403 rather than succeeding wrongly. The fix is another reviewed release, which is the same posture the Doffin entry already documents.

## Migration Plan

The change is additive. No existing catalogue entry gains a `kind`, so every existing credential takes the `secret` path byte for byte. The register version moves to 1.4.0 and the new properties are optional, so existing objects validate unchanged. Rollback is removing the entries and the new services; no stored data changes shape.

## Open Questions

- Whether the refresh window should become a per-provider value once a provider with a very short refresh-token life appears. Deferrable: the window is a job constant and changing it later changes no spec, no API and no stored data.
