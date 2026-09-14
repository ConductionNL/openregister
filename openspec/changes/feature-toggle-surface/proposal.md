---
kind: code
depends_on: [apphost-settings-plane]
---

# Proposal: feature-toggle-surface

## Summary

Give every app one way to switch a feature on or off per instance. The
settings plane (`apphost-settings-plane`) gives an app `index`, `update`
and `load` over its register configuration. This change adds a `features`
domain to that surface: an app declares its toggles with defaults, an
administrator flips them on the Nextcloud admin page, PHP asks
`FeatureToggleService::isEnabled()`, and a manifest entry hides behind
`visibleIf.feature`.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 11.15 | Feature toggles | partial | S |

## Why

The register's note: "`tenantConfiguration.features`,
`lib/Controller/SettingsController.php` (AI toggles); no feature-flag
admin". The best competitor, verbatim from the `best` column: "xxllnc
Zaken:
`frontend-mono/apps/main/src/modules/configuration/Configuration.fixtures.ts`
(`_round2/compare/M1-functionality.md`)".

The register's `why`: "a feature toggle administered per instance is the
settings plane".

## What changes

- An app declares toggles in its manifest: `features: [{ key, label,
  description, default }]`. The plane reads the declaration at `load`.
- The generic settings `index` returns `features` merged: declared default,
  instance override. `update` accepts `features: { key: bool }` and refuses
  an undeclared key.
- `FeatureToggleService::isEnabled(app, key)` for PHP, cached per request
  and invalidated on `update`; `loadState('openregister', 'features')`
  carries the merged map to the client.
- The manifest runtime honours `visibleIf: { feature: "<key>" }` on pages,
  widgets and actions.
- The Nextcloud admin page of every app riding `GenericAdminSettings` shows
  a Features section generated from the declaration.
- A toggle change writes a settings audit entry (`settings-change-audit`).

## Consumers

- dossiq: `tenantConfiguration.features` reads the plane instead of its own
  list. Specified in dossiq by the dossiq lane (register row 11.15).
- Every app on the settings plane: one declaration, one section, no
  controller.

## ADRs

- ADR-076 (settings plane): the toggle is a plane consumable.
- ADR-079: administered on the Nextcloud admin page.
- ADR-024 (app manifest): the declaration is manifest data.
- ADR-102 (config fail mode): a toggle guarding a security-relevant path
  declares its fail mode in the declaration.

## Impact

- Extends: `apphost-settings-plane` requirement "Generic settings surface".
- Affected code: `lib/AppHost/Service/GenericSettingsService.php`,
  `lib/AppHost/Service/FeatureToggleService.php`, the manifest schema in
  nextcloud-vue (`features`, `visibleIf.feature`), `GenericAdminSettings`.
- Backwards compatible: an app without `features` sees no section.
- Size: S.
