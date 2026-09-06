## ADDED Requirements

### Requirement: The catalogue may describe an OAuth2 provider

A provider catalogue entry MAY carry a `kind` field and, when that kind is `oauth2-token-set`, an `oauth2` block declaring the authorization endpoint, the token endpoint, the revoke endpoint where the provider has one, the refresh grant shape, the scope separator, and whether the token request authenticates with a client secret or with PKCE alone. These fields SHALL be read only from the shipped, runtime-immutable file, so an endpoint the broker will exchange a code or a refresh token at can only ever change in a reviewed release.

`@e2e exclude catalogue file contents; asserted by PHPUnit on ProviderCatalogue and by the JSON strictness check`

#### Scenario: An OAuth2 endpoint cannot be supplied at runtime

- **WHEN** a caller supplies a token endpoint in a request body
- **THEN** the broker ignores it and uses the catalogue's value

#### Scenario: An entry missing its oauth2 block cannot act as a token set

- **WHEN** an entry declares `kind: "oauth2-token-set"` but carries no `oauth2` block
- **THEN** the broker denies every call on credentials for that provider

### Requirement: Injection is selected by kind

`CredentialBrokerService::request()` SHALL resolve the value substituted into the provider's `authScheme` template through a kind-aware step: for a `secret` kind the stored secret verbatim, for an `oauth2-token-set` kind the access token in force after any refresh required by the margin. All four existing guards SHALL run before that step, unchanged and in the same order.

`@e2e exclude broker injection path; asserted by PHPUnit including a guard-ordering test`

#### Scenario: Guards still run before any refresh

- **WHEN** a caller is not admitted by the owner or allowed-app guard
- **THEN** the call is denied before any token endpoint is contacted

#### Scenario: An inject-only provider is still refused by the proxy

- **WHEN** `request()` is called for an `inject_only` provider
- **THEN** it is denied exactly as before, whatever kind the entry declares

### Requirement: A token set is never resolved app-side

`CredentialBrokerService::resolveInjectable()` SHALL refuse a credential whose catalogue entry declares the `oauth2-token-set` kind, and SHALL decide that on the kind rather than on the entry's `inject_only` flag. A refusal SHALL return the same "call `request()` instead" signal every other proxied credential returns, so a caller learns nothing about why. The stored document for this kind is a whole token set including a long-lived refresh token, and handing it to an app is the case ADR-064 decision #8 closes.

`@e2e exclude in-process resolution path with no HTTP route; asserted by PHPUnit`

#### Scenario: A token set marked inject-only is still refused

- **WHEN** an `oauth2-token-set` entry also carries `inject_only: true`
- **THEN** `resolveInjectable()` resolves nothing and `request()` denies as well, so the credential fails closed on both paths

#### Scenario: An ordinary inject-only secret is unaffected

- **WHEN** `resolveInjectable()` is called for an entry with no `kind`
- **THEN** it behaves exactly as before, so an OpenConnector source still receives its client secret
