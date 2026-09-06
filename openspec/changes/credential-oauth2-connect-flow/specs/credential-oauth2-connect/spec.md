## Purpose

Defines how a tenant connects a provider account to the credential broker: the authorisation start, the callback that exchanges the code and mints an `oauth2-token-set` credential, the relay that lets a Conduction-hosted callback serve tenants whose own domain a provider will not accept, and the reconnect and revoke actions on an existing connection.

## ADDED Requirements

### Requirement: Starting a connection returns an authorization URL bound to the caller

`POST /api/credentials/oauth2/start` SHALL be available to an authenticated user only. It SHALL accept a catalogue provider identifier whose entry declares `kind: "oauth2-token-set"`, the scopes to request, a desired credential scope of `personal` or `organisation`, an optional `credentialRef` naming a tenant-supplied `generic-oauth2` client secret, an optional instance host for a provider whose catalogue entry declares `baseUrlFrom`, an optional existing credential id to re-authorise, and a return URL. It SHALL return the provider's authorization URL carrying a `state` value, and a PKCE code challenge for every provider whose catalogue entry declares PKCE support. A code verifier SHALL be generated and held for every start, whether or not the provider consumes it. An organisation-scoped start SHALL be refused unless the caller administers the organisation the credential would belong to.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: A start returns a provider URL and never a secret

- **WHEN** an authenticated user starts a connection for a supported provider
- **THEN** the response carries the provider's authorization endpoint with the requested scopes and a `state`, and a code challenge when the provider supports PKCE
- **AND** it carries no client secret, no code verifier and no token

#### Scenario: An unsupported provider is refused

- **WHEN** a start names a provider that is absent from the catalogue or whose entry is not an OAuth2 token set
- **THEN** the request is refused and no state is issued

#### Scenario: An organisation start needs organisation administration

- **WHEN** a member who does not administer the organisation starts an organisation-scoped connection
- **THEN** the request is refused

### Requirement: The state value is signed, single-use and short-lived

The `state` issued at start SHALL be signed with an instance-held key and SHALL bind the initiating user, the instance, the provider, the desired credential scope, a nonce, the callback URL of the instance that must receive the code, the return URL, any credential id being re-authorised, and an expiry. The callback SHALL reject a `state` whose signature does not verify, whose expiry has passed, or whose nonce has already been consumed. The PKCE code verifier SHALL be held server-side against the nonce and SHALL NOT travel inside the `state`.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: A tampered state is refused

- **WHEN** any field of a `state` value is altered before the callback is reached
- **THEN** the callback refuses it and mints nothing

#### Scenario: An expired state is refused

- **WHEN** a `state` is presented after its expiry
- **THEN** the callback refuses it and mints nothing

#### Scenario: A replayed state is refused

- **WHEN** a valid `state` is presented a second time
- **THEN** the first presentation is honoured and the second is refused

#### Scenario: The verifier never leaves the instance

- **WHEN** a start is issued
- **THEN** the code verifier is not present in the authorization URL, the `state`, or the response body

### Requirement: The callback exchanges the code and mints a token-set credential

`GET /oauth2/callback` SHALL be a public, throttled endpoint. On a `state` that verifies and names this instance as the receiving instance, it SHALL exchange the authorization code at the catalogue's token endpoint using the held code verifier and the resolved client, SHALL mint an `oauth2-token-set` credential holding the resulting token set in the custody leaf, SHALL record the granted scopes, the account identity and the expiry as non-secret metadata with `status` `active`, and SHALL then redirect the user to the return URL declared at start. A failed exchange SHALL mint nothing and SHALL redirect to the same return URL carrying a failure marker.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: A successful callback produces one active credential

- **WHEN** the callback receives a code with a valid state
- **THEN** exactly one credential exists for that provider and account, with status `active`
- **AND** the browser is redirected to the return URL declared at start

#### Scenario: A failed exchange leaves nothing behind

- **WHEN** the token endpoint rejects the exchange
- **THEN** no credential object and no stored secret is created
- **AND** the redirect carries a failure marker rather than a provider error message

#### Scenario: A failed callback is throttled

- **WHEN** callbacks with an unverifiable state arrive repeatedly from one address
- **THEN** each failure is registered with the brute-force throttler

### Requirement: A relay forwards a code and never exchanges it

When a verified-shape `state` names a callback URL that is not this instance's own, the handler SHALL act as a relay: it SHALL forward the authorization code and the `state` unchanged to that URL and SHALL NOT contact the token endpoint, SHALL NOT resolve a client, and SHALL NOT mint anything. It SHALL forward only to a target whose origin appears on the instance's administrator-managed relay allow-list, and whose path is the callback path of this application. Any other target SHALL be refused.

`@e2e exclude relay forwarding needs two instances; asserted by PHPUnit on the controller, including the allow-list refusal`

#### Scenario: A relay forwards to an allow-listed tenant

- **WHEN** the relay receives a code whose state names an allow-listed instance callback
- **THEN** the browser is redirected to that callback with the code and state intact
- **AND** the relay makes no token request

#### Scenario: A relay refuses an unknown target

- **WHEN** the state names a callback on an origin that is not on the allow-list
- **THEN** the relay refuses the request and forwards nothing

