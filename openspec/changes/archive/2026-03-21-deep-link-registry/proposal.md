# Deep Link Registry

## Problem
The Deep Link Registry enables consuming Nextcloud apps (Procest, Pipelinq, OpenCatalogi, etc.) to claim ownership of specific OpenRegister (register, schema) combinations by registering URL templates at boot time. When Nextcloud's unified search returns objects belonging to a claimed combination, results link directly to the consuming app's detail view instead of OpenRegister's generic object view. This decouples object storage (OpenRegister) from object presentation (consuming apps), allowing each app to own its user experience while sharing a common data layer.
The registry is event-driven and in-memory only: OpenRegister dispatches a `DeepLinkRegistrationEvent` during `Application::boot()`, consuming apps listen and call `register()`, and the resulting mappings are used by `ObjectsProvider` (the unified search provider) to resolve URLs and icons for the current request cycle.

## Proposed Solution
Implement Deep Link Registry following the detailed specification. Key requirements include:
- Requirement: Apps SHALL register deep link patterns via boot-time events
- Requirement: Deep link registry SHALL resolve URLs for unified search results
- Requirement: Registration SHALL use slugs not database IDs
- Requirement: URL templates SHALL support placeholder-based URL generation
- Requirement: Registry SHALL be in-memory only without database persistence

## Scope
This change covers all requirements defined in the deep-link-registry specification.

## Success Criteria
- Pipelinq registers deep link patterns for CRM schemas
- Procest registers deep link patterns for case management schemas
- Multiple apps register for different schemas in the same register
- Duplicate registration for same (register, schema) pair is silently ignored
- App that is disabled stops registering deep links
