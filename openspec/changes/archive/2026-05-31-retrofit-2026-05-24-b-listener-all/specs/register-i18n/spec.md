---
status: proposed
retrofit_extensions:
  - The system MUST project translatable content into the sidecar on object lifecycle events
---

# Register Internationalization — sidecar projection write-side (delta)

**Cross-references**: [register-i18n main spec](../../../../specs/register-i18n/spec.md), in-flight changes `i18n-source-of-truth` and `i18n-api-language-negotiation` (which name `TranslationProjectionService` and the `openregister_translations` sidecar table).

## Purpose of this delta

The `register-i18n` capability covers the synchronous save/render path for translatable properties (`TranslationHandler::normalizeTranslationsForSave()` / `resolveTranslationsForRender()`). This delta retroactively captures the event-driven write-side: `TranslationProjectionListener`, which keeps the `openregister_translations` sidecar in sync with the JSONB property data on each object lifecycle event. It is the projection sibling of the realtime recorder — derived-projection-by-event.

## ADDED Requirements

### Requirement: The system MUST project translatable content into the sidecar on object lifecycle events

The system MUST keep the `openregister_translations` sidecar in sync with translatable object content by reacting to object lifecycle events. `TranslationProjectionListener` MUST subscribe to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and `ObjectTransitionedEvent`. On create, update, and transition it MUST project the object's translatable content into the sidecar via `TranslationProjectionService::project($object)`; on delete it MUST remove the object's sidecar rows via `TranslationProjectionService::purge($object)`.

#### Scenario: Project on object creation
- **GIVEN** an `ObjectCreatedEvent` carrying an `ObjectEntity`
- **WHEN** `TranslationProjectionListener::handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::project($object)`

#### Scenario: Re-project on update using the new object state
- **GIVEN** an `ObjectUpdatedEvent`
- **WHEN** `handle()` processes it
- **THEN** it MUST read the new state via `getNewObject()`
- **AND** call `TranslationProjectionService::project($object)`

#### Scenario: Re-project on transition
- **GIVEN** an `ObjectTransitionedEvent` carrying an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::project($object)`

#### Scenario: Purge on deletion
- **GIVEN** an `ObjectDeletedEvent` carrying an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** it MUST call `TranslationProjectionService::purge($object)` to remove the object's sidecar translation rows

#### Scenario: Non-ObjectEntity payload is ignored
- **GIVEN** a lifecycle event whose payload is not an `ObjectEntity`
- **WHEN** `handle()` processes it
- **THEN** neither `project()` nor `purge()` MUST be called (the `instanceof ObjectEntity` guard short-circuits)

#### Notes
- The projection sidecar (`openregister_translations`) and `TranslationProjectionService` are introduced by the in-flight `i18n-source-of-truth` / `i18n-api-language-negotiation` changes; this listener is the reactive write-side that those changes rely on.
