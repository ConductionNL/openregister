## Context

See proposal.md for motivation, and `credential-oauth2-token-set` for the credential kind this change mints. The constraint that shapes everything here is in the marketing architecture's connection table: Meta, LinkedIn, X and Google all match callback URLs exactly, and their per-application caps are small enough that "every tenant registers its own callback" is not a plan. Bluesky publishes its own client metadata and needs nothing. Mastodon registers an application per server at connect time.

OpenRegister already has the pieces this builds on. `CredentialBrokerService::mint()` is sessionless and takes an already-resolved scope and organisation, which is exactly the shape a callback needs. `CredentialController` owns the authorisation gates. `CaseTokenController` is the worked example of an ADR-082 throttled public endpoint: `#[PublicPage]`, `#[AnonRateLimit]`, `#[BruteForceProtection]`, and a `registerAttempt()` call on the failure branch, because the attribute alone does nothing.

## Goals / Non-Goals

**Goals:**

- One controller that is both the relay and the receiving instance, so the Conduction-hosted relay is not a second implementation with a second set of bugs.
- A `state` that a receiving instance can verify on its own, without trusting the relay.
- Re-authorisation that lands on the same credential id, because every `socialAccount` and `searchProperty` in a consuming app points at that id.

**Non-Goals:**

- DPoP proofs. Bluesky's AT Protocol OAuth requires them for a working token, and this change ships the client metadata endpoint and the code exchange only. The DPoP layer is a named follow-up, not a silent omission.
- Provider-specific business calls. Publishing a post and reading metrics belong to the consuming app through OpenConnector, per ADR-091.
- Automatic discovery of a Mastodon or Bluesky host from a handle. The user supplies the server at start.

## Decisions

### D1: the relay validates shape and destination, the receiving instance validates the signature

A relay cannot verify a `state` it did not sign, and giving every tenant the relay's signing key would make the relay's key the only key. So the two roles check different things, and the split is deliberate rather than a gap:

- The **relay** parses the `state`, reads its declared callback URL, and refuses unless that URL's origin is on an administrator-managed allow-list and its path is this application's callback path. It then 302s to that URL with `code` and `state` intact. It performs no exchange, resolves no client, and mints nothing. A forged `state` therefore buys an attacker one redirect to a host the relay operator already trusts.
- The **receiving instance** verifies the HMAC with its own key, checks the expiry, consumes the nonce, and looks up the PKCE verifier it stored at start. A `state` it did not issue fails at the first of those.

The security property that matters survives: a code can only be exchanged by the instance that started the flow, holds the verifier, and signed the state. That is true whether the relay is honest, compromised, or absent.

The rejected alternative was a shared fleet-wide signing key so the relay could verify. It fails the sovereignty test: a tenant's connections would then be forgeable by whoever holds the fleet key, which is the opposite of what a bring-your-own-instance product should offer.

### D2: PKCE always, and the verifier never travels

Every start generates an S256 challenge. The verifier is stored server-side in the distributed cache keyed by the state's nonce, with the same TTL as the state, and is deleted when consumed. It is absent from the authorization URL, from the `state`, and from the start response. This is what makes the relay safe to be dumb: forwarding a code to a host that has no verifier gets that host nothing.

Consuming the nonce is also what makes the state single-use. The cache entry is the record of "this start has not been completed"; deleting it on first use is both the replay defence and the verifier cleanup, in one operation.

### D3: the client secret is itself a brokered credential

There are three ways a client secret could reach the token exchange, and two of them break ADR-064:

1. An app config value. Rejected: a secret on the instance's config table is exactly the shape the ADR closes.
2. A file. Rejected for the same reason, plus it cannot be per organisation.
3. A brokered credential of the existing `generic-oauth2` kind, resolved through `resolveInjectable()` at exchange time. Chosen.

`OAuth2ClientResolver` therefore returns a client id and a secret from one of two places: the caller's `credentialRef` when the tenant brought its own application, or the administrator-set default credentialRef for that provider otherwise. The `generic-oauth2` entry already exists and already says the app performs the token exchange itself; the broker is now that app, resolving in-process. The resolution runs the ordinary guard chain, so a caller cannot borrow a client secret they have no claim on.

