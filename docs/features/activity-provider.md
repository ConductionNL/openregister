# Activity Provider

## Standards

- **Nextcloud Activity API** -- `OCP\Activity\IProvider`, `OCP\Activity\IFilter`, `OCP\Activity\ActivitySettings`

## Overview

The Activity Provider publishes events to the Nextcloud Activity stream whenever objects, registers, or schemas are created, updated, or deleted in OpenRegister. Users see these events in the Activity app alongside file changes, calendar events, and other Nextcloud activity. The provider supports per-user notification settings and a dedicated sidebar filter.

## Key Capabilities

### 9 Event Types

The provider handles nine CRUD event subjects covering all three core entity types:

| Subject | Entity | Trigger |
|---------|--------|---------|
| `object_created` | Object | New object saved |
| `object_updated` | Object | Existing object modified |
| `object_deleted` | Object | Object removed |
| `register_created` | Register | New register created |
| `register_updated` | Register | Register modified |
| `register_deleted` | Register | Register removed |
| `schema_created` | Schema | New schema created |
| `schema_updated` | Schema | Schema modified |
| `schema_deleted` | Schema | Schema removed |

### Activity Provider (Event Rendering)

`Provider` (`lib/Activity/Provider.php`) implements `OCP\Activity\IProvider` and parses raw activity events into human-readable format. It delegates subject text generation to `ProviderSubjectHandler` for localized, rich-text event descriptions. Events are rendered with the OpenRegister app icon.

### Activity Filter (Sidebar)

`Filter` (`lib/Activity/Filter.php`) implements `OCP\Activity\IFilter` and provides a dedicated "Open Register" entry in the Activity app sidebar. It filters for three activity types: `openregister_objects`, `openregister_registers`, `openregister_schemas`.

### 3 Activity Settings (Per-User Notifications)

Three `ActivitySettings` subclasses let users control which OpenRegister events appear in their activity stream and email notifications:

| Setting | Class | Identifier | Default Stream | Default Mail |
|---------|-------|------------|---------------|-------------|
| Object changes | `ObjectSetting` | `openregister_objects` | Enabled | Disabled |
| Register changes | `RegisterSetting` | `openregister_registers` | Enabled | Disabled |
| Schema changes | `SchemaSetting` | `openregister_schemas` | Enabled | Disabled |

All three are grouped under "Open Register" in the Activity settings page.

### Event Listener

`ActivityEventListener` (`lib/Listener/ActivityEventListener.php`) implements `IEventListener` and bridges OpenRegister entity lifecycle events to the `ActivityService`. It listens for `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, and the corresponding Register and Schema events.

### ActivityService

`ActivityService` (`lib/Service/ActivityService.php`) handles the actual publishing of events to the Nextcloud Activity Manager via `IActivityManager::publish()`.

## Registration Status

**Current state**: The Activity classes are fully implemented, but registration is incomplete:

- **Not in info.xml**: No `<activity>` section declaring providers, filters, or settings. Nextcloud's standard mechanism for Activity registration uses `info.xml` entries.
- **Not in Application.php**: The `ActivityEventListener` is not registered via `registerEventListener()` in the `register()` method.
- **Partially working**: Despite missing formal registration, some OpenRegister activity events (e.g., "Request created:", "Lead created:") do appear in the Activity stream, likely published via direct `ActivityService` calls from other code paths. However, no "Open Register" filter appears in the Activity sidebar (Pipelinq has one, but OpenRegister does not).

### Required info.xml Registration

```xml
<activity>
    <providers>
        <provider>OCA\OpenRegister\Activity\Provider</provider>
    </providers>
    <filters>
        <filter>OCA\OpenRegister\Activity\Filter</filter>
    </filters>
    <settings>
        <setting>OCA\OpenRegister\Activity\Setting\ObjectSetting</setting>
        <setting>OCA\OpenRegister\Activity\Setting\RegisterSetting</setting>
        <setting>OCA\OpenRegister\Activity\Setting\SchemaSetting</setting>
    </settings>
</activity>
```

## Files

| File | Purpose |
|------|---------|
| `lib/Activity/Provider.php` | IProvider -- parses events into rich text |
| `lib/Activity/ProviderSubjectHandler.php` | Localized subject text generation |
| `lib/Activity/Filter.php` | IFilter -- sidebar filter for Activity app |
| `lib/Activity/Setting/ObjectSetting.php` | Per-user notification setting for objects |
| `lib/Activity/Setting/RegisterSetting.php` | Per-user notification setting for registers |
| `lib/Activity/Setting/SchemaSetting.php` | Per-user notification setting for schemas |
| `lib/Listener/ActivityEventListener.php` | Event listener bridging entity events to ActivityService |
| `lib/Service/ActivityService.php` | Publishes events to IActivityManager |
