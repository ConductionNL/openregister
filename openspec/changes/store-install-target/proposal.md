---
kind: code
---

## Why

A store install lands in the app's local register and nowhere more specific.
Apps that keep several worlds, administrations or tenants inside one register
need an install to go into one of them: larpinq installs a skill tree, a set of
conditions or the effects behind them into one game world (larpinq
admin-configuration-store REQ-ACS-003). nextcloud-vue's `CnStorePage` already
offers a target picker (`config.targets`) and sends the chosen id in the install
body as `target`; the engine did not read it.

## Decision

The target is stamped onto the installed objects, not modelled as a new kind of
install. The app declares in its manifest which property records the target and
on which schemas (`store.installTarget: { property, schemas }`). The stamp is
applied after the bundle's signature check, to a copy: the publisher signed what
it published, and where it lands is the installing organisation's choice.

A `target` the app did not declare, or one that is not an id, is refused with a
400 rather than ignored, so nothing lands somewhere the caller did not ask for.
The posture check runs first, so a caller who may not install learns nothing
about targets.

Not done here: installing the same bundle into a second target. A configuration
set's objects keep their identities, so a second install updates the objects of
the first rather than copying them. Per-target copies need identity rewriting
across the bundle's references and are left for a follow-up when an app needs it.

## What Changes

- `StoreInstallTarget` (AppHost): the manifest block, the id check, the stamp.
- `BundleObjectStamp` (Kernel value): writes one property on the objects of
  named schemas in `components.objects` and `objects`.
- `GenericStoreController::install()` reads `target`; `FederatedStoreCatalog`,
  `ShareableConfigCatalogue` and `FederatedConfigService::install()` carry the
  stamp (optional, default none); `GenericStoreInstaller::install()` stamps each
  object component.
- The app-manifest schema gains `store.installTarget`.

## Impact

Optional everywhere: an app without `installTarget` and an install without
`target` behave as before.
