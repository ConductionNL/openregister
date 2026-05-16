---
title: Leaf integration system
sidebar_position: 2
description: How Open Register surfaces NC apps and external services as pluggable "leaves" — one provider contract, one UI registry, every leaf wires the same way.
keywords:
  - Open Register
  - Integrations
  - Pluggable integration registry
  - Leaf
  - ADR-019
---

import {LeafCard, LeafGrid, Pair} from '@conduction/docusaurus-preset/components';

# Leaf integration system

Every "linked thing" on an Open Register object — a meeting, a contact, a chat room, a wiki page — is a **leaf**. Each leaf implements the same provider contract on the backend and registers the same way on the frontend. The result: one sidebar tab pattern, one widget pattern, one admin page that lists every leaf with its health status. Apps install only the leaves they need; the rest stay hidden.

This page documents the system. Each leaf has its own page under [Integrations](./index.md).

<Pair
  leftLabel="Open Register"
  leftCaption="object · sidebar · dashboard"
  rightLabel="Leaf integration"
  rightCaption="NC app · external service"
  rightColor="cobalt-700"
  bridgeLabel="IntegrationProvider contract" />

## What a leaf gives you

- A **sidebar tab** on every object detail page. One tab per registered leaf, filtered to the apps the user has installed.
- A **dashboard widget** in four surfaces: user-dashboard, app-dashboard, detail-page, single-entity. The same registration drives all four.
- A **reference-property renderer**. A schema property typed as `referenceType: '<leaf-id>'` renders the linked entity inline in `CnFormDialog` and `CnDetailGrid`.
- An **admin row** under Administration → Open Register → Integrations. Reports install state, auth status, storage strategy, and a deep-link to configure the upstream source.
- An **OCS capability entry** under `openregister.integrations.providers`. Clients discover the registry surface without probing routes.

All of this for one provider class and one registry descriptor per leaf.

## The 18 leaves Open Register ships

<LeafGrid columns={3}>
  <LeafCard id="files" label="Files" icon="Paperclip" group="core" requiredApp={null} storage="magic-column" status="built-in" href="../features/files" description="Files attached to an object. Always available." />
  <LeafCard id="notes" label="Notes" icon="CommentTextOutline" group="core" requiredApp={null} storage="link-table" status="built-in" href="./notes" description="Free-form notes on an object. Always available." />
  <LeafCard id="tags" label="Tags" icon="TagOutline" group="core" requiredApp={null} storage="link-table" status="built-in" href="./tags" description="System tags on an object. Always available." />
  <LeafCard id="tasks" label="Tasks" icon="CheckboxMarkedOutline" group="core" requiredApp={null} storage="link-table" status="built-in" href="./tasks" description="To-do items on an object. Always available." />
  <LeafCard id="audit-trail" label="Audit trail" icon="History" group="core" requiredApp={null} storage="query-time" status="built-in" href="./audit-trail" description="Every change to an object. Read-only, always available." />
  <LeafCard id="shares" label="Shares" icon="Share" group="core" requiredApp={null} storage="query-time" status="backend-ready" href="./shares" description="NC Share Manager-backed share visibility per object." />
  <LeafCard id="calendar" label="Meetings" icon="Calendar" group="comms" requiredApp="calendar" storage="link-table" status="backend-ready" href="./calendar" description="CalDAV meetings linked to an object." />
  <LeafCard id="contacts" label="Contacts" icon="AccountBox" group="comms" requiredApp="contacts" storage="link-table" status="backend-ready" href="./contacts" description="vCard contacts linked to an object, with role." />
  <LeafCard id="email" label="Emails" icon="Email" group="comms" requiredApp="mail" storage="link-table" status="backend-ready" href="./email" description="Link existing NC Mail messages to an object." />
  <LeafCard id="talk" label="Chat" icon="ChatOutline" group="comms" requiredApp="spreed" storage="link-table" status="stub" href="./talk" description="Talk conversations linked to an object. Provider stub." />
  <LeafCard id="bookmarks" label="Bookmarks" icon="Bookmark" group="docs" requiredApp="bookmarks" storage="link-table" status="stub" href="./bookmarks" description="NC Bookmarks linked to an object. Provider stub." />
  <LeafCard id="collectives" label="Knowledge" icon="BookOpenPageVariant" group="docs" requiredApp="collectives" storage="link-table" status="stub" href="./collectives" description="Collectives pages linked to an object. Provider stub." />
  <LeafCard id="maps" label="Location" icon="MapMarker" group="docs" requiredApp="maps" storage="link-table" status="stub" href="./maps" description="NC Maps locations linked to an object. Provider stub." />
  <LeafCard id="photos" label="Photos" icon="Image" group="docs" requiredApp="photos" storage="link-table" status="stub" href="./photos" description="NC Photos linked to an object with EXIF metadata. Provider stub." />
  <LeafCard id="activity" label="Activity" icon="Timeline" group="workflow" requiredApp="activity" storage="query-time" status="stub" href="./activity" description="NC Activity events relevant to an object. Read-only stub." />
  <LeafCard id="analytics" label="Analytics" icon="ChartBar" group="workflow" requiredApp="analytics" storage="link-table" status="stub" href="./analytics" description="NC Analytics reports linked to an object. Provider stub." />
  <LeafCard id="cospend" label="Costs" icon="CurrencyEur" group="workflow" requiredApp="cospend" storage="link-table" status="stub" href="./cospend" description="NC Cospend projects/bills linked to an object. Provider stub." />
  <LeafCard id="deck" label="Cards" icon="ViewColumnOutline" group="workflow" requiredApp="deck" storage="link-table" status="backend-ready" href="./deck" description="NC Deck cards linked to or created from an object." />
  <LeafCard id="flow" label="Automation" icon="RobotOutline" group="workflow" requiredApp="workflowengine" storage="link-table" status="stub" href="./flow" description="NC Flow rules scoped to a schema/object. Provider stub." />
  <LeafCard id="forms" label="Forms" icon="ClipboardText" group="workflow" requiredApp="forms" storage="link-table" status="stub" href="./forms" description="NC Forms responses linked to an object. Provider stub." />
  <LeafCard id="polls" label="Polls" icon="Poll" group="workflow" requiredApp="polls" storage="link-table" status="stub" href="./polls" description="NC Polls linked to an object. Provider stub." />
  <LeafCard id="time-tracker" label="Time" icon="Clock" group="workflow" requiredApp="timemanager" storage="link-table" status="stub" href="./time-tracker" description="NC time tracking entries linked to an object. Provider stub." />
  <LeafCard id="xwiki" label="Articles" icon="FileDocumentMultiple" group="external" requiredApp="openconnector" storage="external" status="external" href="./xwiki" description="XWiki pages linked to an object. Routed through OpenConnector." />
  <LeafCard id="openproject" label="Projects" icon="Briefcase" group="external" requiredApp="openconnector" storage="external" status="external" href="./openproject" description="OpenProject work packages. Routed through OpenConnector." />
