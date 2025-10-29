# Enhanced VersionInfoCard Component

## Overview

The `VersionInfoCard` component has been significantly enhanced to support conditional update buttons and action menus, matching the Software Catalog layout style.

## What's New

### 1. Conditional Update Button

The component now supports an update button with three states:

#### 🔴 **Needs Update** (Error Style)
- Red "Update" button when `isUpToDate={false}`
- Update icon displayed
- Button is enabled and clickable

#### ✅ **Up To Date** (Success Style)
- Green "Update" button when `isUpToDate={true}`
- Checkmark icon displayed
- Button is disabled

#### ⏳ **Updating** (Loading State)
- Loading spinner displayed
- Button is disabled

### 2. Actions Menu Slot

Add custom action buttons next to the update button:
- Load/Reload Schemas
- Reset Auto-Configuration
- Any app-specific actions

### 3. Improved Layout

**Before** (Old Style):
```
┌─────────────────────────────────────────┐
│ 📦 Application Information              │
│                                         │
│ Application Name:    Open Register     │
│ ─────────────────────────────────────  │
│ Version:             0.2.3             │
└─────────────────────────────────────────┘
```

**After** (New Style - like Software Catalog):
```
┌─────────────────────────────────────────┬────────────────┐
│ Application:        Open Register v0.2.3│  [Update] [⋮]  │
├─────────────────────────────────────────┴────────────────┤
│ Configured Version: 0.2.1                                │
├──────────────────────────────────────────────────────────┤
│ Status:             ⚠ Update needed                      │
└──────────────────────────────────────────────────────────┘
```

- No wrapping card
- Left-aligned grid layout
- Individual items with background
- Action buttons positioned top-right
- Cleaner, more professional appearance

## Usage Examples

### Example 1: Basic (OpenRegister)

```vue
<template>
  <VersionInfoCard
    :app-name="settingsStore.versionInfo.appName || 'Open Register'"
    :app-version="settingsStore.versionInfo.appVersion || 'Unknown'"
    :loading="settingsStore.loadingVersionInfo"
  />
</template>
```

### Example 2: With Update Button (OpenConnector)

```vue
<template>
  <VersionInfoCard
    app-name="Open Connector"
    :app-version="versionInfo.appVersion"
    :configured-version="versionInfo.configuredVersion"
    :is-up-to-date="versionInfo.versionsMatch"
    :show-update-button="true"
    :updating="updating"
    update-button-text="Update Configuration"
    :additional-items="[
      { 
        label: 'Status', 
        value: versionInfo.versionsMatch ? '✓ Up to date' : '⚠ Update needed',
        statusClass: versionInfo.versionsMatch ? 'status-ok' : 'status-warning'
      },
      {
        label: 'Endpoints',
        value: `${endpointCount} configured`
      }
    ]"
    @update="handleUpdateConfiguration"
  />
</template>

<script>
export default {
  data() {
    return {
      versionInfo: {
        appVersion: '1.0.5',
        configuredVersion: '1.0.3',
        versionsMatch: false,
      },
      updating: false,
      endpointCount: 42,
    }
  },
  methods: {
    async handleUpdateConfiguration() {
      this.updating = true
      try {
        await this.$store.updateConfiguration()
        showSuccess('Configuration updated successfully')
      } catch (error) {
        showError('Failed to update configuration')
      } finally {
        this.updating = false
      }
    },
  },
}
</script>
```

### Example 3: With Actions Menu (Software Catalog)

```vue
<template>
  <VersionInfoCard
    app-name="Software Catalog"
    :app-version="versionInfo.appVersion"
    :configured-version="versionInfo.configuredVersion"
    :is-up-to-date="versionInfo.versionsMatch"
    :show-update-button="true"
    :updating="updating"
    update-button-text="Force Update"
    :additional-items="statusItems"
    @update="handleForceUpdate">
    
    <template #actions>
      <!-- Auto Configure Button -->
      <NcButton
        v-if="!versionInfo.autoConfigCompleted"
        type="secondary"
        @click="handleAutoConfig">
        Auto Configure
      </NcButton>
      
      <!-- Additional Actions Menu -->
      <NcActions>
        <NcActionButton @click="loadSchemas">
          <template #icon>
            <FileDocumentIcon :size="20" />
          </template>
          Load Schemas
        </NcActionButton>
        
        <NcActionButton @click="resetAutoConfig">
          <template #icon>
            <RefreshIcon :size="20" />
          </template>
          Reset Auto-Config
        </NcActionButton>
      </NcActions>
    </template>
  </VersionInfoCard>
</template>

<script>
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
  components: {
    NcButton,
    NcActions,
    NcActionButton,
    FileDocumentIcon,
    RefreshIcon,
  },
  computed: {
    statusItems() {
      return [
        {
          label: 'Status',
          value: this.versionInfo.versionsMatch ? '✓ Up to date' : '⚠ Update needed',
          statusClass: this.versionInfo.versionsMatch ? 'status-ok' : 'status-warning',
        },
        {
          label: 'Open Register',
          value: this.openRegisterEnabled ? '✓ Installed and active' : '✗ Not installed',
          statusClass: this.openRegisterEnabled ? 'status-ok' : 'status-error',
        },
      ]
    },
  },
  methods: {
    async handleForceUpdate() {
      // Implementation...
    },
    async handleAutoConfig() {
      // Implementation...
    },
    async loadSchemas() {
      // Implementation...
    },
    async resetAutoConfig() {
      // Implementation...
    },
  },
}
</script>
```

