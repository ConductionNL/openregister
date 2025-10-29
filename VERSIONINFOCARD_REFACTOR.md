# VersionInfoCard Refactored to Use SettingsSection

## Problem

`VersionInfoCard.vue` was duplicating the functionality of `SettingsSection.vue`:
- Both wrapped `NcSettingsSection`
- Both implemented the `.section-header-inline` pattern
- Both handled action button positioning
- Duplicated CSS for the OpenConnector pattern

This violated the DRY (Don't Repeat Yourself) principle.

## Solution

Refactored `VersionInfoCard` to **use** `SettingsSection` instead of reimplementing it.

## Changes Made

### 1. Component Composition
**Before:**
```vue
<NcSettingsSection>
  <div class="section-header-inline">...</div>
  <!-- content -->
</NcSettingsSection>
```

**After:**
```vue
<SettingsSection>
  <template #actions>...</template>
  <!-- content -->
</SettingsSection>
```

### 2. Import Changes
**Before:**
```javascript
import { NcSettingsSection, NcLoadingIcon, NcButton } from '@nextcloud/vue'
```

**After:**
```javascript
import SettingsSection from './SettingsSection.vue'
import { NcLoadingIcon, NcButton } from '@nextcloud/vue'
```

### 3. Actions Slot Usage
**Before:**
```vue
<div v-if="$slots.actions || showUpdateButton" class="section-header-inline">
  <span />
  <div class="button-group">
    <NcButton>...</NcButton>
    <slot name="actions" />
  </div>
</div>
```

**After:**
```vue
<template #actions>
  <NcButton v-if="showUpdateButton">...</NcButton>
  <slot name="actions" />
</template>
```

### 4. Loading State Delegation
Now leverages `SettingsSection`'s built-in loading handling:
```vue
<SettingsSection 
  :loading="loading"
  loading-message="Loading version information...">
```

### 5. CSS Cleanup
**Removed** (handled by SettingsSection):
```css
.section-header-inline { ... }  /* 15 lines removed */
.button-group { ... }            /* 5 lines removed */
/* Mobile responsive for actions */ /* 10 lines removed */
```

**Kept** (VersionInfoCard-specific):
```css
.version-card { ... }
.version-item { ... }
.version-label { ... }
.version-value { ... }
.status-* { ... }
```

## Benefits

✅ **No Duplication** - Single implementation of the OpenConnector pattern  
✅ **Consistency** - All sections (including VersionInfoCard) behave identically  
✅ **Maintainability** - Changes to action button positioning only need to be made in one place  
✅ **Smaller Code** - Removed ~30 lines of duplicated CSS and template logic  
✅ **Better Separation** - VersionInfoCard focuses only on version display logic  
✅ **Easier Testing** - Less code to test, clearer responsibilities  

## Component Hierarchy

```
Settings.vue
  └─ VersionInfoCard.vue
       └─ SettingsSection.vue (shared component)
            └─ NcSettingsSection (Nextcloud component)
  └─ SolrConfiguration.vue
       └─ NcSettingsSection (uses same pattern directly)
  └─ LlmConfiguration.vue
       └─ NcSettingsSection (uses same pattern directly)
  └─ ... other sections
```

## Usage Example

```vue
<VersionInfoCard
  app-name="Open Register"
  app-version="1.0.0"
  :is-up-to-date="true"
  :show-update-button="true"
  title="Version Information"
  description="Current installation details">
  <!-- Additional actions slot (optional) -->
  <template #actions>
    <NcActions>
      <NcActionButton>Load Schemas</NcActionButton>
    </NcActions>
  </template>
</VersionInfoCard>
```

## Impact

- **Breaking Changes**: None - API remains the same
- **Visual Changes**: None - looks identical
- **Behavioral Changes**: None - functions the same
- **Performance**: Slightly better (less code to execute)

## Files Modified

1. `openregister/src/components/shared/VersionInfoCard.vue`
   - Now uses `SettingsSection` component
   - Removed duplicated CSS
   - Simplified template structure

## Related Components

- `SettingsSection.vue` - The reusable base component
- `Settings.vue` - Uses VersionInfoCard
- All section components - Use the same pattern

## Testing

✅ No linter errors  
✅ Component structure validated  
✅ Props and events unchanged (backward compatible)  
✅ Slots work correctly  

## Date

2025-10-29

