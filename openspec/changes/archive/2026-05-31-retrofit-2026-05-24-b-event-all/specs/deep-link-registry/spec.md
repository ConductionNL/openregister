## MODIFIED Requirements

### Requirement: Apps SHALL register deep link patterns via boot-time events

Consuming Nextcloud apps SHALL be able to register URL patterns for OpenRegister schema/register combinations via the `DeepLinkRegistryService`. A registration maps a (register, schema) pair to a URL template and optional icon, so that OpenRegister can generate URLs pointing to the consuming app's detail view instead of its own. Registration is event-driven: OpenRegister dispatches a `DeepLinkRegistrationEvent` during its `Application::boot()` phase. Consuming apps listen for this event and call `register()` on the provided `DeepLinkRegistryService` (or use the convenience `register()` method on the event itself). The event MUST expose the wrapped registry service via `getRegistry()` so listeners can interact with the registry directly.

**Key classes:**
- `OCA\OpenRegister\Service\DeepLinkRegistryService` -- In-memory registry with `register()`, `resolve()`, `resolveUrl()`, `resolveIcon()`, `hasRegistrations()`, `reset()` methods
- `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` -- Event dispatched during boot; wraps the registry service
- `OCA\OpenRegister\Dto\DeepLinkRegistration` -- Value object storing a single registration (appId, registerSlug, schemaSlug, urlTemplate, icon)

#### Scenario: Pipelinq registers deep link patterns for CRM schemas
- **GIVEN** Pipelinq is installed alongside OpenRegister
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent` during `Application::boot()`
- **THEN** Pipelinq's `DeepLinkRegistrationListener` registers four patterns: `client`, `lead`, `request`, `contact` in the `pipelinq` register
- **AND** each registration uses the URL template format `/apps/pipelinq/#/clients/{uuid}` (hash-based Vue Router routes)

#### Scenario: Procest registers deep link patterns for case management schemas
- **GIVEN** Procest is installed alongside OpenRegister
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent` during `Application::boot()`
- **THEN** Procest's `DeepLinkRegistrationListener` registers two patterns: `case` and `task` in the `case-management` register
- **AND** each registration uses the URL template format `/apps/procest/#/cases/{uuid}` and `/apps/procest/#/tasks/{uuid}`

#### Scenario: Multiple apps register for different schemas in the same register
- **GIVEN** both Procest and a hypothetical audit app are installed
- **WHEN** Procest registers for `case-management::case` and the audit app registers for `case-management::audit-log`
- **THEN** both registrations coexist and the correct app is resolved per schema

#### Scenario: Duplicate registration for same (register, schema) pair is silently ignored
- **GIVEN** Procest has already registered a deep link for `case-management::case`
- **WHEN** a second app attempts to register for the same `case-management::case` pair
- **THEN** the duplicate registration is silently ignored (first-come-first-served)
- **AND** a debug log message is emitted: `[DeepLinkRegistry] Ignoring duplicate registration for {key} from {appId} (already claimed by {existing})`

#### Scenario: App that is disabled stops registering deep links
- **GIVEN** Procest was previously registered for `case-management::case`
- **WHEN** Procest is disabled by the admin
- **THEN** on the next request, Procest's boot listener does not fire
- **AND** the `case-management::case` pair has no registration, so search results fall back to OpenRegister's default URL

#### Scenario: Listener obtains the registry service from the event
- **GIVEN** a consuming app's `DeepLinkRegistrationListener` receives a `DeepLinkRegistrationEvent`
- **WHEN** the listener calls `getRegistry()` on the event
- **THEN** it MUST receive the live `DeepLinkRegistryService` instance
- **AND** calling `register()` on that service MUST be equivalent to calling the event's convenience `register()` method
