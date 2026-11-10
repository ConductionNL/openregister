# ADR-037: modular register fragments

This directory is the drop-zone for per-OpenSpec-change register fragments
(`*.json`) that would otherwise edit a shared `*_register.json` monolith,
so concurrent same-app builds touch disjoint files and never collide on a
merge.

## Status for OpenRegister

OpenRegister is the configuration *platform* itself: it exposes
`ConfigurationService::importFromApp()` / `importFromFilePath()` for **other**
apps to import their `{app}_register.json` data models. OpenRegister does not
read its own register monolith and feed it to `importFromApp` for its own data
model (the local `lib/Settings/*_register.json` files are integration register
descriptors fetched/imported through separate flows). There is therefore no
single PHP load-and-import site to wrap with a `deepMergeConfig()` fragment
merge here.

The PHP-side fragment merge (the `deepMergeConfig()` + `register.d/*.json` glob
that consumer apps such as shillinq carry — see shillinq#171) is consequently
**not wired** in OpenRegister. This directory is kept as the convention anchor:
if a future change introduces a self-imported register monolith, drop fragments
here and add the `deepMergeConfig` merge in the loading service, mirroring the
shillinq `SettingsService::loadRegisterConfigData()` reference.

The manifest side (`src/manifest.d/*.json` merged in `src/main.js` via
`mergeManifestFragments()`) **is** enabled.
