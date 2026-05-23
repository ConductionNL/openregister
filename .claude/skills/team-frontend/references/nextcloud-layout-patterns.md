#### Nextcloud Page Layout

All apps MUST use the standard Nextcloud layout containers from `@nextcloud/vue`. Nextcloud defines two primary layout patterns — choose the one that fits your app.

**Reference:** [Nextcloud Layout Docs](https://docs.nextcloud.com/server/latest/developer_manual/design/layout.html) | [NcAppContent API](https://nextcloud-vue-components.netlify.app/#/Components/App%20containers/NcAppContent)

##### Pattern 1: Navigation → Content → Sidebar
**Used by:** Files, Calendar, Deck, Tasks

```
┌──────────────────────────────────────────────────┐
│  NcContent                                       │
│ ┌──────────┬────────────────────┬──────────────┐ │
│ │NcApp     │ NcAppContent       │ NcAppSidebar │ │
│ │Navigation│                    │ (optional,   │ │
│ │          │                    │  closed by   │ │
│ │          │                    │  default)    │ │
│ └──────────┴────────────────────┴──────────────┘ │
└──────────────────────────────────────────────────┘
```

- Left: `NcAppNavigation` — navigation items, filters, categories
- Center: `NcAppContent` — main content (changes based on navigation)
- Right: `NcAppSidebar` — item detail panel (hidden by default, opens on item select)
- **Mobile:** Content shows by default; navigation and sidebar expand via icons
- **Variation (no sidebar):** For apps that don't need item details (e.g., Activities)
- **Variation (list in navigation):** Navigation contains a scrollable list of items (e.g., Talk)

##### Pattern 2: Navigation → List → Content
**Used by:** Mail, Contacts

```
┌──────────────────────────────────────────────────┐
│  NcContent                                       │
│ ┌──────────┬──────────────┬────────────────────┐ │
│ │NcApp     │ List         │ NcAppContent       │ │
│ │Navigation│ (NcAppContent│ (detail view)      │ │
│ │          │  with list)  │                    │ │
│ │          │              │                    │ │
│ └──────────┴──────────────┴────────────────────┘ │
└──────────────────────────────────────────────────┘
```

- Left: `NcAppNavigation` — categories/folders
- Center: List of entries (rendered in `NcAppContent`)
- Right: Content/detail for selected entry
- **Mobile:** List shows by default; navigation via top-left icon; back arrow to return

##### Layout Implementation Example

```vue
<template>
    <NcContent app-name="myapp">
        <NcAppNavigation>
            <NcAppNavigationItem
                v-for="item in navItems"
                :key="item.id"
                :name="item.name"
                :to="{ name: item.route }" />
        </NcAppNavigation>

        <NcAppContent>
            <router-view />
        </NcAppContent>

        <NcAppSidebar
            v-if="sidebarOpen"
            :name="selectedItem?.name || ''"
            @close="closeSidebar">
            <NcAppSidebarTab id="details" :name="t('myapp', 'Details')" :order="1">
                <!-- Detail content -->
            </NcAppSidebarTab>
        </NcAppSidebar>
    </NcContent>
</template>
```

Rules:
- `NcContent` wraps the entire app — always set `app-name` prop
- `NcAppNavigation` is the leftmost panel — keep it narrow
- `NcAppContent` holds the main view — this is where `<router-view>` goes
- `NcAppSidebar` is closed by default — open it on item selection
- Consistency: pick ONE layout pattern per app and use it everywhere
- Responsiveness is handled by the Nextcloud components — don't override their responsive behavior
