# Output Templates

## Archive Complete

```
## Archive Complete

**Change:** <change-name>
**Schema:** <schema-name>
**Archived to:** openspec/changes/archive/YYYY-MM-DD-<name>/
**Specs:** ✓ Synced to main specs
**Feature docs:** ✓ Updated docs/features/<feature-file>.md
**Changelog:** ✓ CHANGELOG.md updated (v<version>)
**Test scenarios:** ✓ Created N scenario(s): TS-NNN, … (or "Skipped" / "No test-plan.md")

All artifacts complete. All tasks complete.
```

## Archive Complete No Delta Specs

```
## Archive Complete

**Change:** <change-name>
**Schema:** <schema-name>
**Archived to:** openspec/changes/archive/YYYY-MM-DD-<name>/
**Specs:** No delta specs
**Feature docs:** ✓ Updated docs/features/<feature-file>.md (or "No matching feature doc")
**Changelog:** ✓ CHANGELOG.md updated (v<version>)
**Test scenarios:** ✓ Created N scenario(s): TS-NNN, … (or "Skipped" / "No test-plan.md")

All artifacts complete. All tasks complete.
```

## Archive Complete With Warnings

```
## Archive Complete (with warnings)

**Change:** <change-name>
**Schema:** <schema-name>
**Archived to:** openspec/changes/archive/YYYY-MM-DD-<name>/
**Specs:** Sync skipped (user chose to skip)
**Feature docs:** ✓ Updated docs/features/<feature-file>.md (or "Skipped — no mapping found")
**Changelog:** ✓ CHANGELOG.md updated (v<version>)
**Test scenarios:** ✓ Created N scenario(s): TS-NNN, … (or "Skipped" / "No test-plan.md")

**Warnings:**
- Archived with 2 incomplete artifacts
- Archived with 3 incomplete tasks
- Delta spec sync was skipped (user chose to skip)

Review the archive if this was not intentional.
```

## Archive Failed

```
## Archive Failed

**Change:** <change-name>
**Target:** openspec/changes/archive/YYYY-MM-DD-<name>/

Target archive directory already exists.

**Options:**
1. Rename the existing archive
2. Delete the existing archive if it's a duplicate
3. Wait until a different date to archive
```
