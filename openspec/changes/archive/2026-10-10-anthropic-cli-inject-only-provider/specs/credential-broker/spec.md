# credential-broker

## ADDED Requirements

### Requirement: The catalogue registers anthropic-cli as an inject-only provider

The provider catalogue (`lib/Settings/credential-providers.json`) MUST register an `anthropic-cli`
provider carrying `inject_only: true`, and it MUST NOT carry a `baseUrl` or `allowRules`. Its secret is a
Claude Max/Pro subscription OAuth token destined for the `claude` CLI's process environment, so there is
no outbound request for the broker to proxy and no header for `injectAuth()` to substitute into — the
constrained-proxy path cannot express this credential at any host.

The entry's `$comment` MUST record that the secret leaves OpenRegister into the calling app, MUST name
the two guards that bound it (owner/IDOR and `allowedApps`), and MUST state the personal-scope-only
Terms-of-Service constraint.

#### Scenario: the entry is inject-only and unbounded by host

- **WHEN** the catalogue is read
- **THEN** `anthropic-cli` carries `inject_only: true`
- **AND** it declares no `baseUrl` and no `allowRules`

#### Scenario: the existing anthropic proxy providers are untouched

- **WHEN** `anthropic-cli` is added
- **THEN** the host-locked `anthropic` and `anthropic-oauth` entries are unchanged
- **AND** both keep their `baseUrl` and `allowRules`, so the zero-knowledge proxy path is unaffected

### Requirement: An inject-only credential is never proxied

The broker MUST refuse to proxy any `inject_only` credential, for every method and every path, and MUST
release its secret only through the app-side resolution path after both guards pass. This holds for
`anthropic-cli` by virtue of the flag alone — no provider-specific branch may be added.

#### Scenario: a proxied request against an inject-only credential is denied

- **WHEN** a caller invokes the broker's proxy path with an `anthropic-cli` credential
- **THEN** the broker denies the call and makes no outbound request
- **AND** the denial reason directs the caller to the app-side resolution path

#### Scenario: the secret is released app-side only after both guards pass

- **WHEN** the owning user's `anthropic-cli` credential grants the calling app and app-side resolution is requested
- **THEN** the raw secret is returned only after the owner/IDOR guard and the `allowedApps` guard have both passed

#### Scenario: a non-owner is refused

- **WHEN** app-side resolution is requested for a credential owned by another user, or by an app the credential does not grant
- **THEN** the broker denies and returns no secret

#### Scenario: proxy providers remain zero-knowledge

- **WHEN** app-side resolution is requested for the host-locked `anthropic-oauth` credential
- **THEN** it returns nothing, because a proxy credential's secret never leaves OpenRegister

### Requirement: A subscription credential is personal-scope only

An `anthropic-cli` credential MUST be treated as personal-scope only: a Claude Max/Pro subscription
serves its own subscriber under the Anthropic Terms of Service, so the credential may serve only its
owner and MUST be rejected at organisation scope.

This catalogue entry *declares* the constraint. Enforcement lives in the consuming app's resolution
path, which is the only place a scope decision can be made — a catalogue entry has no resolution path of
its own. Until a consumer exists, no code can use the credential, so the constraint is inert rather than
bypassed.

#### Scenario: the constraint is recorded normatively

- **WHEN** the `anthropic-cli` entry is reviewed
- **THEN** its `$comment` states that the credential is personal-scope only and must be rejected at organisation scope
- **AND** it names the Anthropic Terms of Service as the reason

#### Scenario: the API-key proxy provider is preferred where it works

- **WHEN** an operator needs pay-per-token Anthropic API access rather than a subscription
- **THEN** the host-locked `anthropic` provider is the correct choice, because it is zero-knowledge and strictly stronger
