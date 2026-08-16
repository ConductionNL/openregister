# Deprecate Published/Depublished Metadata

## Standards

- Internal architectural cleanup

## Overview

This change removes object-level published/depublished date fields from OpenRegister. Previously, schemas could configure `objectPublishedField`, `objectDepublishedField`, and `autoPublish` keys to automatically stamp objects with publication timestamps. This pattern has been replaced by RBAC authorization rules using the `$now` dynamic variable, which provides more flexible and declarative publication control.

For example, instead of setting a `published` date on an object, access control now uses rules like:

```json
{
  "read": [{
    "group": "public",
    "match": {
      "publicatieDatum": { "$lte": "$now" }
    }
  }]
}
```

This approach separates access control from object data, allowing publication logic to be managed through the existing RBAC system without polluting object schemas with metadata fields.

## What Was Removed

### ImportService Publish Logic

The `addPublishedDateToObjects` method has been fully removed from `ImportService`. Grep confirms zero matches for this method anywhere in `lib/`. Import operations no longer automatically stamp objects with publication dates.

### Frontend Stats Displays

Published count displays have been cleaned from stats views. A grep for "published" in Stats-related Vue components returns zero matches, confirming the removal from dashboard/overview statistics.

### Copy Modal Cleanup

Copy/clone modals no longer carry forward published/depublished metadata when duplicating objects.

## What Was Preserved

### File-Level autoPublish (Different Concept)

The `autoPublish` key still exists in `FilePropertyHandler` (`lib/Service/Object/SaveObject/FilePropertyHandler.php`) but this refers to **file sharing** (whether uploaded files are automatically shared/published via Nextcloud sharing), not object-level publication metadata. This is intentionally preserved as it serves a different purpose.

### Register/Schema Published Status

The `published` and `depublished` fields on **Registers and Schemas themselves** (as opposed to objects within them) are preserved. These control whether a register or schema is visible/active, which is a different concern from object-level publication dates. The `RegisterSchemaCard.vue` component still shows published/depublished badges for these entities.

### File Published Status in ViewObject

The `ViewObject.vue` modal retains published/unpublished filtering for **file attachments** (tracking which files have been shared). This is Nextcloud file sharing status, not object publication metadata.

## Deprecation Warnings

The `MetadataHydrationHandler` (`lib/Service/Object/SaveObject/MetadataHydrationHandler.php`) actively detects and warns when schemas still use deprecated configuration keys:

```php
$deprecatedKeys = ['objectPublishedField', 'objectDepublishedField', 'autoPublish'];
foreach ($deprecatedKeys as $key) {
    if (isset($config[$key]) === true) {
        $this->logger->warning(
            message: "[MetadataHydrationHandler] Schema configuration key '{$key}' is deprecated. "
                . 'Object-level published/depublished metadata has been removed. '
                . 'Use RBAC authorization rules with $now for publication control. '
                . 'Example: {"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}',
        );
    }
}
```

This ensures administrators are informed during runtime if legacy configuration keys are still present, guiding migration to the RBAC approach.

## Verification Summary

| Check                                          | Result  |
|------------------------------------------------|---------|
| `addPublishedDateToObjects` in lib/            | Not found (removed) |
| Published count in Stats Vue components        | Not found (removed) |
| Deprecation warnings for config keys           | Present in MetadataHydrationHandler |
| File-level autoPublish preserved               | Yes (FilePropertyHandler, different concept) |
| Register/Schema published status preserved     | Yes (entity-level, different concern) |
