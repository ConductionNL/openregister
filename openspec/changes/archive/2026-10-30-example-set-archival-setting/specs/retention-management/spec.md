# retention-management Delta: example-set-archival-setting

## ADDED Requirements

### Requirement: OpenRegister ships a register for destruction lists

OpenRegister SHALL ship a `destruction-lists` register with a `destructionList` schema whose status covers every state a destruction list passes (`in_review`, `awaiting_second_approval`, `approved`, `rejected`, `completed`) and whose entries carry the reviewer, assignment moment, disposal date, classification and decision the review reads. A repair step SHALL import it under its own configuration id, version-gated, so an administrator (or an example set) can name it in the `archival` setting.

#### Scenario: The register is there after an upgrade
- **WHEN** OpenRegister's repair steps run
- **THEN** the `destruction-lists` register with the `destructionList` schema SHALL exist
