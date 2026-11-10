# deep-link-registry

## ADDED Requirements

### Requirement: The registry MUST fill itself in a process that never booted OpenRegister

`DeepLinkRegistryService` SHALL dispatch `DeepLinkRegistrationEvent` itself the
first time it is read while empty, once per process, so a background worker
that does not load apps resolves the same deep links as a web request.
`GenericDeepLinkRegistrationListener` SHALL log a warning when the leaf app it
reads ships no `src/manifest.json`.

#### Scenario: a background worker resolves a pipelinq deep link

- **GIVEN** a process in which OpenRegister's `boot()` never ran
- **AND** pipelinq listens for `DeepLinkRegistrationEvent`
- **WHEN** the dispatcher resolves the deep link for a pipelinq client
- **THEN** the registry asks the apps once and returns `/apps/pipelinq/clients/{uuid}`
- @e2e exclude {background process; covered by tests/Unit/Service/DeepLinkRegistryBackgroundTest.php}

#### Scenario: a released app without a manifest is reported

- **GIVEN** a leaf app whose package has no `src/manifest.json`
- **WHEN** its deep link listener handles the event
- **THEN** it registers nothing and logs a warning naming the app
- @e2e exclude {server log; covered by tests/Unit/Service/DeepLinkRegistryBackgroundTest.php}
