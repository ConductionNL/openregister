## ADDED Requirements

### Requirement: A system entity update notifies only on a real change

When a register, schema, configuration, source or agent is updated, the
notification bridge SHALL send no notice unless the entity's content changed.
The comparison SHALL ignore `updated`, `created`, `version`, `lastChecked`,
`lastSyncDate`, `syncStatus`, `localVersion` and `remoteVersion`, SHALL treat
`null`, `''` and `[]` as equal, and SHALL ignore the order of map keys and of
lists of plain values.

#### Scenario: An app re-imports an identical configuration

- GIVEN a configuration "pipelinq example data" at version 0.5.7-unstable.20261005210000
- WHEN a newer build re-imports it with the same content and a new version
- THEN no administrator receives a notice

#### Scenario: A schema only reorders its required list

- GIVEN a schema with `required: ["title", "anonymity"]`
- WHEN it is saved with `required: ["anonymity", "title"]` and `authorization: []` for `null`
- THEN no notice is sent

#### Scenario: A schema gains a property

- GIVEN a schema with the property `title`
- WHEN it is saved with the properties `title` and `source`
- THEN the administrators receive the "updated" notice
