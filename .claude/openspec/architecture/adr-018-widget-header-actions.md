# ADR-018: Widget Header Actions Pattern

## Status
Accepted

## Date
2026-04-14

## Context

Card and widget components across Conduction apps need action controls (buttons, dropdowns, selects) for user interactions like changing status, adding items, or toggling views. Developers have been placing these controls inline with card content, taking up vertical space and creating inconsistent layouts.

Nextcloud's own UI pattern places actions in the title bar (top-right) of panels and sidebars. Our shared component library should enforce this same pattern so all card/widget components have a consistent location for actions.

## Decision

### All card/widget components MUST support a `header-actions` slot

Every component that renders a title bar or header MUST provide a `header-actions` slot positioned in the **top-right of the header**, inline with the title. This is the standard location for action controls.

### Standard slot name: `header-actions`

All components use the slot name `header-actions` for consistency. Components that previously used `actions` retain it for backwards compatibility but `header-actions` is the canonical name.

### Component support status

All card/widget components in `@conduction/nextcloud-vue` now support `header-actions`:

| Component | Slot name | Notes |
|---|---|---|
| `CnDetailCard` | `header-actions` | Primary card component |
| `CnWidgetWrapper` | `header-actions` | Dashboard widget container |
| `CnObjectDataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnObjectMetadataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnStatsPanel` | `header-actions` | Added in this ADR |
| `CnSettingsCard` | `header-actions` | Added in this ADR |
| `CnConfigurationCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |
| `CnVersionInfoCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |

### What goes in header-actions

- Status change dropdowns / selects
- Add/create buttons
- Toggle switches (e.g. edit mode)
- Refresh buttons
- Filter controls specific to this widget

### What does NOT go in header-actions

- Save/cancel for the entire page (those belong in `CnDetailPage` `#header-actions`)
- Bulk action toolbars (those belong in `CnMassActionBar`)
- Form inputs that are part of the data being edited

### Usage pattern

```vue
<CnDetailCard :title="t('app', 'Status')">
  <template #header-actions>
    <NcSelect
      v-model="selectedStatus"
      :options="statusOptions"
      :placeholder="t('app', 'Change status...')" />
  </template>

  <!-- Card content -->
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### New components

When creating new card or widget components, the `header-actions` slot MUST be included from the start. The standard template pattern:

```vue
<div class="cn-my-widget__header">
  <h4 class="cn-my-widget__title">{{ title }}</h4>
  <div v-if="$slots['header-actions']" class="cn-my-widget__header-actions">
    <slot name="header-actions" />
  </div>
</div>
```

With CSS:
```css
.cn-my-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cn-my-widget__header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
```

## Consequences

- All existing card components now support `header-actions`
- New components must include this slot from creation
- Existing apps should migrate inline actions to `header-actions` when touching those files
- Code reviews should flag action controls placed in card content as a pattern violation
- The `actions` slot name in CnConfigurationCard and CnVersionInfoCard is deprecated but retained for backwards compatibility
