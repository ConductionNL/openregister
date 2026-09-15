# Design: feature-toggle-surface

## D-1: declared, never free-form

An undeclared key cannot be set, so a toggle nobody reads cannot exist and
a typo cannot silently disable a feature. The declaration is also what
renders the admin section, so the two cannot drift.

## D-2: instance scope, not organisation

The row asks for a toggle per instance. Per-organisation toggles would need
the tenancy model and a resolution order; nothing in the corpus asks for
it. The override lives in `IAppConfig` under the app, the same store the
plane already uses.

## D-3: one read path for PHP and the client

`FeatureToggleService` is the only reader in PHP; the merged map reaches
the client through initial state so a page never asks the API to know
whether to render itself. `visibleIf.feature` is evaluated by the manifest
runtime the way `visibleIf` is today.

## D-4: kind

Code, in OpenRegister and the manifest runtime. Consuming apps declare.
