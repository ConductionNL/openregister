<!-- Example output — opsx-bulk-archive for 3 changes -->

Getting active changes...

Active changes: add-webhook-support, fix-pagination-format, add-csv-export

> User selected: All 3 changes

---

### Gathering status for all 3 changes...

**add-webhook-support**
- Artifacts: proposal ✓ | specs ✓ | design ✓ | tasks ✓
- Tasks: 6/6 `[x]`
- Delta specs: `specs/webhooks/spec.md`

**fix-pagination-format**
- Artifacts: proposal ✓ | specs ✓ | design ✓ | tasks ✓
- Tasks: 3/3 `[x]`
- Delta specs: `specs/api-standards/spec.md`

**add-csv-export**
- Artifacts: proposal ✓ | specs ✓ | design ✓ | tasks ✓
- Tasks: 4/4 `[x]`
- Delta specs: `specs/export/spec.md`

---

### Detecting spec conflicts...

```
webhooks       → [add-webhook-support]      ← OK (only 1 change)
api-standards  → [fix-pagination-format]    ← OK (only 1 change)
export         → [add-csv-export]           ← OK (only 1 change)
```

No conflicts detected.

---

### Status Summary

| Change | Artifacts | Tasks | Specs | Conflicts | Status |
|--------|-----------|-------|-------|-----------|--------|
| add-webhook-support | Done | 6/6 | 1 delta | None | Ready |
| fix-pagination-format | Done | 3/3 | 1 delta | None | Ready |
| add-csv-export | Done | 4/4 | 1 delta | None | Ready |

All 3 changes ready for archive.

> User confirmed: Archive all 3 changes

---

### Processing add-webhook-support...

Syncing delta spec `specs/webhooks/spec.md`...
✓ Created `openspec/specs/webhooks/spec.md` (3 requirements added)

Archiving...
```
mv openspec/changes/add-webhook-support openspec/changes/archive/2026-04-10-add-webhook-support
```
✓ Archived to `archive/2026-04-10-add-webhook-support/`

---

### Processing fix-pagination-format...

Syncing delta spec `specs/api-standards/spec.md`...
✓ Modified `openspec/specs/api-standards/spec.md` — added "Pagination Response Format" requirement

Archiving...
```
mv openspec/changes/fix-pagination-format openspec/changes/archive/2026-04-10-fix-pagination-format
```
✓ Archived to `archive/2026-04-10-fix-pagination-format/`

---

### Processing add-csv-export...

Syncing delta spec `specs/export/spec.md`...
✓ Created `openspec/specs/export/spec.md` (2 requirements added)

Archiving...
```
mv openspec/changes/add-csv-export openspec/changes/archive/2026-04-10-add-csv-export
```
✓ Archived to `archive/2026-04-10-add-csv-export/`

---

### Updating CHANGELOG.md (v1.4.0)...

```
## [1.4.0] — 2026-04-10

### Added
- Webhook subscription management (POST/GET/PUT/DELETE /api/webhook-subscriptions)
- Webhook event dispatching with HMAC-SHA256 signatures on object.created/updated/deleted
- CSV export for objects list view (downloadable from objects list page)

### Changed
- Pagination response format now includes total, page, pages, pageSize fields (NLGov API Design Rules compliance)
```

✓ CHANGELOG.md updated

---

## Bulk Archive Complete

Archived 3 changes:
- add-webhook-support → `archive/2026-04-10-add-webhook-support/`
- fix-pagination-format → `archive/2026-04-10-fix-pagination-format/`
- add-csv-export → `archive/2026-04-10-add-csv-export/`

Spec sync summary:
- 3 delta specs synced to main specs
- No conflicts

Changelog: ✓ CHANGELOG.md updated (v1.4.0, 4 entries added)
