---
kind: code
---

## Why

A store install can go into a target the app declares (change
`store-install-target`), but nothing remembers where an item went. An app that
keeps several worlds, administrations or tenants in one register cannot show
"Installed in De Zuiderlanden" on a store card, which larpinq's LrStore board
draws for every installed bundle (larpinq admin-configuration-store
REQ-ACS-003).

## Decision

The engine keeps a small install ledger per app: item slug to the targets it
was installed into, '' for an install without target. It lives in the calling
app's own IAppConfig under `store_installed_in`, so one app never sees
another's and resetting the app resets it. The search response carries it as
`installed`, next to `cards`, so a page reads where everything is in the same
request. Only a whole install (`success: true`) is recorded. The ledger holds
ids only and is bounded (500 items, 50 targets each, least recent dropped
first).

Deriving the answer from the installed objects was rejected: an installed object
carries no reference to the bundle it came from, and adding one would change
every installed object's shape for a display line.

Not done here: uninstall. There is no store uninstall yet; when one lands it
removes the target from the ledger.

## What Changes

- `StoreInstallLedger` (AppHost/Store): `record()` and `installedIn()`.
- `GenericStoreInstall` carries the ledger; `GenericStoreController::install()`
  records a whole install with its target, and `search()` answers with
  `installed`.

## Impact

Additive: the search response gains one key; installs behave as before.
