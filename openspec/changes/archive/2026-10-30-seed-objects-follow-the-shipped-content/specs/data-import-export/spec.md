## ADDED Requirements

### Requirement: A seed object follows its shipped content, not only its version

The import SHALL remember, per seed object, the hash of the content it last shipped (`@self` excluded, key order ignored). When a re-import ships a seed whose version is not newer:
- if the shipped content differs from the remembered hash, the import SHALL write the shipped seed over the stored object;
- if it equals the remembered hash, the import SHALL keep every value the stored object holds, and SHALL add only the shipped fields it lacks;
- with no remembered hash, the import SHALL add only the shipped fields the stored object lacks (null or absent), and SHALL keep every value it holds.

A change to the rules an import writes by SHALL invalidate the app-level fast-skip once.

#### Scenario: a status type gains its phase
- **GIVEN** a stored status type without `phase`, and no remembered hash
- **WHEN** the app ships the same version with `phase: Ontvangst`
- **THEN** the stored status type gets `phase: Ontvangst` and keeps its other values

#### Scenario: changed content without a version bump
- **GIVEN** a remembered hash of the content shipped before
- **WHEN** the app ships different content at the same version
- **THEN** the seed is written

#### Scenario: unchanged content
- **GIVEN** a remembered hash equal to the shipped content
- **WHEN** the app imports again
- **THEN** the stored object, edits included, is left alone

The import SHALL remember a seed's content hash only after the seed is written, so a refused write is tried again on the next import.

#### Scenario: a refused write is tried again
- **GIVEN** a seed whose fill-in write the store refuses
- **WHEN** the app imports again
- **THEN** the import tries the fill-in again, because no hash was remembered for it
