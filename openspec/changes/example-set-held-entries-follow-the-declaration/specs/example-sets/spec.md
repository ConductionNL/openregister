## ADDED Requirements

### Requirement: A held change or preference follows its declaration

A change the record holds SHALL be proved and removed only on the fields its declaration still sets. On `--refresh`, a held change SHALL write a declared field again in two cases: the field holds the value the change replaced, or it holds the value the change wrote while the declaration resolves to a new one. It SHALL leave any other value. On `--refresh`, a held user preference SHALL be written again when its declaration resolves to a value other than the one the set last wrote. The value it first replaced SHALL stay recorded.

#### Scenario: a field a job owns now
- **GIVEN** a held change that set `status`, and a declaration that no longer sets it
- **WHEN** a job changes `status` and the set is refreshed
- **THEN** the install reports nothing lost, and the job's value stays

#### Scenario: an import undid a change
- **GIVEN** a held change from name "Fase 3" to "Oplevering"
- **WHEN** an import puts "Fase 3" back and the set is refreshed
- **THEN** the name is "Oplevering" again, and the change reports refreshed

#### Scenario: a view written again
- **GIVEN** a preference that quotes a view of its fragment
- **WHEN** a refresh writes the view again under a new uuid
- **THEN** the preference quotes the new uuid
