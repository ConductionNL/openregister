# Design: Pluggable Integration Registry

## Reuse analysis

- `OCA\OpenRegister\Service\LinkedEntityService` exists; it's the
  existing single point of contact for "linked things." The migration
  preserves its public API surface — `IntegrationProvider` is the new
  internal contract, `LinkedEntityService` becomes a registry
  consumer.
- `OCA\OpenRegister\Db\Schema::validateLinkedTypesValue()` already
  hooks linkedTypes validation; we swap the data source from constant
  to registry.
- DI tags via `lib/AppInfo/Application.php` are an existing pattern in
  OR (e.g. `EventSubscriber` tag); we add `IntegrationProvider`
  alongside.
- OpenConnector handles credentials for external services already; we
  reuse, never duplicate.

## Public API shape

```php
namespace OCA\OpenRegister\Service\Integration;

interface IntegrationProvider {
    public function getId(): string;        // 'files', 'notes', etc. — the canonical key
    public function getLabel(): string;     // human-readable, i18n key
    public function getIcon(): string;      // icon class or URL
    public function isEnabled(): bool;      // checks NC app installed/enabled
    public function getStorageStrategy(): string;     // 'native' | 'external'
    public function authRequirements(): array;        // empty for native; OAuth scope for external
    public function linkedColumnName(): ?string;      // backwards compat: column on object's linked-things JSON
    public function query(string $objectUuid): iterable;
    public function mutate(string $objectUuid, array $payload): void;
}

final class IntegrationRegistry {
    public function register(IntegrationProvider $p): void;
    public function listAll(): iterable;       // every registered provider
    public function listEnabled(): iterable;   // only those with isEnabled() === true
    public function listIds(): array;          // ['files', 'notes', ...]
    public function getById(string $id): ?IntegrationProvider;
    public function requireById(string $id): IntegrationProvider; // throws IntegrationNotFoundException
}
```

## Three-stage filter (per ADR-019)

The service surface answers stage 1 (registry — does it exist + is
the underlying NC app installed). The schema's `configuration.linkedTypes`
whitelist answers stage 2. The rendering component's `excludeIntegrations`
prop answers stage 3. This change implements stage 1 only; stages 2-3
are existing capabilities of the schema + the FE library.

## Storage strategy: native vs external

- **native**: provider stores linked-thing references in OR's existing
  per-object linked-things JSON column (preserves current behaviour).
- **external**: provider stores nothing in OR; instead, queries flow
  through `ExternalIntegrationRouter` which dispatches to an
  OpenConnector source. OR shows the linked things in the UI but
  does NOT own credentials or persistence for them.

The external path is what enables OpenProject / XWiki / third-party
service integrations without leaking credentials into OR.

## Capability advertising

Mobile apps and partner integrations need to discover which
integrations a given OR install supports. The OCS capability hook
puts this on the standard NC discovery surface:

```http
GET /ocs/v2.php/cloud/capabilities
```

```json
{
  "ocs": {
    "data": {
      "capabilities": {
        "openregister": {
          "integrations": ["files", "notes", "tasks", "calendar"]
        }
      }
    }
  }
}
```

Unenabled integrations (`isEnabled() === false`) MUST NOT appear here —
the capability map is "what works right now," not "what could work."

## Parity gate

A new `scripts/check-integration-parity.sh` walks both registries:

1. List every backend `IntegrationProvider`'s `getId()`.
2. Parse `nextcloud-vue/src/integrations/index.js` for every FE
   `register({id})` call.
3. Diff: any backend id with no matching FE registration → fail.
4. Any FE registration with no matching backend provider → fail.

Hooks into `hydra/scripts/run-hydra-gates.sh` as gate #15. Per
ADR-019, tab-only or widget-only integrations are NOT permitted.

## Migration risks

- **Schema linkedTypes referencing not-yet-registered ids**: handled
  per ADR-019 — validation is permissive on read (warns), strict on
  write only when adding.
- **External consumers of `TYPE_COLUMN_MAP`**: the constant is
  private-by-convention; we mark `@deprecated` here, remove in a
  follow-up cleanup change once built-in providers stabilise.

## Open design questions

1. **Should `IntegrationProvider::query()` return an iterable or a
   collection?** Iterable wins for streaming, but most consumers
   want a count + first 10 — a wrapper class might be cleaner.
   Defer; v1 returns iterable, consumers wrap as needed.
2. **Per-provider settings page?** ADR-019 mentions "unified admin UI"
   for auth status. Out of scope for this change; surfaced via
   capabilities + each provider's `authRequirements()`. A separate
   admin-UI change builds the unified page.
3. **`@deprecated TYPE_COLUMN_MAP` removal timing?** Per the migration
   rules, deprecate-then-remove takes one release. Follow-up change
   tracks the removal.
