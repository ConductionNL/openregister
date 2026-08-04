# Proposal: Reverse-spec the settings-management capability (mint)

## Why

OpenRegister exposes a large, cross-cutting settings surface — LLM providers,
file/object vectorization, retention, archival, SOLR/search backend, RBAC,
multitenancy, organisation defaults, publishing, n8n, and cache — that the admin
UI and several business-logic services read and write. This surface is
implemented today as a single `SettingsService` facade (2097 lines, ~50 public
methods) delegating to seven specialized `Service\Settings\*` handlers, plus
mass-validation batch orchestration and version/database/PostgreSQL-extension
introspection. Despite the large method count, the behavior is highly uniform:
every per-domain endpoint is a typed `getXSettingsOnly()` / `updateXSettingsOnly()`
pair that reads a single JSON-encoded blob from `IAppConfig`, fills defaults for
unconfigured keys, PATCH-merges incoming data over the stored values, and writes
back. No capability currently describes this contract — the controller-side
settings admin (specced separately under the `ctrl-settings-observ` bundle)
delegates into this layer but has no documented persistence/orchestration
contract beneath it.

This is a reverse-spec retrofit: it documents observed behavior of already-shipped
code. It mints one `settings-management` capability that specifies the
**sliced-settings PATTERN** (typed per-domain get/update, defaults, PATCH-merge,
JSON-in-`IAppConfig` persistence) once, enumerates the domains as a scenario,
and adds the cross-cutting orchestration concerns (mass-validation batch jobs,
environment introspection, rebase) as their own requirements — rather than
emitting ~80 near-identical per-method annotations.

## What Changes

- **MINT** capability `settings-management` with five requirements covering the
  sliced-settings persistence pattern, the facade/handler delegation
  architecture, mass-validation batch orchestration, environment introspection
  (version/database/PostgreSQL extensions), and configuration rebase.
- Annotate the `SettingsService` facade and its `Service\Settings\*` handler
  methods with `@spec` pointers to the tasks in this change, grouping the
  repetitive per-domain get/update pairs under shared tasks.
- No production code behavior changes — annotations and documentation only.

## Impact

- Affected specs: `settings-management` (new).
- Affected code (annotations only):
  - `lib/Service/SettingsService.php`
  - `lib/Service/Settings/CacheSettingsHandler.php`
  - `lib/Service/Settings/ConfigurationSettingsHandler.php`
  - `lib/Service/Settings/LlmSettingsHandler.php`
  - `lib/Service/Settings/SolrSettingsHandler.php`
  - `lib/Service/Settings/ValidationOperationsHandler.php`
- No migrations, no API changes, no behavioral change.