#### Scenario: A forged state cannot mint anywhere

- **WHEN** a state the relay forwarded was not signed by the receiving instance
- **THEN** the receiving instance refuses it on signature verification and mints nothing

### Requirement: Re-authorisation overrides the same credential

A start that names an existing credential the caller may manage SHALL, on a successful callback, replace that credential's stored token set and metadata rather than creating a second credential. The credential's id SHALL not change, its `allowedApps` and shares SHALL be preserved, and its status SHALL return to `active`. This is how a `relink_needed` connection is repaired, so every object referencing that credentialRef keeps working.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: Reconnecting a broken connection keeps its id

- **WHEN** a credential in `relink_needed` is re-authorised
- **THEN** the same credential id is now `active` with a fresh token set
- **AND** no second credential was created for the same account

#### Scenario: A caller cannot re-authorise a credential they do not manage

- **WHEN** a start names a credential the caller may not manage
- **THEN** the start is refused

### Requirement: Disconnecting revokes upstream where it can and disables locally

`DELETE /api/credentials/oauth2/{id}` SHALL be available to a caller who may manage the credential. When the provider's catalogue entry declares a revoke endpoint, the broker SHALL call it with the stored token before deleting anything locally. It SHALL then delete the stored secret and set the credential's status to `disabled`. A failed upstream revoke SHALL NOT prevent the local disable, and SHALL be recorded as a secret-free `lastError`.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: A revoke is attempted before the local delete

- **WHEN** a connection for a provider with a revoke endpoint is disconnected
- **THEN** the revoke request is made and the stored secret is then removed

#### Scenario: An unreachable provider still disables the connection locally

- **WHEN** the revoke endpoint cannot be reached
- **THEN** the stored secret is still removed and the status becomes `disabled`

### Requirement: Bluesky is its own client and Mastodon registers per instance

For a provider whose catalogue entry declares client metadata served by the instance, the application SHALL expose that metadata at a public JSON endpoint whose URL is the client identifier, and SHALL use its own callback as the redirect target with no relay. For a provider that requires an application registered at the account's own server, the start SHALL register one at connect time, store the resulting client identifier as non-secret metadata on the credential, and store the issued client secret as a separate brokered credential referenced by `clientCredentialRef`.

`@e2e exclude both paths need a live third-party server; asserted by PHPUnit against mocked registration and metadata responses`

#### Scenario: Client metadata is served publicly and self-consistently

- **WHEN** the client metadata endpoint is fetched
- **THEN** it returns JSON whose client identifier is that endpoint's own URL and whose redirect target is this instance's callback

#### Scenario: A per-instance registration stores no secret on the object

- **WHEN** an application is registered at an account's own server
- **THEN** the credential carries the client identifier and a `clientCredentialRef`
- **AND** the client secret appears only in the custody leaf

#### Scenario: A reconnect does not register a second application

- **WHEN** a connection whose credential already carries a client identifier is re-authorised
- **THEN** no new application is registered at the account's server
- **AND** the existing client identifier and `clientCredentialRef` are reused

### Requirement: A connection records the account it speaks for

Where a provider's catalogue entry declares an identity call, the connect flow SHALL make that one call immediately after the token set is stored and SHALL record the returned account identifier, handle and display name as non-secret metadata on the credential. The call SHALL go through the broker's own constrained proxy, so it is bounded by the same allow-rules and host-lock as any other call on that credential, and the catalogue SHALL only declare an identity call that its own allow-rules already permit.

A failure of this call SHALL NOT undo or alter the connection: the credential stands, the stored token set is untouched, and the failure is logged. A connection is a working connection whether or not its label could be read.

`@e2e exclude the identity answer comes from a live third-party account; asserted by PHPUnit against a mocked broker`

#### Scenario: The handle is read once and shown on the connection

- **WHEN** a connection is minted for a provider that declares an identity call
- **THEN** the credential's `account` carries the handle the provider returned
- **AND** the connections panel shows that handle rather than a placeholder

#### Scenario: A provider that declares no identity call is not asked

- **WHEN** a connection is minted for a provider with no identity declaration
- **THEN** no additional call is made, and the connection carries the name the person gave it

#### Scenario: A failed identity call leaves a working connection alone

- **WHEN** the identity call is refused, unreachable, or answers with something that is not JSON
- **THEN** the stored token set is unchanged and the connection remains usable

### Requirement: A person can connect, see and repair a connection from personal settings

OpenRegister's personal settings SHALL offer a "Connect account" action for every catalogue provider that is an OAuth2 token set, SHALL list the caller's existing connections with a status chip reading active, expired or relink needed, and SHALL offer a Reconnect action on a connection that needs one. The panel SHALL never display a token, a client secret, or an authorization code.

`@e2e tests/e2e/credential-oauth2-connect.spec.ts`

#### Scenario: A connection that needs repair says so and offers the repair

- **WHEN** a connection's status is `relink_needed`
- **THEN** the panel shows the relink needed chip on that connection
- **AND** a Reconnect action starts the flow onto the same credential

#### Scenario: The panel shows no secret material

- **WHEN** the connections panel is rendered
- **THEN** it shows the account handle, the granted scopes and the expiry, and no token
