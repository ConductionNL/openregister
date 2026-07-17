# Retrofit — content-versioning (rspec 2026-05-24)

Describes observed behavior of 1 method in 1 file under `content-versioning` as 1 new REQ. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Event/FileVersionRestoredEvent.php` — `__construct` (DTO carrying objectUuid, fileId, data for the typed event dispatched by `FilesController::restoreVersion()`)

## Cluster scan notes (rspec-cluster-content-versioning.json)

The 2026-05-24 reverse-spec scanner clustered 3 methods into `content-versioning`. Triage on read:

- `src/components/shared/VersionInfoCard.vue::handleUpdateClick` — **not in scope**. This is a generic Vue component that displays the **app's own version** (used by `Settings.vue` to show "Open Register vX.Y.Z" and a self-update button); it does not version register-object content. Cluster match was a false positive on the word "version".
- `src/components/shared/VersionInfoCard.vue::if` — **false positive**. The scanner picked up a Vue template `v-if` directive as a method name; no method exists.
- `lib/Event/FileVersionRestoredEvent.php::__construct` — **in scope but originally triaged DROP from REQ-017** (REQ-017 covers the restore *operation* in `FileVersioningHandler::restoreVersion()`, not the typed-event-dispatch contract the controller layer uses). On second read this is a genuine observed integration behavior worth specifying as a new REQ — the event is the integration point n8n / external listeners hook into, and REQ-017's scenarios stop at "return true on success" without mentioning the event payload contract.

## Approach

- Add one new REQ describing the observed event-dispatch contract for file-version restore. Scenarios describe what `FilesController::restoreVersion()` dispatches and what listeners observe in the event DTO.
- Do not add aspirational REQs for the UI app-version card or the parser-induced `if` false positive.

## REQ map

| REQ | Methods |
|-----|---------|
| REQ-018 | `FileVersionRestoredEvent::__construct` (DTO) + dispatch call in `FilesController::restoreVersion()` (already annotated via files capability) |

Source: `/tmp/or-scan/rspec-cluster-content-versioning.json`. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
