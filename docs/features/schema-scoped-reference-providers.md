# Schema-Scoped Reference Providers

## Overview

OpenRegister's Smart Picker and unified search integration is, by default, a single fleet-wide pair: every object across every register and schema is found through one generic "Register Objects" entry in Nextcloud's Smart Picker, and one generic `openregister_objects` unified-search provider. That is enough for ad-hoc object lookup, but it means a consuming app cannot make one specific data type — e.g. Pipelinq's "Lead" — appear as its own named entry in the Smart Picker's "Select provider" list, the way Files, Talk conversations, or Bookmarks do.

Schema-Scoped Reference Providers close that gap: two reusable abstract base classes (`AbstractSchemaReferenceProvider`, `AbstractSchemaSearchProvider`) let a consuming app expose a single (register, schema) pair as its own discoverable, searchable Smart Picker entry — with a near-empty subclass, no reimplementation of URL parsing, RBAC-safe search, or rich-preview formatting.

Crucially, OpenRegister — not the consuming app — determines the provider's identity: the provider id, title, and icon are computed deterministically from the schema's own slug and metadata, never chosen by app code, so ids can never collide and a picker entry's title always reflects the schema's current name.

## How It Works

### One subclass, no business logic

A consuming app implements exactly two methods — `getRegisterSlug()` and `getSchemaSlug()` — and gets a fully functional Smart Picker entry:

```php
// Pipelinq's LeadReferenceProvider.php (illustrative — not shipped by this change)
class LeadReferenceProvider extends AbstractSchemaReferenceProvider {
    public function getRegisterSlug(): string { return 'pipelinq'; }
    public function getSchemaSlug(): string { return 'lead'; }
}
```

Everything else — `getId()`, `getSupportedSearchProviderIds()`, `getTitle()`, `getIconUrl()`, `matchReference()`, `resolveReference()`, `getCachePrefix()`, `getCacheKey()` — is inherited, computed, or delegated by the base class.

### Deterministic, collision-proof identity

`getId()` and `getSupportedSearchProviderIds()` are declared `final` on the base class — a subclass cannot override them:

- Reference provider id: `openregister-ref-{registerSlug}-{schemaSlug}`
- Search provider id: `openregister_objects_{registerSlug}_{schemaSlug}`

Since register and schema slugs are already guaranteed unique, two schema-scoped providers can never collide on id.

### Title and icon are live, not static

`getTitle()` reads the schema's current title via `SchemaMapper::find()`; `getIconUrl()` resolves the schema's configured MDI icon through the existing `openregister.icon.mdi` route. Renaming a schema in the Schema settings UI updates its Smart Picker entry's title on the next request — no code change or redeploy in the consuming app.

### Schema-match guard

`matchReference()`/`resolveReference()` reuse OpenRegister's existing URL-parsing and rich-preview logic (hash-routed UI, API, and direct-route object URLs), then reject anything that doesn't belong to the configured (register, schema) pair — a Case URL never resolves inside a "Leads" picker entry, even though both are syntactically valid OpenRegister object references.

### `smartPickerEnabled` — a schema setting, not app code

Whether a schema participates is a schema-level boolean flag, `smartPickerEnabled` (default `false`), mirroring the existing `searchable` flag used by unified search. This means OpenRegister — not the consuming app — decides whether a schema is exposed, consistent with how `searchable` already governs unified-search participation.

**Known limitation, by design of Nextcloud's registration API:** Nextcloud resolves the Smart Picker's "Select provider" list from PHP classes registered once at app boot (`IRegistrationContext::registerReferenceProvider()`/`registerSearchProvider()`), which never consults a runtime database flag. So `smartPickerEnabled = false` makes a provider **functionally inert** — `matchReference()` returns `false`, `resolveReference()` returns `null`, `search()` returns zero results — but its entry remains visible in the picker list as long as its class is registered. Removing the entry from the list requires the consuming app to stop registering the class in code.

### RBAC and multitenancy — no second access filter

`AbstractSchemaSearchProvider::search()` delegates to the same `ObjectService::searchObjectsPaginated(_rbac: true, _multitenancy: true)` pipeline the generic `openregister_objects` provider uses, with the configured schema forced into the query. Access control is enforced identically — the schema-scoped provider applies no separate filter of its own.

## Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `AbstractSchemaReferenceProvider` | `lib/AppHost/Reference/AbstractSchemaReferenceProvider.php` | Smart Picker entry for one (register, schema) pair — computed id/title/icon, schema-scoped `matchReference()`/`resolveReference()` |
| `AbstractSchemaSearchProvider` | `lib/AppHost/Search/AbstractSchemaSearchProvider.php` | Unified-search provider scoped to one schema, same RBAC/multitenancy contract as the generic provider |
| `ObjectPreviewFormatter` | `lib/Service/Reference/ObjectPreviewFormatter.php` | Shared URL parsing/building and rich-preview formatting, used by both the generic and schema-scoped reference providers |
| `ObjectSearchResultFormatter` | `lib/Service/Search/ObjectSearchResultFormatter.php` | Shared search-result formatting (icon precedence, deep-link URL, excerpt), used by both the generic and schema-scoped search providers |

## Use Cases

A consuming app with a small, deliberate set of schemas it wants surfaced as first-class Smart Picker entries — e.g. Pipelinq's CRM schemas (Lead, Client), or Procest's case-management schemas (Case) — subclasses both abstract base classes once per schema and flips `smartPickerEnabled` on for that schema. No app currently ships a concrete subclass; this change delivers the reusable base classes that a future per-app change builds on.

## Related Features

- [Deep Link Registry](deep-link-registry.md) — URL/icon resolution both the generic and schema-scoped providers consult
- [Search, Filtering & Faceting](search-and-faceting.md) — the generic `openregister_objects` unified-search provider this change complements
- [Access Control (RBAC)](access-control.md) — the RBAC/multitenancy contract schema-scoped search delegates to unchanged