### D4: one endpoint, two roles, decided by the state's own callback field

`callback()` reads the state's declared receiving callback and compares it to this instance's own. Same means receive, different means relay. There is no mode flag, no separate route, and no deployment-time switch, so a Conduction-hosted relay is an ordinary OpenRegister install whose administrator populated the allow-list. That also means the relay path is exercised by the same tests every tenant's code runs.

### D5: throttling follows ADR-082's two-halves rule

`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 30, period: 60)]` and `#[BruteForceProtection(action: 'openregisterOauth2Callback')]`, plus an explicit `IThrottler::registerAttempt()` on every rejection branch. The ADR's own finding is that openregister had 23 public endpoints and zero brute-force machinery; adding an endpoint whose only credential is a signed opaque string without registering its failures would repeat exactly that.

The callback's failure response is uniform: a redirect carrying a generic failure marker where a return URL is known, and a static 400 otherwise. It never reports which check failed and never forwards the provider's own error text, which would be an oracle for state forgery.

### D6: the return URL is declared at start and validated as same-origin

An attacker-chosen redirect target on a callback is an open redirect. The return URL is taken at start, from an authenticated caller, and is accepted only when it is a path on this instance. It is then carried inside the signed state, so the callback redirects to a value the instance itself approved rather than to anything present in the request.

### D7: the frontend is a new OpenRegister-owned section, not a change to `CnCredentials`

The credential wallet on the personal settings page is `CnCredentials`, which lives in `@conduction/nextcloud-vue`, a different repository. Connect is broker behaviour and its status vocabulary is this change's, so the panel ships here as `OAuth2ConnectionsSection.vue` and `PersonalRoot.vue` renders it beside the existing wallet. When the shape settles it can move into the library; putting it there first would mean shipping a library release before the server that backs it exists.

### D8: Bluesky ships honestly incomplete

The client metadata endpoint and the code exchange are real. A Bluesky access token without a DPoP proof will be refused by a PDS, so a Bluesky connection minted by this change is not yet usable for API calls. The alternative was to omit Bluesky from the catalogue entirely, which would have made the gap invisible to the marketing programme's phase 3 planning rather than visible in a follow-up change. It is stated in the catalogue entry's own comment and in the connections panel, which marks the provider as preview.

## Declarative-vs-imperative decision (ADR-031)

Every behaviour here is an HTTP protocol exchange with an external party under a signature and a lock: the authorisation redirect, the code exchange, the relay forward, the upstream revoke, and the per-instance application registration. All fall under ADR-031's external-integration exception and are implemented imperatively in `lib/Service/Credential/`. The one declarative part, the credential's status vocabulary and metadata shape, is declared in `credential_broker_register.json` by the predecessor change and is not re-declared here.

## Risks / Trade-offs

- A compromised relay can redirect a user to any allow-listed tenant → the receiving instance still refuses a state it did not sign, so the worst outcome is a failed connect, and the allow-list is administrator-managed rather than derived from the request.
- The relay operator learns which tenant connected which provider and when → it is metadata the relay must see to route at all; it never sees a token, and a tenant that objects brings its own client and skips the relay entirely.
- A distributed cache is required for the nonce and verifier → without one there is no single-use guarantee, so the start endpoint refuses to issue a state when no distributed cache is available, rather than issuing one that cannot be made single-use.
- Six providers, one code path, and no live developer applications yet → the provider-specific parts are confined to catalogue data, so a wrong endpoint is a data fix in a reviewed release rather than a code change.

## Migration Plan

Additive. Four new routes, one new controller, four new services, one new settings section. Nothing existing changes shape. Rollback is removing the routes; a credential already minted keeps working through the predecessor change's refresh path, because a token set does not depend on how it was created.

## Open Questions

- Whether the relay allow-list should eventually be derived from the federated instance registry rather than typed by an administrator. Deferrable: it changes where a list of origins comes from, not what the relay does with one.
