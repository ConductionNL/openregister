## Purpose

Gives any OpenRegister schema a declarative, append-only, evidentiary consent shape it can attach to one of its own array properties via `x-openregister-consent`, so that proving who consented to what, when, from where, and on what device is a platform guarantee instead of a per-app reimplementation.

## ADDED Requirements

### Requirement: A schema MAY declare a property as consent-shaped via `x-openregister-consent`
A schema MUST be allowed to annotate a property of `type: array` with `x-openregister-consent: {purpose: string, subjectProperty?: string}`. `purpose` identifies, in a fixed string, what is being consented to. `subjectProperty`, when present, names another property on the same object holding the data subject's identifier; when absent, the data subject is the acting user. Schema-save validation MUST reject a declaration on a non-array property, and MUST reject a declaration missing `purpose`, both with HTTP 422.

#### Scenario: Valid consent declaration on an array property
- **WHEN** a schema declares property `beeldmateriaalConsent` with `type: array` and `x-openregister-consent: {purpose: "beeldmateriaal-gebruik"}`
- **THEN** the schema save MUST succeed

#### Scenario: Declaration on a non-array property is rejected
- **WHEN** a schema declares property `consentGiven` with `type: boolean` and `x-openregister-consent: {purpose: "beeldmateriaal-gebruik"}`
- **THEN** the schema save MUST fail with HTTP 422 naming the property and the reason ("must be type array")

#### Scenario: Declaration missing purpose is rejected
- **WHEN** a schema declares property `consentLog` with `type: array` and `x-openregister-consent: {}`
- **THEN** the schema save MUST fail with HTTP 422 naming the missing `purpose` key

### Requirement: The system MUST fill evidentiary fields on a new consent entry
When an object write appends an entry to a consent-shaped array property, the system MUST fill fields the caller does not (and cannot) supply itself: `by` (the acting user id, or the resolved `subjectProperty` identifier when the caller writes on a data subject's behalf), `timestamp` (server clock, RFC3339, at the moment of write), `ip` (the caller's remote address), `userAgent` (the caller's `User-Agent` request header), and `contentHash` (a SHA-256 hash computed over the declared `purpose`, the entry's `decision`, and the caller-supplied `evidenceOf` string). A caller-supplied value for any of these five fields MUST be silently overwritten by the platform-computed one — a caller cannot forge evidence.

#### Scenario: Granting consent fills evidence fields
- **GIVEN** schema `Guardian` declares `beeldmateriaalConsent` as consent-shaped with `purpose: "beeldmateriaal-gebruik"`
- **WHEN** an authenticated guardian `guardian-42` creates an object with `beeldmateriaalConsent: [{subject: "learner-7", decision: "granted", evidenceOf: "beeldmateriaal-terms-v3"}]` from IP `203.0.113.5` with User-Agent `Mozilla/5.0 (…)`
- **THEN** the persisted entry MUST include `by: "guardian-42"`, a server-generated `timestamp`, `ip: "203.0.113.5"`, `userAgent: "Mozilla/5.0 (…)"`, and a `contentHash` computed from `purpose` + `decision` + `evidenceOf`

#### Scenario: A caller-supplied evidentiary field is overwritten, not trusted
- **WHEN** the same create call also supplies `timestamp: "2000-01-01T00:00:00Z"` and `ip: "10.0.0.1"` on the appended entry
- **THEN** the persisted entry's `timestamp` MUST be the server's write-time clock (not `2000-01-01T00:00:00Z`) and `ip` MUST be the caller's actual remote address (not `10.0.0.1`)

### Requirement: An existing consent entry MUST NOT be mutated in place
Once a consent-shaped array entry is persisted, no subsequent write MUST be able to change or remove it. Only appending new entries beyond the currently persisted length is permitted. A withdrawal MUST be expressed as a new appended entry with `decision: "withdrawn"` and its own `withdrawnAt` timestamp equal to its own write-time clock — the original `granted` (or `refused`) entry is never edited.

#### Scenario: Editing an existing entry is refused
- **GIVEN** object `guardian-record-1` has one persisted `beeldmateriaalConsent` entry at index 0 with `decision: "granted"`
- **WHEN** an update attempts to change index 0's `decision` to `"refused"`
- **THEN** the update MUST be refused with HTTP 422 identifying the property and the offending index
- **AND** the persisted entry at index 0 MUST remain unchanged

#### Scenario: Removing an existing entry is refused
- **GIVEN** object `guardian-record-1` has two persisted `beeldmateriaalConsent` entries
- **WHEN** an update submits an array containing only one entry (matching index 0)
- **THEN** the update MUST be refused with HTTP 422 — shortening the array drops a persisted entry, which is a mutation

#### Scenario: Withdrawal appends rather than edits
- **GIVEN** object `guardian-record-1` has one persisted `beeldmateriaalConsent` entry at index 0 with `decision: "granted"`
- **WHEN** an update appends a new entry at index 1 with `decision: "withdrawn"`
- **THEN** the update MUST succeed
- **AND** the entry at index 0 MUST remain unchanged with its original `decision: "granted"`
- **AND** the entry at index 1 MUST carry a `withdrawnAt` equal to its own write-time server clock

#### Scenario: Appending a new entry beyond the persisted length is allowed
- **GIVEN** object `guardian-record-1` has one persisted `beeldmateriaalConsent` entry
- **WHEN** an update submits the unchanged first entry plus one new entry at index 1
- **THEN** the update MUST succeed and both entries MUST be persisted
