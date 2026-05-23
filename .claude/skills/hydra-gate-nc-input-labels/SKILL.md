---
name: hydra-gate-nc-input-labels
description: Detect `<NcSelect>` usages without an `inputLabel` (or `ariaLabelCombobox`) prop. Manual `<label>` elements paired with NcSelect break the component's internal accessibility wiring (WCAG 2.1 AA Success Criteria 1.3.1 and 4.1.2); the built-in props are required for correct screen-reader association. ADR-004 hard rule. Observed 2026-04-30 on doriath.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, frontend, a11y]
---

## Purpose

`@nextcloud/vue`'s `NcSelect` component owns its label wiring internally. Pairing it with a manual `<label>` element does NOT associate the label with the underlying combobox, which breaks WCAG 2.1 AA Success Criterion 1.3.1 (Info and Relationships) and 4.1.2 (Name, Role, Value).

Always pass the visible label via the `inputLabel` prop (or `ariaLabelCombobox` for combobox mode without a visible label).

## Check

```bash
# Find every <NcSelect ... > opening tag (multi-line allowed) and verify
# each has inputLabel or ariaLabelCombobox.
find src -name '*.vue' 2>/dev/null | while IFS= read -r vue; do
    flat=$(tr '\n' ' ' < "${vue}")
    echo "${flat}" \
        | grep -oE '<NcSelect[^>]*>' \
        | while IFS= read -r tag; do
            if ! echo "${tag}" | grep -qE "(input-label|inputLabel|aria-label-combobox|ariaLabelCombobox)"; then
                echo "${vue}: ${tag}"
            fi
        done
done
```

## Fix action

For each FAIL line:

1. Locate the `<NcSelect>` tag in the file
2. Add an `inputLabel` prop with a translated string:
   ```vue
   <NcSelect
       :input-label="t('appid', 'Choose register')"
       :options="registerOptions"
       v-model="selectedRegister" />
   ```
3. If a manual `<label>` element was used to pair with the select, **delete it** — `NcSelect` renders the label internally
4. For comboboxes without a visible label, use `:aria-label-combobox` instead
5. Re-run the check

## Related orchestrator gate

`scripts/run-hydra-gates.sh` stage `nc-input-labels` runs the same check. ADR-004 documents this as a hard frontend rule.

## Verification

Sample gate output is in [examples/](examples/):
- [examples/pass.log](examples/pass.log) — what stdout looks like when this gate is green on the diff
- [examples/fail.log](examples/fail.log) — what stdout looks like when this gate finds a violation; per-finding detail is in `/tmp/hydra-gate-nc-input-labels.log` so the builder/reviewer can read line-by-line and apply Fix actions deterministically. Confirm the gate is green by running `./scripts/run-hydra-gates.sh --scope-to-diff` from the app dir before pushing.
