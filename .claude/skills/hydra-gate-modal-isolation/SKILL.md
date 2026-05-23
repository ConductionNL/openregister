---
name: hydra-gate-modal-isolation
description: Detect `<NcModal>` or `<NcDialog>` markup written inline inside parent components. Every modal/dialog MUST live in its own `.vue` file under `src/modals/` (NcModal-based) or `src/dialogs/` (NcDialog-based) and be imported by the parent. Inline modals couple presentation to the parent's lifecycle, prevent reuse, and bloat the parent component. ADR-004 hard rule. Observed 2026-04-30 on doriath.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, frontend, structure]
---

## Purpose

Modals and dialogs are independent presentational units: open/close state, slot content, validation, and event emission belong with the modal — not with whatever page or sidebar happens to host it. ADR-004 codifies the convention:

- `src/modals/` — every `NcModal`-based component
- `src/dialogs/` — every `NcDialog`-based component
- Parent components import + register them, never declare them inline

## Check

```bash
# .vue files OUTSIDE src/modals/ and src/dialogs/ that contain <NcModal or <NcDialog
find src -name '*.vue' 2>/dev/null | while IFS= read -r vue; do
    case "${vue}" in
        src/modals/*|src/dialogs/*) continue ;;
    esac
    if grep -qE '<NcModal[ \t>/]|<NcDialog[ \t>/]' "${vue}" 2>/dev/null; then
        echo "${vue}: inline NcModal/NcDialog — extract to src/modals/ or src/dialogs/"
    fi
done
```

## Fix action

For each FAIL file:

1. Identify the inline `<NcModal>` / `<NcDialog>` block in the parent
2. Extract it to a new file:
   - `NcModal`-based → `src/modals/<DescriptiveName>Modal.vue`
   - `NcDialog`-based → `src/dialogs/<DescriptiveName>Dialog.vue`
3. The new component owns: visible/open prop, close emit, internal state, slot content, validation
4. In the parent: import the new component, register it, replace the inline markup with the new tag, drive open/close via `:open` + `@update:open`
5. Re-run the check

**Note:** This gate excludes `NcAppSettingsDialog` usage in `UserSettings.vue` because that's the documented in-app settings pattern (ADR-004). Add new exclusions only when ADR-004 is updated to allow them.

## Related orchestrator gate

`scripts/run-hydra-gates.sh` stage `modal-isolation` runs the same check. ADR-004 documents this as a hard frontend rule.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-modal-isolation.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
