# example-sets Delta: example-set-archival-setting

## ADDED Requirements

### Requirement: OpenRegister's fragment may name the destruction-list register and schema

OpenRegister's own fragment of a set MAY declare an `archival` part: rows with a key and `data` holding `destructionListRegister` and/or `destructionListSchema`. Install SHALL merge only those keys into OpenRegister's `archival` setting, SHALL keep every other key of it, and SHALL record what each key held before. The proof SHALL name a written key that no longer holds the set's value. Remove SHALL put back what each key held (or drop a key that was not there, and the whole setting when the set made it from nothing), but SHALL leave a key whose value somebody changed since. Any other key, or a value that does not resolve, SHALL refuse the set before anything is written. A fragment of another app declaring the part SHALL refuse the set.

#### Scenario: The keys are merged and put back
- **GIVEN** an `archival` setting with `notificationLeadDays` 14 and `destructionListRegister` 7
- **WHEN** the set naming the destruction-list register and schema is installed and later removed
- **THEN** after install the setting SHALL hold `notificationLeadDays` 14 and the register's and schema's ids; after remove it SHALL hold `notificationLeadDays` 14 and `destructionListRegister` 7 again

#### Scenario: An unknown key refuses the set
- **GIVEN** an `archival` part with `destructionBatchSize`
- **WHEN** the set is checked
- **THEN** the set SHALL be refused with that key named, and nothing SHALL be written
