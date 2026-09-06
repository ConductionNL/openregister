## ADDED Requirements

### Requirement: A store MUST declare its discovery source, defaulting to OpenRegister

The `store` block MAY carry a `source` key. Its value MUST be one of
`openregister` or `github`. When the key is absent the source MUST be
`openregister`, so every store declared before this change keeps its behaviour
exactly.

An unknown `source` MUST be treated as a malformed block: the manifest is
DISABLED and the store does not appear. Falling back to `openregister` would
silently point an app at a registry it never asked for.

#### Scenario: An absent source defaults to OpenRegister

- **WHEN** a `store` block declares no `source`
- **THEN** the manifest reports source `openregister`
- **AND** discovery uses the registry URL configured for the app

#### Scenario: An unknown source disables the store

- **WHEN** a `store` block declares `"source": "npm"`
- **THEN** the manifest reports `enabled: false`
- **AND** no store entry may be rendered for that app

### Requirement: A GitHub source MUST discover by topic against a compile-time host

When `source` is `github` the block MUST carry a non-empty `topics` list. Each
topic is searched as `topic:<topic>` against the GitHub repository search API.

The host MUST be a compile-time constant. A `github` source MUST NOT read the
app's `registry_url`, and an app MUST NOT be able to influence the host it
reaches. This is deliberately stricter than the `openregister` source, where the
URL is admin-configured and therefore SSRF-guarded at request time: here there
is no URL to guard.

A `github` source MUST NOT be reported as `not_configured` for want of a
registry URL. It is configured by its topics.

#### Scenario: Topics are searched and results merged

- **WHEN** a store declares `"source": "github"` with two topics
- **THEN** each topic is searched
- **AND** the results are merged into one card list, de-duplicated by
  `owner/repo`

#### Scenario: A GitHub source with no topics is malformed

- **WHEN** a store declares `"source": "github"` and an empty `topics` list
- **THEN** the manifest reports `enabled: false`

### Requirement: Search MUST distinguish rate limiting from unreachability

The outcome vocabulary gains `rate_limited`. A source that answers with an
explicit rate-limit signal MUST map to `rate_limited`, never to
`store_unreachable`.

The two are not interchangeable to a reader: `rate_limited` means wait, or add
a credential to raise the limit; `store_unreachable` means the network or the
registry is broken. Reporting the first as the second sends someone to debug a
network that is fine.

A rate-limited or unreachable search MUST still answer HTTP 200 with an empty
card list, as today. A store that cannot reach its registry is not a server
error in the app that hosts the page.

#### Scenario: GitHub rate limiting is reported as itself

- **WHEN** the GitHub API answers 403 with a rate-limit signal
- **THEN** the outcome is `rate_limited`
- **AND** the HTTP status is 200
- **AND** the card list is empty

#### Scenario: A network failure stays unreachable

- **WHEN** the request to the source throws a connection error
- **THEN** the outcome is `store_unreachable`

### Requirement: Discovery MUST be cached per source

A source MUST declare a cache lifetime for search results, and the engine MUST
NOT issue an upstream request while a cached answer for the same query is live.

Without this, every keystroke past the debounce is an upstream call, and the
GitHub search API's unauthenticated limit is low enough that a single user
typing exhausts it — turning a working store into a `rate_limited` one for
everybody on the instance.

#### Scenario: A repeated query inside the cache window issues no request

- **WHEN** the same query is searched twice inside the cache lifetime
- **THEN** exactly one upstream request is made
- **AND** both answers carry the same cards
