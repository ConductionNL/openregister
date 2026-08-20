# Pluggable Integration Registry — How to add an integration

OpenRegister's object surfaces — the per-object **sidebar tabs**, the **dashboard widgets**, the **detail-page widgets**, and **reference properties** in forms — are not hard-coded. They are driven by a registry of *integration providers*. Each provider exposes a small contract on the PHP side (data access, auth requirements, health) and a matching pair of Vue components on the JS side (a sidebar `tab` and a `widget`). Apps register their own providers without touching OpenRegister core; OpenConnector-backed integrations (xWiki, Confluence, …) plug in the same way.

This page is the worked walkthrough. The normative contract lives in [`openspec/changes/pluggable-integration-registry/design.md`](../../openspec/changes/pluggable-integration-registry/design.md).

## The five built-ins

| id | storage strategy | tab | widget | required app |
|---|---|---|---|---|
| `files` | `magic-column` | `CnFilesTab` | `CnFilesCard` | — (OpenRegister) |
| `notes` | `link-table` | `CnNotesTab` | `CnNotesCard` (adapter) | — |
| `tags` | `link-table` | `CnTagsTab` | `CnTagsCard` | — |
| `tasks` | `link-table` | `CnTasksTab` | `CnTasksCard` (adapter) | — |
| `audit-trail` | `query-time` | `CnAuditTrailTab` | `CnAuditTrailCard` | — |

Storage strategies (AD-22): `magic-column` (a column on the object table), `link-table` (a join table), `external` (lives in another service, reached via OpenConnector), `query-time` (computed per request, never stored).

## Quickstart — scaffold a leaf change

```bash
# from the openregister repo root
scripts/scaffold-integration.sh contacts
```

This creates `openspec/changes/integration-contacts/` with:
- `proposal.md` + `tasks.md` (skeleton, within the ADR-028 15-task cap)
- `hydra.json` with `depends_on: ["pluggable-integration-registry"]`
- a PHP `ContactsProvider` stub extending `AbstractIntegrationProvider`
- a JS registration stub (`src/integrations/builtin/contacts.js` shape, to be moved into the consuming app)

Then flesh out the stubs following the steps below.

## Step 1 — PHP provider

Create `lib/Service/Integration/.../ContactsProvider.php` extending `AbstractIntegrationProvider`:

```php
final class ContactsProvider extends AbstractIntegrationProvider
{
    public function getId(): string { return 'contacts'; }
    public function getLabel(): string { return $this->l10n->t('Contacts'); }
    public function getIcon(): string { return 'AccountBox'; }       // MDI name
    public function getGroup(): ?string { return 'comms'; }          // core|comms|docs|workflow|external
    public function getRequiredApp(): ?string { return 'contacts'; } // hidden when this NC app isn't enabled
    public function getStorageStrategy(): string { return 'link-table'; }
    public function isEnabled(): bool { return $this->appManager->isEnabledForUser('contacts'); }

    public function list(string $register, string $schema, string $objectId, array $filters = []): array { /* … */ }
    // get / create / update / delete as needed; inherited defaults throw NotImplementedException
}
```

For an **external** provider, return `'external'` from `getStorageStrategy()` and the OpenConnector source id from `getOpenConnectorSource()`; data access then routes through `ExternalIntegrationRouter` (which surfaces `ProviderUnavailableException` with a `cause` so the UI degrades gracefully). Credentials live in OpenConnector — the provider never handles them; it declares them via `authRequirements()` and OpenRegister's admin UI links out to OpenConnector's credential screen (AD-15).

Register the provider at app bootstrap (the built-ins use `IntegrationRegistry::addProvider()` from `Application::boot()`):

```php
$registry = $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class);
$registry->addProvider($container->get(ContactsProvider::class));
```

## Step 2 — JS components

You need **both** a `tab` and a `widget` component — this is the parity contract (AD-11/AD-13), enforced by `npm run check:integration-parity` in `@conduction/nextcloud-vue` and by the registry throwing at `register()` time. The widget can be a thin shell around the tab's data for an MVP.

Both components receive an object context as props: `register`, `schema`, `objectId` (and `apiBase`). The widget additionally receives a `surface` prop — one of `user-dashboard | app-dashboard | detail-page | single-entity` (AD-19). A single widget component can branch on `surface`, or you can register surface-specific overrides (`widgetCompact` / `widgetExpanded` / `widgetEntity`); absent overrides fall back to the main `widget`.

## Step 3 — register on the JS registry

From the consuming app's bootstrap, after OpenRegister's bundle has loaded:

```js
import CnContactsTab from './CnContactsTab.vue'
import CnContactsCard from './CnContactsCard.vue'

window.OCA.OpenRegister.integrations.register({
    id: 'contacts',
    label: t('myapp', 'Contacts'),
    icon: 'AccountBox',
    requiredApp: 'contacts',
    order: 10,
    group: 'comms',
    referenceType: 'contacts',     // schema props of type 'reference' may target this integration (AD-18)
    tab: CnContactsTab,            // REQUIRED
    widget: CnContactsCard,        // REQUIRED — receives :surface
    defaultSize: { w: 3, h: 3 },
})
```

If your app's bundle might load *before* OpenRegister's, install a queue stub first (OpenRegister replays it):

```js
window.OCA = window.OCA || {}
window.OCA.OpenRegister = window.OCA.OpenRegister || {}
window.OCA.OpenRegister.integrations = window.OCA.OpenRegister.integrations || {
    _queue: [], register(e) { this._queue.push(e) },
}
```

**Collision policy (AD-13):** registering an `id` that's already taken throws in development and warns + keeps the first registration in production. So a consuming app can pre-register an `id` to override a built-in.

## Step 4 — surfaces light up automatically

- `CnObjectSidebar` with `:use-registry="true"` renders one tab per registered provider (filtered by `excludeIntegrations` / `hiddenTabs`).
- `CnDashboardPage` / `CnDetailPage` render a widget for any layout item with `{ type: 'integration', integrationId: '<id>' }` — the component is resolved via `resolveWidget(integrationId, surface)`.
- A schema property carrying `referenceType: '<id>'` renders that integration's single-entity widget in `CnFormDialog` and `CnDetailGrid` instead of a plain input/value.

## Step 5 — admin surface & health

OpenRegister's **Administration → OpenRegister → Integrations** page lists every registered provider with its storage strategy, required app, `isEnabled()` result, and (for external providers) auth status from `probe()` plus a "Configure" deep-link into OpenConnector. The same status is advertised in the OCS capabilities response (`/ocs/v2.php/cloud/capabilities`), redacted per role (AD-17). You don't wire any of this up — it's automatic once the provider is registered.

## Reference

- Contract & ADs: [`openspec/changes/pluggable-integration-registry/design.md`](../../openspec/changes/pluggable-integration-registry/design.md)
- JS API in `@conduction/nextcloud-vue`: `integrations`, `installIntegrationRegistry`, `registerBuiltinIntegrations`, `useIntegrationRegistry`, `VALID_SURFACES` (documented in that repo's `CLAUDE.md` and `docs/utilities/`)
- xWiki leaf (worked external example): `openspec/changes/integration-xwiki/`
