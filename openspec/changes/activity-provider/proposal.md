## Why

When objects are created, updated, or deleted in OpenRegister -- whether by Procest (cases), Pipelinq (leads), DocuDesk (documents), or any other consuming app -- these events are invisible in Nextcloud's Activity stream. Only Pipelinq currently publishes activities with its own provider. A centralized activity provider in OpenRegister would let all consuming apps publish lifecycle events through one shared system, eliminating duplication and ensuring consistent activity formatting across the platform.

## What Changes

- Add a new `ObjectActivityProvider` implementing `OCP\Activity\IProvider` that parses and formats activity events for display in Nextcloud's Activity stream
- Define activity subject types: `object_created`, `object_updated`, `object_deleted`, `object_assigned`, `entity_linked`, `entity_detected`, `action_performed`
- Add an `ActivityPublishListener` that listens to existing OpenRegister events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`) and publishes them to the activity manager
- Add an `ObjectActivitySetting` implementing `OCP\Activity\ISetting` for user preference control (enable/disable per activity category)
- Add an `ObjectActivityFilter` implementing `OCP\Activity\IFilter` to filter the activity stream to show only OpenRegister activities
- Use Nextcloud's rich object format for activity parameters (`{object}`, `{register}`, `{user}`, `{entity}`, `{file}`) enabling clickable, styled activity entries
- Add a new `ActivityPublishEvent` that consuming apps can dispatch to publish custom activities through OpenRegister's centralized provider, removing the need for each app to implement its own activity provider
- Leverage Nextcloud's built-in activity email digest system for email notifications (no custom email implementation)

## Capabilities

### New Capabilities
- `activity-provider`: Activity provider, filter, settings, event listener, and rich object formatting for publishing OpenRegister object lifecycle events to Nextcloud's Activity stream
- `activity-integration-api`: Public event-based API (`ActivityPublishEvent`) that consuming apps dispatch to publish custom activities through OpenRegister's centralized activity provider

### Modified Capabilities
- `event-driven-architecture`: Adds new `ActivityPublishEvent` to the existing event system and new `ActivityPublishListener` that subscribes to existing object lifecycle events

## Impact

- **New PHP classes**: `lib/Activity/ObjectActivityProvider.php`, `lib/Activity/ObjectActivitySetting.php`, `lib/Activity/ObjectActivityFilter.php`, `lib/Listener/ActivityPublishListener.php`, `lib/Event/ActivityPublishEvent.php`
- **Modified**: `lib/AppInfo/Application.php` (register provider, setting, filter, and listener via `IRegistrationContext`)
- **Dependencies**: Requires the Nextcloud `activity` app to be installed and enabled (standard Nextcloud component, no external dependencies)
- **Existing events consumed**: `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` (already dispatched by OpenRegister's mappers)
- **Deep link integration**: Uses the existing `DeepLinkRegistrationEvent` system for generating activity link URLs
- **Dependent apps**: opencatalogi, softwarecatalog, procest, pipelinq, docudesk can all benefit by dispatching `ActivityPublishEvent` instead of implementing their own providers
- **No breaking changes**: This is purely additive functionality
