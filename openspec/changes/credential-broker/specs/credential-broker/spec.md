## ADDED Requirements

### Requirement: Credential metadata schema

OpenRegister SHALL define a `credential` schema whose objects hold owner-scoped
metadata for a stored secret and MUST NOT contain the secret value. Each object SHALL
carry `name` (string), `provider` (a catalogue provider identifier), `owner` (the owning
user's OR-resolved identity), `allowedApps` (array of app ids), and `createdAt`
(ISO-8601 timestamp). The secret itself SHALL live in the Nextcloud vault keyed by the
credential's UUID, never in any `credential` object property.

#### Scenario: Credential schema declared in the register descriptor

- **WHEN** the `credential-broker` register descriptor (`lib/Settings/credential_broker_register.json`) is read
- **THEN** it declares the `credential` schema with properties `name`, `provider`, `owner`, `allowedApps`, `createdAt`
- **AND** the schema declares no property that stores a secret/token value
- **AND** the descriptor is materialised into OpenRegister by the `credential-broker-service` Repair step (OR does not self-import its own register JSON at boot, ADR-037)

#### Scenario: Metadata never carries a secret

- **WHEN** a `credential` object is inspected via list, export, audit, or GraphQL
- **THEN** no secret or token value is present in the object
- **AND** only the metadata handle (`name`, `provider`, `owner`, `allowedApps`, `createdAt`) is returned

### Requirement: Provider catalogue as a runtime-immutable lib file

OpenRegister SHALL ship the curated provider catalogue as a runtime-immutable JSON file in
`lib/` (`lib/Settings/credential-providers.json`), NOT as an OR schema and NOT as
register-seeded objects. Each entry SHALL declare `identifier` (slug), `title`, `baseUrl`
(the host-locked API base), `authScheme` (an object with the header name and a `template`
containing the `{secret}` placeholder), and `allowRules` (an array of `{ method, pathPattern }`
entries defining the permitted HTTP method + path-prefix patterns for the constrained proxy).
The catalogue SHALL be read-only at runtime — there SHALL be no API that creates, updates, or
deletes a provider or an allow-rule (the allow-rules are a security control and MUST NOT be
widenable without a code review + release). The catalogue SHALL include `github` and `gitlab`
entries, each host-locked with allow-rules scoped to the minimum methods and path prefixes needed.

#### Scenario: Catalogue shipped and read-only

- **WHEN** OpenRegister loads the provider catalogue
- **THEN** it reads `lib/Settings/credential-providers.json` from disk, with each entry declaring
  `identifier`, `title`, `baseUrl`, `authScheme`, and `allowRules`
- **AND** no API exists to create, update, or delete a catalogue provider or allow-rule

#### Scenario: Provider declares host-lock and auth scheme

- **WHEN** a catalogue entry is read
- **THEN** its `baseUrl` defines the single host the broker is permitted to call
- **AND** its `authScheme.template` contains a `{secret}` placeholder for secret injection

#### Scenario: GitHub entry present

- **WHEN** the catalogue is loaded
- **THEN** a `github` entry with `baseUrl` `https://api.github.com` and an
  `Authorization: token {secret}` auth scheme exists
- **AND** its `allowRules` permit `PUT /repos/*/contents/*` and `GET /repos/*`

#### Scenario: GitLab entry present

- **WHEN** the catalogue is loaded
- **THEN** a `gitlab` entry with `baseUrl` `https://gitlab.com/api/v4` and a `Bearer {secret}`
  auth scheme exists

### Requirement: Seeded secret-less example credentials

OpenRegister SHALL seed example `credential` metadata objects using general
organisation data (municipality, travel agency) so the capability is testable on a
fresh install. Seed credentials SHALL carry only metadata and MUST use safe placeholder
values (nil UUID for `owner`); no secret is seeded for them.

#### Scenario: Example credentials present after install

- **WHEN** the register import completes
- **THEN** at least two `credential` objects exist, each referencing a seeded provider
- **AND** each seeded credential contains no secret value and uses the nil-UUID `owner` placeholder

### Requirement: App manifest declares provider usage

The shared app-manifest schema (`@conduction/nextcloud-vue`) SHALL gain an optional,
additive `credentials` array. Each entry SHALL declare `provider` (a catalogue provider
identifier), `reason` (human-readable string), and `scopes` (advisory
string array). The addition MUST be back-compatible — existing manifests without a
`credentials` field SHALL keep validating.

#### Scenario: Manifest with credentials validates

- **WHEN** an app manifest declares `credentials: [{ provider: "github", reason: "publish skills", scopes: ["repo"] }]`
- **THEN** `npm run check:manifest` validates the manifest successfully

#### Scenario: Manifest without credentials still validates

- **WHEN** an existing app manifest has no `credentials` field
- **THEN** the manifest continues to validate against the updated schema
