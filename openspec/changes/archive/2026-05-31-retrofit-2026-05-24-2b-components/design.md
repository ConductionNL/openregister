# Design — Retrofit shared-ui-components

**Retrofit change.** Tasks describe retroactive annotation, not new implementation work. The code already exists in `src/components/`; this change adds the spec it should have been authored against.

## Why a new capability instead of extending an existing one

The four annotated methods belong to general-purpose UI building blocks (pagination, settings card, settings section, the universal configuration card). None of the existing OpenRegister capabilities — `register-i18n`, `zoeken-filteren`, `auth-system`, `audit-trail-immutable`, etc. — are about app-wide reusable widgets. Folding shared widgets into a feature capability (e.g. "put pagination inside zoeken-filteren") would couple the widget contract to a single consumer; every other consumer of `PaginationComponent` would then be reading a search-flavoured spec for a widget that knows nothing about search.

Minting `shared-ui-components` as its own capability keeps the boundary clean: feature capabilities consume these widgets, and the widget contract evolves independently.

## What was deliberately dropped

The cluster JSON included 10 entries; the retrofit covers 4 of them. The remaining 6 were dropped:

| Method | Reason |
|---|---|
| `AgentSelector.vue::t` | Translation-function placeholder (`return text`) — no observable behaviour. Plumbing. |
| `PaginationComponent.vue::if` | False positive — the parser caught the `if`-statement inside `changePage`. |
| `SettingsCard.vue::if` | False positive — the `if`-statement inside `toggleCollapsed`. |
| `BulkTranslateDialog.vue::onSubmit` | Already covered by `register-i18n` (bulk-translate UI). Annotation will be added by a Bucket 1 sweep against the existing REQ. |
| `TranslationCompletenessBadge.vue::ratioPercent` | Already covered by `register-i18n` (translation-completeness tracking). |
| `TranslationFieldEditor.vue::getValue` | Already covered by `register-i18n` (language-tabbed editor). |

This is in line with the playbook's no-inflate rule: do not mint REQs for plumbing, do not duplicate behaviour already specified elsewhere.

## REQ granularity

One REQ per distinct observable behaviour:

- **REQ-001** captures pagination's bounded page-change emission (the safety check is the observable behaviour; the visiblePages calc is internal layout logic).
- **REQ-002** captures the async backend round-trip that drives the imported/discovered presentation switch in `ConfigurationCard`. Scenarios cover the three observable branches (found, not-found, error).
- **REQ-003** captures the collapsible-card toggle contract — opt-in via `collapsible`, with a `toggle` event payload that callers depend on.
- **REQ-004** captures the XSS-safe rendering of `detailedDescription`. The "quick and dirty" implementation note is preserved verbatim in the spec's Notes section so it isn't lost when a future change replaces the textContent-roundtrip with DOMPurify.

No REQ was split or merged beyond this rule.

## Specter sync

After archive, run `python3 concurrentie-analyse/scripts/sync_spec_content.py openregister` to register the new capability (and its `retrofit: true` frontmatter flag) in the retrofit cohort dashboards. Coverage report is regenerated on the next `/opsx-coverage-scan` cycle.
