## ADDED Requirements

### Requirement: A re-authorised credential returns to active in place

A credential in any status MAY be returned to `active` by a successful re-authorisation that writes a new token set under the same credential id. The re-authorisation SHALL clear `lastError`, SHALL set `expiresAt`, `scopes` and `account` from the new token response, and SHALL leave `allowedApps`, shares, `kind`, `scope` and `organisation` unchanged. A credential's `provider` and, where one is pinned, its `instanceBaseUrl` SHALL NOT change on re-authorisation; a re-authorisation naming a different provider or host SHALL be refused.

`@e2e exclude the in-place override is asserted end to end by the connect spec; the invariants here are asserted by PHPUnit`

#### Scenario: Re-authorisation preserves grants and identity

- **WHEN** a credential with two entries in `allowedApps` is re-authorised
- **THEN** both entries survive and the credential is `active`

#### Scenario: Re-authorisation cannot re-point a credential

- **WHEN** a re-authorisation would change the credential's provider or its pinned instance host
- **THEN** it is refused and the stored token set is unchanged