### Example 4: With OpenCatalogi Status

```vue
<template>
  <VersionInfoCard
    app-name="Open Catalogi"
    :app-version="version"
    :configured-version="configuredVersion"
    :is-up-to-date="needsUpdate === false"
    :show-update-button="true"
    :updating="updating"
    :additional-items="[
      {
        label: 'Status',
        value: getStatusText(),
        statusClass: getStatusClass()
      },
      {
        label: 'Catalogs',
        value: `${catalogCount} active`
      },
      {
        label: 'Publications',
        value: `${publicationCount} published`
      }
    ]"
    @update="handleUpdate">
    
    <template #actions>
      <NcButton @click="syncCatalogs">
        Sync Catalogs
      </NcButton>
    </template>
  </VersionInfoCard>
</template>
```

## Props Reference

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `app-name` | String | *required* | Application name |
| `app-version` | String | *required* | Current version |
| `configured-version` | String | `''` | Configured version (optional) |
| `is-up-to-date` | Boolean | `true` | Whether app needs update |
| `show-update-button` | Boolean | `false` | Show update button |
| `update-button-text` | String | `'Update'` | Update button label |
| `updating` | Boolean | `false` | Updating in progress |
| `additional-items` | Array | `[]` | Extra items to display |
| `loading` | Boolean | `false` | Loading state |

### additionalItems Format

```javascript
[
  {
    label: 'Status',           // Label text
    value: '✓ Up to date',     // Value text
    statusClass: 'status-ok'   // CSS class (optional)
  },
  // ...more items
]
```

**Available statusClass values:**
- `status-ok` - Green (success)
- `status-warning` - Orange (warning)
- `status-error` - Red (error)

## Events

| Event | Payload | Description |
|-------|---------|-------------|
| `update` | none | Emitted when update button clicked |

## Slots

| Slot | Description |
|------|-------------|
| `actions` | Additional action buttons (top-right) |
| `additional-items` | Custom items in version details |
| `footer` | Footer content below version details |
| `extra-cards` | Additional cards below main section |

## Migration Guide

### From Old Component

**Before:**
```vue
<NcSettingsSection name="Version Information">
  <div class="version-info">
    <div class="version-card">
      <h4>📦 Application Information</h4>
      <div class="version-details">
        <div class="version-item">
          <span class="version-label">Application Name:</span>
          <span class="version-value">{{ appName }}</span>
        </div>
        <div class="version-item">
          <span class="version-label">Version:</span>
          <span class="version-value">{{ version }}</span>
        </div>
      </div>
    </div>
  </div>
</NcSettingsSection>
```

**After:**
```vue
<VersionInfoCard
  :app-name="appName"
  :app-version="version"
/>
```

### Adding Update Button

Just add these props:
```vue
<VersionInfoCard
  :app-name="appName"
  :app-version="version"
  :is-up-to-date="versionsMatch"
  :show-update-button="true"
  @update="handleUpdate"
/>
```

### Adding Actions Menu

Use the `actions` slot:
```vue
<VersionInfoCard ...>
  <template #actions>
    <NcButton @click="myAction">My Action</NcButton>
  </template>
</VersionInfoCard>
```

## Styling

The component uses Nextcloud CSS variables:

- `--color-success` - Green for success states
- `--color-warning` - Orange for warning states
- `--color-error` - Red for error states
- `--color-main-text` - Main text color
- `--color-text-maxcontrast` - Muted text
- `--color-background-hover` - Item backgrounds
- `--color-border` - Border color

## Best Practices

### 1. Update Button Logic

```javascript
computed: {
  versionsMatch() {
    return this.appVersion === this.configuredVersion
  },
  needsUpdate() {
    return !this.versionsMatch
  },
}
```

### 2. Status Items

Use consistent status messages:

```javascript
statusItems() {
  return [
    {
      label: 'Status',
      value: this.versionsMatch ? '✓ Up to date' : '⚠ Update needed',
      statusClass: this.versionsMatch ? 'status-ok' : 'status-warning',
    },
  ]
}
```

### 3. Update Handler

Always handle errors:

```javascript
async handleUpdate() {
  this.updating = true
  try {
    await this.performUpdate()
    showSuccess('Updated successfully')
    await this.reloadVersionInfo()
  } catch (error) {
    console.error('Update failed:', error)
    showError('Update failed: ' + error.message)
  } finally {
    this.updating = false
  }
}
```

## Testing Checklist

- [ ] Version info displays correctly
- [ ] Update button shows correct icon (Update/Check)
- [ ] Update button has correct style (error/success)
- [ ] Update button is disabled when up to date
- [ ] Update button is disabled when updating
- [ ] Loading spinner shows during update
- [ ] Update event is emitted when clicked
- [ ] Actions menu works
- [ ] Layout is left-aligned
- [ ] No whitespace issues
- [ ] Responsive on mobile

## Browser Compatibility

Tested on:
- ✅ Chrome/Edge (Chromium)
- ✅ Firefox
- ✅ Safari

## License

EUPL-1.2 © 2024 Conduction B.V.

## Support

- Email: info@conduction.nl
- Documentation: See `components/shared/README.md`

