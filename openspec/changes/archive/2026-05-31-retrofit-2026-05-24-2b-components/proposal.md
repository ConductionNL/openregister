# Retrofit — Shared UI Components Cluster

## Why

Coverage scan on 2026-05-24 surfaced 10 public methods across 8 `src/components/` Vue files that have no `@spec` annotation linking them to a capability. This change retroactively specifies four of those methods as a new `shared-ui-components` capability (pagination, configuration card import detection, settings card collapse, HTML sanitisation in settings sections). Code already exists — this change describes observed behavior, not new work.

## What Changes

- Mint a new `shared-ui-components` capability spec that captures the observable contracts of four shared Vue components: `PaginationComponent`, `ConfigurationCard`, `SettingsCard`, and `SettingsSection`.
- Add four numbered requirements (REQ-001..REQ-004) describing the observed behaviour — page-change bounds-checking, async detection of already-imported discovered configurations, opt-in collapsible section toggle, and HTML-entity escaping for the detailed-description slot.
- Annotate the four implementing methods with `@spec` pointers so the coverage matcher resolves them on the next scan.

This is a retroactive specification — code already exists. No code behaviour changes.

## Scope of this batch

**Cluster source**: `/tmp/or-scan/rspec-2b-components.json` — 10 methods / 8 files.

After triage:

- **4 methods → 4 REQs** in the new `shared-ui-components` capability:
  - `src/components/PaginationComponent.vue::changePage` (REQ-001)
  - `src/components/cards/ConfigurationCard.vue::checkIfImported` (REQ-002)
  - `src/components/shared/SettingsCard.vue::toggleCollapsed` (REQ-003)
  - `src/components/shared/SettingsSection.vue::sanitizeHtml` (REQ-004)

- **3 methods dropped** — behaviour is already covered by the existing `register-i18n` capability spec (the coverage matcher missed them only because they lack `@spec` annotation; a separate Bucket 1 sweep will add the pointers):
  - `src/components/i18n/BulkTranslateDialog.vue::onSubmit` — covered by register-i18n bulk-translate UI requirements.
  - `src/components/i18n/TranslationCompletenessBadge.vue::ratioPercent` — covered by register-i18n translation-completeness tracking.
  - `src/components/i18n/TranslationFieldEditor.vue::getValue` — covered by register-i18n language-tabbed editor requirements.

- **3 methods dropped as plumbing / false positives**:
  - `src/components/AgentSelector.vue::t` — translation function placeholder (`return text`), no observable behaviour.
  - `src/components/PaginationComponent.vue::if` — false positive; matches the `if`-statement inside `changePage`.
  - `src/components/shared/SettingsCard.vue::if` — false positive; matches the `if`-statement inside `toggleCollapsed`.

## Affected code units

- `src/components/PaginationComponent.vue::changePage`
- `src/components/cards/ConfigurationCard.vue::checkIfImported`
- `src/components/shared/SettingsCard.vue::toggleCollapsed`
- `src/components/shared/SettingsSection.vue::sanitizeHtml`

## Approach

For each method:
1. Read the file and capture observed inputs, outputs, side effects, preconditions, failure modes.
2. Draft one REQ per distinct observable behaviour, using imperative MUST/SHALL language.
3. Add at least one `WHEN … THEN …` scenario per REQ, testable against the existing implementation.
4. Surface observed-but-suspicious behaviour in a Notes section — do not silently "fix" it via the spec.

## Notes (carried into spec)

- `SettingsSection::sanitizeHtml` uses a `document.createElement('div').textContent = html; return div.innerHTML` roundtrip. The method's own inline comment flags this as "quick and dirty" and "@TODO: Implement production ready sanitisation". The retrofit REQ captures the observed behaviour (text-escape, not HTML-allowlist) and the Notes preserve the TODO.
- `ConfigurationCard::checkIfImported` swallows fetch errors (`catch (error) { this.importedConfigId = null }`) — observed behaviour is "on any error, treat as not-imported". The REQ captures this; tightening to retry / surface error is left as future work.

Source: `openspec/coverage-report.md` generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
