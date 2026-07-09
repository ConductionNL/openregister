## Purpose

Delta against the `credential-broker` capability: the runtime-immutable provider
catalogue (`lib/Settings/credential-providers.json`) gains a `doffin` entry so the
Doffin (Norway public procurement) subscription key can be held once by the broker.
The catalogue's own requirement (runtime-immutable `lib/` file, no mutation API,
entries change only via reviewed release) is unchanged — this delta is one such
reviewed release. Note: the base `credential-broker` spec still lives in the active
head change (`openspec/changes/credential-broker/specs/credential-broker/spec.md`);
`openspec/specs/credential-broker/` does not exist yet.

## ADDED Requirements

### Requirement: Doffin provider entry

The provider catalogue SHALL include a `doffin` entry declaring the Doffin Public
API as a host-locked, GET-only provider. The entry SHALL declare `baseUrl`
`https://betaapi.doffin.no/public/v2`, an `authScheme` injecting the header
`Ocp-Apim-Subscription-Key` whose template is exactly the `{secret}` placeholder
(no prefix), and `allowRules` containing ONLY GET rules limited to the
notice-search path used by the spectr ingestion connector (`/notices`). No rule
for the `doffin` provider SHALL permit a non-GET method, and the entry SHALL be
mutable only through a reviewed release like every other catalogue entry.

#### Scenario: Doffin entry present and host-locked

- **WHEN** the provider catalogue is loaded
- **THEN** a `doffin` entry exists with `identifier` `doffin` and `baseUrl`
  `https://betaapi.doffin.no/public/v2`
- **AND** any brokered call for a `doffin` credential resolves only to the
  `betaapi.doffin.no` host (host-lock guard)

#### Scenario: Subscription-key header injected

- **WHEN** the broker performs a permitted call for a `doffin` credential whose
  stored secret is `YOUR_API_KEY_HERE`
- **THEN** the outbound request carries the header
  `Ocp-Apim-Subscription-Key: YOUR_API_KEY_HERE`
- **AND** any caller-supplied `Ocp-Apim-Subscription-Key` header is discarded
  before injection

#### Scenario: Notice search allowed with query parameters

- **WHEN** a brokered call requests `GET /notices?cpvCodes=48000000&pageNumber=1&pageSize=25`
- **THEN** the query string is stripped for rule matching and the `GET /notices`
  allow-rule matches, so the call proceeds

#### Scenario: Non-GET and unlisted paths are denied

- **WHEN** a brokered call requests `POST /notices`, or `GET` on any path other
  than `/notices`
- **THEN** no `doffin` allow-rule matches and the broker denies the call with the
  static 403, failing closed
