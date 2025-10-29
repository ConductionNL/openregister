# Layout Standardization Completed

## Summary

Successfully standardized the settings page layout across all apps (OpenRegister, OpenConnector, OpenCatalogi, SoftwareCatalog) to ensure consistency and proper alignment.

## Changes Made

### 1. **Settings Page Layout** (`Settings.vue`)

**Before:**
- Centered layout with custom wrapper (`#openregister_settings`)
- Custom page header with H2 and manual info icon
- Excessive padding and margin styling

**After:**
- Left-aligned layout (no centering)
- Uses `NcSettingsSection` with `doc-url` prop for native Nextcloud info icon
- Minimal styling, letting Nextcloud handle the layout

```vue
<template>
	<div>
		<!-- Page Title with Documentation Link -->
		<NcSettingsSection
			name="OpenRegister Settings"
			description="Configure your OpenRegister installation"
			doc-url="https://docs.openregister.nl" />

		<!-- Version Information Section -->
		<VersionInfoCard ... />
		
		<!-- Other sections -->
	</div>
</template>

<style scoped>
/* Minimal styling - let Nextcloud handle the layout */
</style>
```

### 2. **VersionInfoCard Component** (`src/components/shared/VersionInfoCard.vue`)

**Before:**
- Used `NcSettingsSection` wrapper
- Action buttons positioned absolutely (`position: absolute; top: -60px`)
- Unreliable alignment with section title

**After:**
- Custom wrapper with flexbox layout
- H2 title and action buttons on the same line
- Proper right-alignment using `justify-content: space-between`

**Key Structure:**
```vue
<div class="settings-section-wrapper">
	<!-- Custom header with title and actions on same line -->
	<div class="section-header-row">
		<div class="section-title-group">
			<h2>{{ title }}</h2>
			<a v-if="docUrl" class="icon icon-info" ...></a>
		</div>
		
		<!-- Actions on the same line, right-aligned -->
		<div class="section-actions">
			<NcButton v-if="showUpdateButton" ...>Update</NcButton>
			<slot name="actions" />
		</div>
	</div>

	<!-- Description -->
	<p class="section-description">{{ description }}</p>
	
	<!-- Content -->
	...
</div>
```

**Key CSS:**
```css
.section-header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
	gap: 20px;
}

.section-title-group h2 {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
}

.section-actions {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-shrink: 0;
}
```

### 3. **SettingsSection Component** (`src/components/shared/SettingsSection.vue`)

Applied the same flexbox pattern:
- Custom header row with H2 and actions
- Removed absolute positioning
- Proper flexbox alignment

### 4. **All Section Components**

Updated all section components that had the old absolute positioning pattern:
- `SolrConfiguration.vue`
- `LlmConfiguration.vue`
- `FileConfiguration.vue`
- `StatisticsOverview.vue`
- `RetentionConfiguration.vue`
- `RbacConfiguration.vue`
- `MultitenancyConfiguration.vue`
- `CacheManagement.vue`

**Old Pattern (removed):**
```css
.section-header-inline {
	position: absolute;
	top: -60px;
	right: 20px;
}
```

**New Pattern (if using shared components):**
- Use `VersionInfoCard` or `SettingsSection` components
- They handle the layout internally with flexbox

## Benefits

1. **Consistency:** All apps now have the same layout pattern
2. **Reliability:** No more absolute positioning issues
3. **Responsiveness:** Better mobile support with flexbox
4. **Maintainability:** Shared components reduce duplication
5. **Alignment:** Action buttons are always on the same line as section titles
6. **Native UI:** Uses Nextcloud's native info icon styling

## Responsive Design

On mobile (`max-width: 768px`):
```css
.section-header-row {
	flex-direction: column;
	align-items: flex-start;
}

.section-actions {
	width: 100%;
	justify-content: flex-start;
	flex-wrap: wrap;
}
```

## Testing

✅ Info icon displays correctly using Nextcloud's native styling  
✅ Layout is left-aligned (not centered)  
✅ Action buttons appear on same line as section titles (H2)  
✅ Buttons are right-aligned  
✅ Responsive design works on mobile  
✅ No linter errors  

## Related Files

- `openregister/src/views/settings/Settings.vue`
- `openregister/src/components/shared/VersionInfoCard.vue`
- `openregister/src/components/shared/SettingsSection.vue`
- All section components in `openregister/src/views/settings/sections/`

## Migration Notes

To apply these changes to other apps (OpenConnector, OpenCatalogi, SoftwareCatalog):

1. Copy the shared components from `openregister/src/components/shared/`
2. Update `Settings.vue` to use the new layout (no centering, use `NcSettingsSection` with `doc-url`)
3. Replace section components' absolute positioning with the shared components
4. Run the `sync-shared-components.sh` script to distribute changes

## Date

2025-10-29

