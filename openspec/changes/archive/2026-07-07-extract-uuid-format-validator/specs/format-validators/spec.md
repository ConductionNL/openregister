## ADDED Requirements

### Requirement: A single shared UUID format validator

OpenRegister SHALL provide exactly one UUID validator in `lib/Formats/`
(`UuidFormat`). All UUID validation in `lib/` SHALL use it; no inline UUID regex
literal SHALL remain at any other call site. Supported UUID shapes (canonical
8-4-4-4-12, and any prefixed or 32-hex variant) SHALL be explicit, named options
of the validator, not divergent per-site copies.

#### Scenario: All call sites use the shared validator

- **WHEN** the codebase is scanned for the inline UUID regex literal
- **THEN** it appears only inside `lib/Formats/UuidFormat.php`

#### Scenario: Variant behaviour is preserved

- **WHEN** a call site previously accepted a prefixed or 32-hex UUID form
- **THEN** it selects the matching `UuidFormat` option and continues to accept it

### Requirement: BSN validation rejects invalid sentinels

The BSN format validator SHALL reject the all-zero BSN and any input longer than
nine digits, in addition to applying the elfproef checksum to valid nine-digit
input.

#### Scenario: All-zero BSN is rejected

- **WHEN** the value `000000000` is validated as a BSN
- **THEN** validation fails

#### Scenario: Over-length numeric input is rejected

- **WHEN** a numeric string longer than nine digits is validated as a BSN
- **THEN** validation fails (it is not silently left-padded and checksummed)

### Requirement: User datetime input is normalised centrally

Conversion of user-supplied datetime strings to `DateTime` SHALL go through
`DateTimeNormalizer`. Direct `new DateTime($value)` on request input is
prohibited.

#### Scenario: Controller date param uses the normaliser

- **WHEN** a controller parses an optional date request parameter
- **THEN** it delegates to `DateTimeNormalizer`, not `new DateTime($value)`
