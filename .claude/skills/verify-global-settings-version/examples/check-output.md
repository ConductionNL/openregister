<!-- Example outputs — verify-global-settings-version for different scenarios -->

# Example Outputs: verify-global-settings-version

---

## Case A — No changes to `global-settings/`

```
Current branch : v1.4.0
origin/main    : v1.4.0

✅ No files in global-settings/ were changed relative to origin/main.
   No version bump needed.
```

---

## Case B — Changes found AND VERSION was bumped correctly

```
Current branch : v1.4.1
origin/main    : v1.4.0

✅ global-settings/ changes detected and VERSION was correctly bumped from v1.4.0 → v1.4.1.

Changed files:
- global-settings/settings.json
- global-settings/hooks/post-tool-use.sh
- global-settings/commands/permissions.md
```

---

## Case C — Changes found but VERSION was NOT bumped

```
Current branch : v1.4.0
origin/main    : v1.4.0

❌ VERSION BUMP MISSING

The following files in global-settings/ were changed but VERSION was not incremented:
- global-settings/settings.json
- global-settings/hooks/post-tool-use.sh

Current VERSION on this branch: v1.4.0 (same as origin/main)

Action required: Increment global-settings/VERSION before creating a PR.
Suggested next version: v1.4.1 (patch bump — use minor if behavior changed, major if breaking)

To apply the suggested bump:
    echo "v1.4.1" > global-settings/VERSION

Then commit the change and re-run /verify-global-settings-version.
```

---

## Case D — VERSION bumped but no other files changed

```
Current branch : v1.4.1
origin/main    : v1.4.0

⚠️  VERSION was bumped from v1.4.0 → v1.4.1 but no other files in global-settings/ were changed.

This is unusual — confirm the bump is intentional before creating a PR.
```