</LeafGrid>

## How a leaf is wired

Each leaf has three pieces. The provider is server-side; the registration is in `@conduction/nextcloud-vue`; the activation is in the consuming app's `main.js`. Every consuming app picks up every leaf automatically — there is no per-app glue.

### 1. PHP provider (`openregister/lib/Service/Integration/Providers/`)

```php
class CalendarProvider extends AbstractIntegrationProvider
{
    public function getId(): string { return 'calendar'; }
    public function getLabel(): string { return $this->l10n->t('Meetings'); }
    public function getIcon(): string { return 'Calendar'; }
    public function getGroup(): ?string { return 'comms'; }
    public function getRequiredApp(): ?string { return 'calendar'; }
    public function getStorageStrategy(): string { return 'link-table'; }
    public function isEnabled(): bool { return $this->appManager->isInstalled('calendar'); }

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return $this->calendarEventService->getEventsForObject(objectUuid: $objectId);
    }

    // get / create / update / delete as the storage strategy supports.
}
```

The provider is registered with the DI container in `Application::register()` and pushed onto the `IntegrationRegistry` in `Application::boot()`. Storage strategy is `'magic-column' | 'link-table' | 'external' | 'query-time'` (AD-22).

### 2. Vue registration (`@conduction/nextcloud-vue/src/integrations/builtin/leaves.js`)

```js
import CnIntegrationTab from '../../components/CnIntegrationTab/CnIntegrationTab.vue'
import CnIntegrationCard from '../../components/CnIntegrationCard/CnIntegrationCard.vue'

window.OCA.OpenRegister.integrations.register({
  id: 'calendar',
  label: t('myapp', 'Meetings'),
  icon: 'Calendar',
  group: 'comms',
  requiredApp: 'calendar',
  order: 20,
  referenceType: 'calendar',
  tab: CnIntegrationTab,
  widget: CnIntegrationCard,
  defaultSize: { w: 4, h: 3 },
})
```

The generic `CnIntegrationTab` + `CnIntegrationCard` drive every leaf until any individual leaf needs a bespoke component, at which point the registration's `tab` / `widget` is repointed at a dedicated Vue file (the xWiki leaf has its own `CnXwikiTab` / `CnXwikiCard` already).

