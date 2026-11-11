# Proposal: the configuration preview compares what the import writes

## Why

The live pass of 5 Oct (defect O2) previewed a newer version of a seeded configuration that changed only `colour`. The preview listed three changes: `@self.version 0.0.1 -> 1.0.1`, `slug null -> "livepass-lane11-a1"` and `colour red -> blue`. The import strips the seed's top-level `slug` and `uuid` from the data (#4315), so the stored object never holds them, and its `@self.version` is OpenRegister's own counter, so both rows appear on every seeded object and say nothing.

## What changes

- `PreviewHandler::previewObjectChange()` removes `@self.version` from both sides after the version gate, and removes the top-level `uuid` and `slug` from the proposal unless the schema declares them, before comparing.

## Impact

- `lib/Service/Configuration/PreviewHandler.php`. The preview modal shows only real changes; the update/skip decision is unchanged.
