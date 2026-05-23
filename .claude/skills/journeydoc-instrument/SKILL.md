---
name: journeydoc-instrument
description: Add stable `data-testid` attributes to a Vue component so the journeydoc capture spec can target it reliably. Audits one file, proposes the additions, applies after confirmation, runs vitest. See ADR-030.
metadata:
  category: Workflow
  tags: [workflow, documentation, journeydoc, testids, playwright]
---

# Journeydoc Instrument — add stable `data-testid` to a Vue component

The journeydoc capture spec depends on stable selectors. Visible
labels shift with i18n, CSS classes get refactored, aria-labels can
match multiple elements. `data-testid="<scope>-<element>"` is the
contract — purely additive, zero behaviour change, zero styling
impact.

This skill audits one Vue file, proposes the testids, asks the human
to confirm, applies them, and runs vitest to prove nothing regressed.

**Input**: the path to one Vue file.
- `/journeydoc-instrument <app>/src/components/.../Foo.vue`

**Reference**: [ADR-030: journeydoc](../../../openspec/architecture/adr-030-journeydoc-pattern.md).

---

## Step 1: Verify the file exists and is a Vue SFC

```bash
test -f <path> && head -1 <path> | grep -q "<template>"
```

If not a Vue file, abort. If the file already has any `data-testid="…"`
attributes, list them so the human can decide whether to extend or
skip.

## Step 2: Identify capture-relevant anchors

Read the file and identify these element types — they're the ones
the capture spec typically needs to target:

| Anchor | Pattern in markup | Example testid |
|---|---|---|
| Modal container (root `<div>` of an `NcModal`'s content) | `<div class="…-modal">` | `<scope>-modal` |
| Modal save / primary action button | `<NcButton type="primary" @click="onSave">` | `<scope>-save-button` |
| Modal delete / destructive button | `<NcButton type="error" @click="onDelete">` | `<scope>-delete-button` |
| Form input (NcTextField, `<input>`, `<textarea>`) | `<NcTextField :placeholder="…">` | `<scope>-<field>-input` |
| Per-row action menu (cog NcActions) | `<NcActions :aria-label="…">` | `<scope>-actions` |
| Per-row action menu items (NcActionButton inside NcActions) | `<NcActionButton @click="$emit('foo')">` | `<scope>-<action>` |
| Right-click / context menu container | `<div class="…-context-menu" role="menu">` | `<scope>-context-menu` |
| Context menu items | `<button role="menuitem">` | `<ctx>-<action>` |
| Per-item card / list row root | `<div :class="…__item" :data-…>` | `<scope>-item-${id}` |
| Tab triggers | `<button role="tab">` | `<scope>-tab-<name>` |
| Section anchor for scroll-into-view | `<section>` / `<div class="…-section">` | `<scope>-<section>-section` |

`<scope>` is the feature surface (`dashboard`, `widget`, `cog`,
`ctx`, `admin`, etc.). Use the file's logical name as the scope
default — adjust if a more meaningful name fits.

## Step 3: Propose the additions

Build a list of `{element, line, proposed-testid, rationale}` rows
and present them with AskUserQuestion:

> "I propose these test-ids for `<file>`. Confirm to apply, or edit
> the list and re-run."

Show the list as a table. Example output for
`DashboardConfigModal.vue`:

```
Line  Element                                     Proposed testid                   Rationale
----  -----                                       ----------------                  ---------
 18   <NcTextField placeholder="My dashboard">    dashboard-name-input              modal name field, used by U2/U10
 29   <textarea placeholder="What is this…">      dashboard-description-input       modal description, used by U2/U10
146   <NcButton type="error" @click="onDelete">   dashboard-delete-button           destructive action, used by U10
156   <NcButton type="primary" @click="onSave">   dashboard-save-button             modal primary action, used by U2/U10
```

If the file has a sibling test (`__tests__/Foo.spec.js`) that asserts
on the same elements via different selectors, flag those rows so the
human knows tests will need to be updated in the same PR.

## Step 4: Apply the additions

Use the **Edit tool** for each addition. Surgical: add one
`data-testid="…"` attribute to an existing tag. Don't reflow the
template; don't move attributes around. Keep the diff minimal.

The Edit goes in the SAME order the human confirmed — if any rows
were rejected or edited, apply only the confirmed set.

## Step 5: Run vitest on touched specs

If the file has a sibling spec, run only that:

```bash
cd <app-root>
./node_modules/.bin/vitest run <path-to-spec>
```

If pass: report ✅. If fail: investigate. Most likely the existing
spec asserts on the same element via a less-stable selector and
needs an update. Report the failure and ask the human whether to
also update the spec to use the new testid.

If no sibling spec, run the full vitest suite (it's fast — usually
~15s for a typical app):

```bash
./node_modules/.bin/vitest run
```

## Step 6: Print the results

```
✅ Instrumented <file>:
  • Added <N> data-testid attributes
  • Vitest: <passed-count>/<total-count> passing

Use these in your capture spec via:
  page.locator('[data-testid="<scope>-<element>"]')

Next:
  1. Update tests/e2e/docs-screenshots.spec.ts to use the new testids
     for the relevant story.
  2. Re-run the capture spec to confirm the screenshots populate.

If you instrumented a component used in multiple capture stories,
also re-check each story for selector consistency.
```

---

## What this skill DOES NOT do

- **Doesn't open a PR.** Instrumenting a single component is usually
  bundled with the capture-spec update, not its own PR. The human
  decides when to commit + PR.
- **Doesn't touch i18n strings.** Test-ids are language-agnostic.
- **Doesn't migrate from `data-test=` (legacy convention) to
  `data-testid=`.** A few Conduction apps have `data-test=` from
  pre-pattern times. That migration is a separate, larger change —
  do it in its own PR with an ADR-030 reference.
- **Doesn't infer from the spec what testids to add.** The audit is
  proactive ("here are the surfaces that capture specs typically need
  to target"), not reactive. If you have a specific selector miss
  from a capture run, point the skill at the failing component and
  it'll propose just the right anchors.

## Failure modes

- **The file is huge (>500 lines) and has many small components
  inline.** Better to refactor first — extract the modals, the rows,
  the cog menu into their own files, then run this skill on each.
  Inline modal markup is also flagged by hydra-gate-modal-isolation
  (see ADR-004 hard rule).
- **No sibling spec exists.** Adding test-ids without a spec to
  exercise them is fine — capture spec will use them. Note this in
  the run output so the human knows to either add a unit test later
  or trust the capture spec as the only validation.
- **The Vue file uses class-bound `:data-testid="someExpression"`
  somewhere already.** Rare but possible. Skip those — leave the
  expression alone. The skill is for adding NEW testids on
  unattributed elements.
