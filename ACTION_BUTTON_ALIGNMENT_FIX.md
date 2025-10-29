# Action Button Alignment Fix for NcSettingsSection

## Problem

Action buttons were appearing **below** the section titles instead of aligned with them. This was because `NcSettingsSection` has its own internal structure with an H2 wrapper, which we weren't accounting for.

## Root Cause

`NcSettingsSection` wraps the title in an H2 element with its own styling and positioning. Our action buttons were positioned relative to the content area, not the H2 title.

### NcSettingsSection Structure
```html
<div class="settings-section">
  <div class="settings-section__header">
    <h2>Section Title</h2>  <!-- Internal H2 wrapper -->
  </div>
  <div class="settings-section__desc">Description</div>
  <div class="settings-section__content">
    <!-- Our action buttons were here -->
  </div>
</div>
```

## Solution

Increased the negative `top` value to account for the H2 wrapper's height:

### Before (OpenConnector pattern)
```css
.section-header-inline {
	top: -45px;
	margin-bottom: -40px;
}
```

### After (Adjusted for NcSettingsSection)
```css
.section-header-inline {
	top: -87px;        /* +42px to reach the H2 level */
	margin-bottom: -82px;  /* Adjusted to compensate */
}
```

## Calculation

- **Original offset**: `-45px` (OpenConnector pattern)
- **Additional offset needed**: `-42px` (for NcSettingsSection's H2 structure)
- **New offset**: `-87px` (`-45px - 42px`)
- **Margin compensation**: `-82px` (`-40px - 42px`)

## Visual Result

### Before ❌
```
┌────────────────────────────────────────┐
│ System Statistics                      │ ← H2 Title
│ Overview of your data                  │
│                          [Refresh]     │ ← Buttons too low
│ Content...                             │
└────────────────────────────────────────┘
```

### After ✅
```
┌────────────────────────────────────────┐
│ System Statistics         [Refresh]   │ ← Same line!
│ Overview of your data                  │
│ Content...                             │
└────────────────────────────────────────┘
```

## Files Modified

1. **`src/components/shared/SettingsSection.vue`**
   - Changed `top: -45px` to `top: -87px`
   - Changed `margin-bottom: -40px` to `margin-bottom: -82px`
   - Added comment explaining adjustment for NcSettingsSection

2. **`src/components/shared/VersionInfoCard.vue`**
   - Added `margin-top: 20px` to `.version-info` for proper content spacing

## Why This Differs from OpenConnector

OpenConnector uses the same `top: -45px` value, but they may:
1. Have different NcSettingsSection versions
2. Use custom styling on their sections
3. Position their sections differently

Our adjustment of +42px is specific to how `NcSettingsSection` renders in our Nextcloud environment.

## Testing

✅ Buttons now align with section titles  
✅ No linter errors  
✅ Responsive design still works  
✅ All sections (including VersionInfoCard) aligned consistently  

## Compatibility

This change affects all components using `SettingsSection`:
- ✅ VersionInfoCard
- ✅ All section components using the shared SettingsSection
- ✅ Future components using SettingsSection

## Date

2025-10-29

