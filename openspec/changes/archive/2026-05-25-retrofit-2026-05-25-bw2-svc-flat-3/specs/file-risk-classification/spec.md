---
retrofit: true
---

# File Risk Classification

## Why

`RiskLevelService` turns the PII entities that text-extraction detects on a
file into a single, persisted risk tier and exposes it through Nextcloud's
files-metadata system for use in the UI and API. This classification concern
is distinct from entity detection (text-extraction), GDPR processing-register
bookkeeping (`avg-verwerkingsregister`), and anonymisation: it is the scoring
+ metadata-persistence layer that sits between them, and no existing
capability owns it. This new capability anchors the observed behaviour. Code
already exists in production; this is an annotation-only reverse-spec.

## ADDED Requirements

### Requirement: RiskLevelService MUST classify a file's PII risk from its detected entities

`RiskLevelService::computeRiskLevel(int $fileId)` MUST derive a single risk tier for a file from the entities that text-extraction detected on it. The method MUST load the file's entity relations via `EntityRelationMapper::findEntitiesForFile()` and MUST return `RISK_NONE` ('none') when no entities are present. For each detected entity it MUST map the entity type to a base tier via the fixed `ENTITY_RISK_MAP` — `SSN` → `very_high`; `EMAIL` and `IBAN` → `high`; `PERSON`, `PHONE`, `ADDRESS` → `medium`; `LOCATION`, `ORGANIZATION`, `DATE`, `IP_ADDRESS` → `low` — with any unrecognised entity type defaulting to `low`. The file's base risk MUST be the highest tier observed across all its entities, compared using the `RISK_ORDER` ranking (`none` < `low` < `medium` < `high` < `very_high`).

When the total number of detected entities exceeds `ESCALATION_THRESHOLD` (50) and the base tier is not already `very_high`, the result MUST be escalated by exactly one tier. The risk tier MUST never exceed `very_high`.

#### Scenario: The highest-risk entity type determines the base tier

- **GIVEN** a file with entities of types `LOCATION`, `PERSON`, and `EMAIL`
- **WHEN** `computeRiskLevel($fileId)` is called
- **THEN** the result MUST be `high` (the `EMAIL` tier, which outranks `PERSON` and `LOCATION`)

#### Scenario: A file with no entities is classified as none

- **GIVEN** a file for which `EntityRelationMapper::findEntitiesForFile()` returns an empty array
- **WHEN** `computeRiskLevel($fileId)` is called
- **THEN** the result MUST be `none`

#### Scenario: High entity volume escalates the tier by one

- **GIVEN** a file with 60 detected entities whose highest tier is `medium`
- **WHEN** `computeRiskLevel($fileId)` is called
- **THEN** the result MUST be escalated to `high`

#### Scenario: Escalation never exceeds very_high

- **GIVEN** a file with 60 detected entities whose highest tier is already `very_high`
- **WHEN** `computeRiskLevel($fileId)` is called
- **THEN** the result MUST remain `very_high`

### Requirement: RiskLevelService MUST persist and expose risk levels through Nextcloud files metadata

The computed risk level MUST be stored on the file using Nextcloud's `IFilesMetadata` API under the `METADATA_KEY` (`openregister-risk-level`). `RiskLevelService::updateRiskLevel(int $fileId)` MUST compute the level via `computeRiskLevel()`, write it as an indexed string metadata value, and return the computed level; a metadata write failure MUST be caught and logged at `warning` level WITHOUT propagating (the computed level is still returned). `RiskLevelService::getRiskLevel(int $fileId)` MUST return the stored level when the metadata key is present and MUST fall back to `RISK_NONE` ('none') when the key is absent or the metadata read throws. `RiskLevelService::initMetadataKey()` MUST register the metadata key with the files-metadata system as an indexed, edit-forbidden string type, and MUST be invoked from a repair step rather than during app boot. `RiskLevelService::getAllRiskLevels()` MUST return the map of every risk-level constant to its human-readable label for use in API documentation and frontend dropdowns.

#### Scenario: updateRiskLevel returns the level even when persistence fails

- **GIVEN** `IFilesMetadataManager::saveMetadata()` throws for a file
- **WHEN** `updateRiskLevel($fileId)` is called
- **THEN** a `warning` log MUST be emitted
- **AND** the computed risk level MUST still be returned
- **AND** no exception MUST propagate

#### Scenario: getRiskLevel falls back to none when no level is stored

- **GIVEN** a file whose metadata does not contain the `openregister-risk-level` key
- **WHEN** `getRiskLevel($fileId)` is called
- **THEN** the result MUST be `none`

#### Scenario: getAllRiskLevels enumerates every tier with a label

- **WHEN** `RiskLevelService::getAllRiskLevels()` is called
- **THEN** the result MUST contain keys `none`, `low`, `medium`, `high`, `very_high`
- **AND** each MUST map to a non-empty human-readable label
