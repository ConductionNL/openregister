# Tasks: settings-change-audit

> **Blocked, and by more than its `depends_on` says.** This change depends on
> `apphost-settings-plane` and `audit-log-page`, and `audit-log-page`'s task 1.2
> turns out to be a deliberate re-opening of a security boundary rather than a
> filter (see the note there, measured 2026-09-18). Its reader half, task 2.1
> here, therefore inherits that blocker: a `settings` kind filter is a filter on
> a page whose access rule is the open question.
>
> The WRITER half, tasks 1.1 to 1.3, does not depend on the page at all — it
> writes rows, and rows are readable through the existing admin-gated
> `GET /api/audit-trails` the day they exist. Whoever picks this up can ship the
> writer first and should, rather than waiting for a page whose hardest question
> is unrelated to recording who changed a setting.

## 1. Writer

- [x] 1.1a The per-key diff and the rows, in `SettingsChangeAuditor`:
      `settings.updated` per changed key and `settings.imported` as ONE row for
      an import, both on the existing chain through
      `AuditTrailMapper::insertAuditTrails()`. A save that changed nothing
      writes nothing, and `"1"` over a stored `1` is not a change — `IAppConfig`
      stores strings, so a strict comparison would record one on every save.
- [ ] 1.1b The entry in `GenericSettingsService::update()`.
      > 🔑 **THAT METHOD DOES NOT EXIST.** The generic service carries only
      > `loadConfiguration()`, and `apphost-settings-plane` has six open tasks.
      > Measured 2026-09-18. The auditor is therefore DOOR-AGNOSTIC — it takes
      > a before and an after — and is wired into the door that does exist,
      > `AppHostSettingsService::updateSettings()`. When the generic `update()`
      > lands it calls the same auditor; nothing here has to be rewritten, and
      > in the meantime settings changes on the live door are recorded rather
      > than waiting for a plane that is half built.
- [ ] 1.1c The import entry on `load(force)`: `recordImport()` exists and is
      tested, and is not yet called from `loadConfiguration()`, which would
      need the overwritten-key count that method does not currently compute.
- [x] 1.2 `x-openregister-secret` read from the register configuration
      (`secretKeysIn()`), and masking. A secret is recorded as CHANGED WITH
      BOTH VALUES MASKED rather than omitted: the credential somebody rotated
      is the row worth having most. The value never reaches the row, because
      the trail is append-only and a secret written into it cannot be redacted
      afterwards. An introduced secret and a removed one stay distinguishable
      (`null` on the side where the key was absent), which a single mask token
      for both would have collapsed.
      `AppHostSettingsService::secretConfigKeys()` is the per-app hook.
- [ ] 1.3 OpenRegister's own `SettingsService` domains route through the
      writer. Unblocked — the writer exists and is a two-line call — but it is
      a separate service with its own write paths, so it is its own task rather
      than a rider on this one.

## 2. Reader

- [ ] 2.1 Kind filter `settings`, diff rendering and export column on the audit leaf.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/settings-audit.spec.ts`: change a setting, filter the audit page, read the diff.
- [x] 3.2a Unit tests for the diff and the masking: 11 cases, including the
      no-op save, type juggling, add and remove, the introduced-versus-removed
      secret, the system actor and the fail-soft write.
- [ ] 3.2b The preferences exclusion, which needs `GenericPreferencesController`
      to route through a writer it does not call yet. Chain verification for
      these rows is covered by `RevealChainIntegrityTest` in openregister#3886:
      they are ordinary rows on the same chain, sealed by the same mapper pass.