### 3. App-side activation (`{consuming-app}/src/main.js`)

```js
import {
  installIntegrationRegistry,
  registerBuiltinIntegrations,
  registerLeafIntegrations,
} from '@conduction/nextcloud-vue'

installIntegrationRegistry()
registerBuiltinIntegrations()  // 5 always-on (files, notes, tags, tasks, audit-trail)
registerLeafIntegrations()     // 18 NC-app and external leaves
```

That's the full wiring. Three calls. Every leaf the user has the required NC app for shows up automatically.

## Storage strategies

Leaves declare one of four storage strategies. The registry uses this to choose the dispatch path; the docs page for each leaf explains the specifics.

| Strategy | Where the link lives | Example leaves |
|---|---|---|
| `magic-column` | A column on the object's table row. Cheapest. | Files |
| `link-table` | A dedicated join table (`openregister_{leaf}_links`). | Notes, Tags, Tasks, Calendar, Contacts, Deck, Email |
| `external` | Nowhere local. Routed through OpenConnector on every CRUD. | xWiki, OpenProject |
| `query-time` | Nowhere local. Computed fresh on every `list()` call. | Audit trail, Activity, Shares |

`'query-time'` providers throw `NotImplementedException` on `create()` / `update()` / `delete()` per AD-22 — there is no local store to write to.

## Required-app gating

Every NC-app-backed leaf declares its `requiredApp`. The registry filters in three stages (AD-5):

1. **PHP `isEnabled()`** — returns false when the required NC app isn't installed. The OCS capabilities response marks the leaf `enabled: false`.
2. **JS-side filter** — `CnObjectSidebar :use-registry` honours the same gate. Disabled leaves don't render a tab.
3. **Admin UI** — the integrations page still lists disabled leaves so admins know what's available, with a "needs `<app>` installed" message and an install hint.

Built-in leaves (`files`, `notes`, `tags`, `tasks`, `audit-trail`) and the `shares` core leaf return `requiredApp: null` — they ride on Open Register itself and are always available.

## Collision policy (AD-13)

Re-registering an existing leaf id is a no-op in production (the first registration wins) and throws in development. So a consuming app can pre-register a leaf id with a bespoke `tab` / `widget` to override the generic pair without touching the library:

```js
import CnMyAppCalendarTab from './components/CnMyAppCalendarTab.vue'
import CnMyAppCalendarCard from './components/CnMyAppCalendarCard.vue'

// Run this BEFORE registerLeafIntegrations() so the override wins.
window.OCA.OpenRegister.integrations.register({
  id: 'calendar',  // same id as the generic registration
  label: t('myapp', 'Meetings'),
  icon: 'Calendar',
  group: 'comms',
  requiredApp: 'calendar',
  tab: CnMyAppCalendarTab,    // bespoke
  widget: CnMyAppCalendarCard, // bespoke
})

registerLeafIntegrations()  // no-op on 'calendar' — first wins
```

This is how xWiki ships its richer `CnXwikiTab` (breadcrumbs, text-preview) on top of the same `id: 'xwiki'` slot.

## Surfaces (AD-19)

Every leaf renders in up to four surfaces from the same registration. The widget component receives a `surface` prop and branches on it.

| Surface | Where it appears | Default behaviour |
|---|---|---|
| `detail-page` | Object detail page | Full linked-list with row actions |
| `user-dashboard` | Personal Open Register dashboard | Compact list, max 5 entries |
| `app-dashboard` | Per-app dashboard widget | Compact list, scoped to the app |
| `single-entity` | A schema property of type `reference` with `referenceType: '<id>'` | A chip resolved by id |

The registration descriptor can pass surface-specific overrides (`widgetCompact`, `widgetExpanded`, `widgetEntity`); absent overrides fall back to the main `widget`.

## What to read next

- **[xWiki leaf](./xwiki.md)** — the worked external example (OpenConnector-backed).
- **[Calendar leaf](./calendar.md)** — the worked NC-native example (CalDAV link-table).
- **[Pluggable integration registry reference](./pluggable-integration-registry.md)** — the full ADR-019 contract.
- **[Verification report](./verification-report.md)** — live status for all 24 advertised providers from the smoke harness.
- **Build your own leaf** — `scripts/scaffold-integration.sh <id>` in the openregister repo generates the openspec change, PHP provider stub, and JS registration stub.

:::tip Add your own leaf
Pre-register an id before `registerLeafIntegrations()` and you can ship a bespoke Vue tab / widget for any leaf. The library never clobbers an existing registration.
:::
