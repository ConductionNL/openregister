# Shared Components for Conduction Nextcloud Apps

This directory contains reusable Vue components designed to be shared across all Conduction Nextcloud applications:
- OpenRegister
- OpenConnector
- OpenCatalogi
- SoftwareCatalog

## Components

### VersionInfoCard.vue

A reusable component for displaying application version information in a consistent format.

**Features:**
- Displays application name, version, and status
- **Conditional update button** (error style if needs update, disabled with check icon if up to date)
- **Actions slot** for additional buttons (e.g., Reset Auto-Config, Load Schemas)
- Clean, left-aligned grid layout matching Software Catalog style
- Built-in loading state
- Flexible slots for customization
- Responsive design

**Basic Usage:**

```vue
<template>
  <VersionInfoCard
    app-name="Open Register"
    :app-version="versionInfo.appVersion"
    :loading="loadingVersionInfo"
  />
</template>

<script>
import VersionInfoCard from './components/shared/VersionInfoCard.vue'

export default {
  components: { VersionInfoCard },
  data() {
    return {
      versionInfo: { appVersion: '0.2.3' },
      loadingVersionInfo: false,
    }
  },
}
</script>
```

**With Update Button:**

```vue
<template>
  <VersionInfoCard
    app-name="Open Connector"
    :app-version="appVersion"
    :configured-version="configuredVersion"
    :is-up-to-date="versionsMatch"
    :show-update-button="true"
    :updating="updating"
    update-button-text="Update Configuration"
    :additional-items="statusItems"
    @update="handleUpdate"
  />
</template>

<script>
export default {
  data() {
    return {
      appVersion: '1.0.5',
      configuredVersion: '1.0.3',
      versionsMatch: false,
      updating: false,
      statusItems: [
        { 
          label: 'Status', 
          value: '⚠ Update needed',
          statusClass: 'status-warning'
        },
      ],
    }
  },
  methods: {
    async handleUpdate() {
      this.updating = true
      // Perform update...
      this.updating = false
    },
  },
}
</script>
```

**With Actions Menu:**

```vue
<template>
  <VersionInfoCard
    app-name="Software Catalog"
    :app-version="version"
    :is-up-to-date="isUpToDate"
    :show-update-button="true"
    @update="handleUpdate">
    
    <template #actions>
      <NcButton @click="loadSchemas">
        Load Schemas
      </NcButton>
      <NcActions>
        <NcActionButton @click="resetAutoConfig">
          Reset Auto-Config
        </NcActionButton>
      </NcActions>
    </template>
  </VersionInfoCard>
</template>
```

**Props:**

| Prop | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `title` | String | No | 'Version Information' | Section title |
| `description` | String | No | 'Information about...' | Section description |
| `appName` | String | Yes | - | Application name |
| `appVersion` | String | Yes | - | Application version |
| `configuredVersion` | String | No | '' | Configured version (if applicable) |
| `isUpToDate` | Boolean | No | `true` | Whether app is up to date |
| `showUpdateButton` | Boolean | No | `false` | Show update button |
| `updateButtonText` | String | No | 'Update' | Update button text |
| `updating` | Boolean | No | `false` | Updating state |
| `additionalItems` | Array | No | `[]` | Additional items `[{label, value, statusClass}]` |
| `loading` | Boolean | No | `false` | Loading state |
| `labels` | Object | No | `{...}` | Custom labels |

**Events:**

- `update` - Emitted when update button is clicked

**Slots:**

- `actions` - Additional action buttons (positioned top-right)
- `additional-items` - Add custom items to the version details
- `footer` - Add footer content
- `extra-cards` - Add additional cards

### SettingsSection.vue

A reusable wrapper component that provides consistent layout and functionality for settings sections.

**Features:**
- Consistent action menu positioning (top-right, aligned with title)
- Built-in loading, error, and empty states
- Flexible slots for customization
- Responsive design
- Standardized styling across all apps

**Usage:**

