# PR Review Checklist

Use this checklist when briefing the analysis subagent. Not every item applies to every PR —
focus on what the diff actually touches.

## Regression Risk

- Were integration or unit tests deleted? If so, are the cases they covered still tested
  somewhere, or are they silently dropped?
- Does a "pointer comment" replace deleted test coverage? That is a risk, not coverage.
- Does the PR claim to migrate logic to a shared service — but leave one callsite behind?

## Completeness of Parallel Changes

- When a group of similar methods/operators gets a fix (null guard, delegation), verify
  every member of the group is updated. Common miss: `operatorIn` when `operatorNotIn`
  and `operatorNotEquals` are patched, or `applyFilter` when `buildFilter` is patched.
- If the PR description lists N things fixed, confirm all N are actually in the diff.
- After verifying a fix at one site, grep the rest of the diff for the same pattern. A
  PR can correctly fix `executeQuery()` in one file while silently reintroducing it in
  a migration or a second mapper in the same commit.

## Claim Accuracy (PR Description vs Code)

- Does the CHANGELOG entry match what is in the diff? Discrepancies confuse consumers.
- If the PR says "all operators return false when null", verify `operatorIn`, `$nin`,
  `$ne`, `$gt/$gte/$lt/$lte` all have null guards — not just the ones mentioned.
- When an author defers a concern as "pre-existing" or "out of scope," grep the diff to
  confirm that change site is not present. Authors with broad context merges frequently
  misattribute new changes to the merge rather than to their own commit.

## SQL vs PHP Parity (for DB/RBAC-adjacent code)

- If the code has parallel SQL and PHP paths that must agree (e.g. list vs find),
  verify both paths are updated or both are intentionally left asymmetric.
- SQL `col = NULL` is always false; PHP `$value === null` is explicit — mismatches cause
  list/find drift. Flag any comparison without a null guard.
- SQL `NULL != X` → NULL (filtered); PHP `null !== $x` → true. They disagree.

## Null Handling (PHP)

- Check comparison operators for raw `<`, `>`, `<=`, `>=` without null guards.
  PHP coerces `null` to `""` or `0`, producing misleading `true/false`.
- `$ne: null` and `$nin` semantics: does PHP match SQL's three-valued logic?
- The escape hatch `$eq: null` ("match missing field") should remain unchanged.

## Delegation Completeness (Unification PRs)

- When a private helper is deleted and its callers delegate to a shared service,
  verify all caller sites are updated — not just the ones in the PR title.
- Check for a "fourth duplicate" — another class with the same logic that was skipped.
- If a method is removed and marked "zero production callers," verify with grep.
  Flag if it has test coverage — tests are still callers.

## Deprecated/Removed Code Hygiene

- Removed methods that are replaced by a canonical alternative should carry `@deprecated`
  tags pointing at the replacement before being deleted in a follow-up.
- "Out of scope" items should be logged with a breadcrumb (`@deprecated`, `// TODO`, or
  a tasks.md entry) — not just mentioned in the PR description.

## Test Quality

- Are new tests asserting behavior, or just verifying that mocks were called?
  (`$this->mock->expects($this->once())->willReturn(true)` tests delegation shape,
  not correctness.)
- Is there at least one real-wired integration test for the changed logic path?
- Do the evals cover the exact failure scenario the PR was meant to fix?

## Frontend (ADR-004) — Conduction Nextcloud apps

These four checks come from real review findings on doriath (2026-04-30) and are now
mechanically enforced by hydra-gates 10–13. When reviewing a frontend PR, also check
them by hand in case a developer disabled or pre-dates the gates.

- **DOM dataset reads** — flag any `getElementById('…').dataset.<key>` or `?.dataset?.<key>`
  in `.vue`/`.js`/`.ts`. Server-side data MUST use `IInitialState::provideInitialState()` in
  PHP + `loadState('appid', 'key', default)` from `@nextcloud/initial-state` in Vue. The
  DOM-attribute pattern breaks on CSP-hardened instances and bypasses the canonical NC pattern.
  Watch for the legacy template skeleton: `getElementById('app-template-settings')` left
  behind from a forked app-template — both the wrong ID and the wrong pattern.
- **Admin settings in vue-router** — flag any `import` from `views/settings/Admin*.vue` in
  `src/router/index.{js,ts}`, and any `path: '/settings'` or `path: '/admin'` entry. Admin
  settings are rendered by Nextcloud's settings framework via `AdminSettings.php`; routing them
  in the SPA makes them publicly accessible to any authenticated user, bypassing the admin
  check. **Security regression — always 🔴 blocker.**
- **NcSelect labels** — flag `<NcSelect>` tags missing `inputLabel` (or `ariaLabelCombobox`).
  Manual paired `<label>` elements break the component's internal a11y wiring (WCAG 2.1 AA
  SC 1.3.1 / 4.1.2). The visible label must come from the `inputLabel` prop.
- **Inline modals/dialogs** — flag any `<NcModal>` or `<NcDialog>` markup in a `.vue` file
  outside `src/modals/` or `src/dialogs/`. Each modal/dialog must be its own file (state,
  slot content, validation, emit) imported by the parent. Exception: `NcAppSettingsDialog`
  in `UserSettings.vue` is the documented in-app settings pattern.

## ConductionNL / PHP / Nextcloud Specifics

- PHP `+` vs `array_merge`: `+` keeps left-hand keys on collision; `array_merge` overwrites.
  Non-obvious precedence should have a comment.
- Nextcloud container resolution via `$container->get(ClassName::class)` — verify the
  class is registered in the app's service container (`Application.php` or equivalent).
- `IUserSession::getUser()` returns `null` for anonymous requests — always null-check.
- `quoteValue('NULL')` in SQL emitters emits `= NULL`, not `IS NULL` — flag this pattern.
- PSR-4 namespacing: `OCA\<AppName>\<Path>` — verify class name matches file path.
- WordPress (if applicable): nonces on all AJAX/form endpoints, `sanitize_*` on input,
  `esc_*` on output.
