# Activity Provider

## Problem
OpenRegister currently dispatches internal events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `RegisterCreatedEvent`, `SchemaCreatedEvent`, etc.) but does not integrate with Nextcloud's Activity app. This means users have no visibility into what has changed in their registers, schemas, or objects through Nextcloud's standard activity stream, dashboard widget, or email notifications. For a data registration platform that multiple users collaborate on, this is a significant gap: administrators cannot see who changed what, team members are unaware of new objects or schema modifications, and there is no audit-friendly timeline of changes visible in the standard Nextcloud UI.

## Proposed Solution
Implement a full Nextcloud **Activity Provider** integration for OpenRegister that:

1. **Publishes activity events** for all CRUD operations on the three core entity types: Objects (created, updated, deleted), Registers (created, updated, deleted), and Schemas (created, updated, deleted).
2. **Provides an `IProvider` implementation** that parses stored events into human-readable activity entries with rich subject parameters (clickable entity names, user references).
3. **Provides an `IFilter` implementation** so users can filter the activity stream to show only OpenRegister events.
4. **Provides `ActivitySettings` subclasses** so users can configure which OpenRegister activity types they want to see in their stream and receive via email notifications.
5. **Publishes events via a dedicated `ActivityService`** that listens to OpenRegister's existing `EventDispatcher` events, translating them into Nextcloud Activity events with proper metadata (author, affected user, object type/ID, timestamp, link).
6. **Registers all activity components** via `info.xml` `<activity>` declarations (provider, settings, filter) following Nextcloud conventions.
7. **Supports i18n** with Dutch and English translations for all activity subjects and settings per ADR-005.

This uses the standard `OCP\Activity\IManager`, `OCP\Activity\IProvider`, `OCP\Activity\IFilter`, and `OCP\Activity\ActivitySettings` APIs (available since NC 11+/NC 20+).