```vue
<template>
  <SettingsSection
    name="Search Configuration"
    description="Configure Apache SOLR search engine"
    detailed-description="SOLR provides powerful full-text search capabilities..."
    :loading="loading"
    :error="error"
    :error-message="errorMessage"
    :on-retry="loadData">
    
    <template #actions>
      <NcButton @click="doSomething">
        Action Button
      </NcButton>
      <NcActions>
        <!-- Action menu items -->
      </NcActions>
    </template>

    <!-- Main content -->
    <div class="my-settings">
      <!-- Your settings UI here -->
    </div>

    <template #footer>
      <NcButton type="primary" @click="save">
        Save Settings
      </NcButton>
    </template>
  </SettingsSection>
</template>

<script>
import SettingsSection from './components/shared/SettingsSection.vue'
import { NcButton, NcActions } from '@nextcloud/vue'

export default {
  components: {
    SettingsSection,
    NcButton,
    NcActions,
  },
  // ...
}
</script>
```

**Props:**

| Prop | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `name` | String | Yes | - | Section name/title |
| `description` | String | No | '' | Brief description under title |
| `detailedDescription` | String | No | '' | Detailed description in gray box |
| `docUrl` | String | No | '' | Documentation URL |
| `loading` | Boolean | No | `false` | Loading state |
| `loadingMessage` | String | No | 'Loading...' | Loading message |
| `error` | Boolean | No | `false` | Error state |
| `errorMessage` | String | No | 'An error occurred' | Error message |
| `onRetry` | Function | No | `null` | Retry callback |
| `retryButtonText` | String | No | 'Retry' | Retry button text |
| `empty` | Boolean | No | `false` | Empty state |
| `emptyMessage` | String | No | 'No data available' | Empty message |
| `wrapperClass` | String | No | '' | Additional CSS classes |

**Slots:**

- `actions` - Action buttons/menu (positioned top-right)
- `description` - Custom detailed description
- `default` - Main content area
- `empty` - Custom empty state
- `footer` - Footer content

## Sharing Components Across Apps

### Option 1: Copy Components (Recommended for now)

Copy the entire `components/shared` directory to each app:

```bash
# From openregister to openconnector
cp -r openregister/src/components/shared openconnector/src/components/

# From openregister to opencatalogi
cp -r openregister/src/components/shared opencatalogi/src/components/

# From openregister to softwarecatalog
cp -r openregister/src/components/shared softwarecatalog/src/components/
```

**Pros:**
- Simple to implement
- Each app remains independent
- No build complexity

**Cons:**
- Need to manually sync changes
- Code duplication

### Option 2: Shared NPM Package (Future)

Create a shared package `@conduction/nextcloud-vue-components`:

1. Create a new package:
```bash
mkdir conduction-vue-components
cd conduction-vue-components
npm init -y
```

2. Add components to the package

3. Install in each app:
```bash
npm install @conduction/nextcloud-vue-components
```

4. Import in apps:
```vue
import { VersionInfoCard, SettingsSection } from '@conduction/nextcloud-vue-components'
```

**Pros:**
- Single source of truth
- Easy updates via npm
- Professional approach

**Cons:**
- More complex setup
- Requires package publishing

### Option 3: Git Submodule

Use a shared Git repository as a submodule:

```bash
# Create shared components repo
git init conduction-shared-components

# Add as submodule to each app
cd openregister
git submodule add <repo-url> src/components/shared
```

**Pros:**
- Git-based versioning
- Single source of truth

**Cons:**
- Submodule complexity
- Git expertise required

## Styling Guidelines

These components use:
- Nextcloud CSS variables (e.g., `--color-main-text`, `--color-primary-element`)
- Nextcloud Vue components (e.g., `NcButton`, `NcActions`)
- Responsive design with mobile breakpoint at 768px
- Consistent spacing and borders

## Best Practices

1. **Always use these shared components** when creating new settings sections
2. **Keep styling consistent** - use Nextcloud CSS variables
3. **Test across all apps** after making changes
4. **Document props and slots** when adding new features
5. **Follow Vue 2 compatibility** - these apps use Vue 2
6. **Use TypeScript hints** in comments for better IDE support

## Migration Guide

To migrate existing sections to use these components:

1. Replace custom version blocks with `<VersionInfoCard>`
2. Wrap sections with `<SettingsSection>` for consistent layout
3. Move action buttons to the `actions` slot
4. Use built-in loading/error states instead of custom ones

## Support

For questions or issues with these components:
- Contact: info@conduction.nl
- Documentation: https://www.conduction.nl

## License

EUPL-1.2 © 2024 Conduction B.V.

