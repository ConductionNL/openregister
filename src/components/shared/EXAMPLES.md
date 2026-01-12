# Shared Components - Usage Examples

This document provides examples of how to use the shared components in different Conduction Nextcloud apps.

## Table of Contents

1. [OpenRegister](#openregister)
2. [OpenConnector](#openconnector)
3. [OpenCatalogi](#opencatalogi)
4. [SoftwareCatalog](#softwarecatalog)

---

## OpenRegister

### Version Information Example

```vue
<!-- src/views/settings/Settings.vue -->
<template>
  <div id="openregister_settings" class="section">
    <h2>{{ t('openregister', 'OpenRegister Settings') }}</h2>

    <!-- Version Information -->
    <VersionInfoCard
      :app-name="settingsStore.versionInfo.appName || 'Open Register'"
      :app-version="settingsStore.versionInfo.appVersion || 'Unknown'"
      :loading="settingsStore.loadingVersionInfo"
      title="Version Information"
      description="Information about the current OpenRegister installation"
    />

    <!-- Other sections... -->
  </div>
</template>

<script>
import VersionInfoCard from '../../components/shared/VersionInfoCard.vue'
import { useSettingsStore } from '../../store/settings.js'

export default {
  name: 'Settings',
  components: {
    VersionInfoCard,
  },
  computed: {
    settingsStore() {
      return useSettingsStore()
    },
  },
}
</script>
```

### Settings Section Example

```vue
<!-- src/views/settings/sections/SolrConfiguration.vue -->
<template>
  <SettingsSection
    name="Search Configuration"
    description="Configure Apache SOLR search engine"
    detailed-description="SOLR provides powerful full-text search capabilities for your data. Configure connection settings and manage search indexes."
    :loading="loading"
    :error="error"
    :error-message="errorMessage"
    :on-retry="loadData">
    
    <template #actions>
      <NcButton type="secondary" @click="refreshStats">
        <template #icon>
          <Refresh :size="20" />
        </template>
        Refresh Stats
      </NcButton>
      
      <NcActions>
        <NcActionButton @click="openConnectionDialog">
          <template #icon>
            <Connection :size="20" />
          </template>
          Connection Settings
        </NcActionButton>
      </NcActions>
    </template>

    <!-- Your settings content -->
    <div class="solr-settings">
      <!-- ... -->
    </div>
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Connection from 'vue-material-design-icons/Connection.vue'

export default {
  name: 'SolrConfiguration',
  components: {
    SettingsSection,
    NcButton,
    NcActions,
    NcActionButton,
    Refresh,
    Connection,
  },
  // ...
}
</script>
```

---

## OpenConnector

### Version Information Example

```vue
<!-- openconnector/src/views/Settings.vue -->
<template>
  <div id="openconnector_settings" class="section">
    <h2>{{ t('openconnector', 'OpenConnector Settings') }}</h2>

    <!-- Version Information -->
    <VersionInfoCard
      app-name="Open Connector"
      :app-version="version"
      :loading="loadingVersion"
      :additional-items="additionalVersionInfo"
      title="Version Information"
      description="Information about the current OpenConnector installation"
    />
  </div>
</template>

<script>
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'

export default {
  name: 'Settings',
  components: {
    VersionInfoCard,
  },
  data() {
    return {
      version: '1.0.5',
      loadingVersion: false,
      additionalVersionInfo: [
        { label: 'API Version', value: 'v2' },
        { label: 'Endpoints', value: '156' },
      ],
    }
  },
}
</script>
```

### Connector Settings Section Example

```vue
<!-- openconnector/src/views/sections/EndpointsConfiguration.vue -->
<template>
  <SettingsSection
    name="Endpoints Configuration"
    description="Manage API endpoints and connections"
    :loading="loading"
    :empty="endpoints.length === 0"
    empty-message="No endpoints configured yet. Add your first endpoint to get started.">
    
    <template #actions>
      <NcButton type="primary" @click="addEndpoint">
        <template #icon>
          <Plus :size="20" />
        </template>
        Add Endpoint
      </NcButton>
      
      <NcActions>
        <NcActionButton @click="importEndpoints">
          <template #icon>
            <Import :size="20" />
          </template>
          Import from File
        </NcActionButton>
        <NcActionButton @click="exportEndpoints">
          <template #icon>
            <Export :size="20" />
          </template>
          Export to File
        </NcActionButton>
      </NcActions>
    </template>

    <div class="endpoints-list">
      <!-- Endpoints list -->
    </div>
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Export from 'vue-material-design-icons/Export.vue'

export default {
  name: 'EndpointsConfiguration',
  components: {
    SettingsSection,
    NcButton,
    NcActions,
    NcActionButton,
    Plus,
    Import,
    Export,
  },
  // ...
}
</script>
```

---

## OpenCatalogi

### Version Information Example

```vue
<!-- opencatalogi/src/views/Settings.vue -->
<template>
  <div id="opencatalogi_settings" class="section">
    <h2>{{ t('opencatalogi', 'OpenCatalogi Settings') }}</h2>

    <!-- Version Information with extra cards -->
    <VersionInfoCard
      app-name="Open Catalogi"
      :app-version="catalogiVersion"
      :loading="loadingVersion">
      
      <!-- Add catalog statistics as additional card -->
      <template #extra-cards>
        <div class="version-card">
          <h4>📊 Catalog Statistics</h4>
          <div class="version-details">
            <div class="version-item">
              <span class="version-label">Total Catalogs:</span>
              <span class="version-value">{{ catalogCount }}</span>
            </div>
            <div class="version-item">
              <span class="version-label">Total Publications:</span>
              <span class="version-value">{{ publicationCount }}</span>
            </div>
          </div>
        </div>
      </template>
    </VersionInfoCard>
  </div>
</template>

<script>
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'

export default {
  name: 'Settings',
  components: {
    VersionInfoCard,
  },
  data() {
    return {
      catalogiVersion: '2.1.0',
      loadingVersion: false,
      catalogCount: 42,
      publicationCount: 1337,
    }
  },
}
</script>
```

### Catalog Settings Section Example

```vue
<!-- opencatalogi/src/views/sections/PublicationSettings.vue -->
<template>
  <SettingsSection
    name="Publishing Options"
    description="Configure automatic publishing behavior and interface preferences"
    :loading="loading">
    
    <template #actions>
      <NcButton type="secondary" @click="resetDefaults">
        Reset to Defaults
      </NcButton>
    </template>

    <template #description>
      <p class="main-description">
        Control how publications are automatically published and configure
        publishing interface preferences for your catalog.
      </p>
    </template>

    <div class="publishing-options">
      <NcCheckboxRadioSwitch v-model="autoPublishAttachments" type="switch">
        Auto publish attachments
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch v-model="autoPublishObjects" type="switch">
        Auto publish objects
      </NcCheckboxRadioSwitch>
    </div>

    <template #footer>
      <NcButton type="primary" @click="saveSettings">
        Save Publishing Options
      </NcButton>
    </template>
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
  name: 'PublicationSettings',
  components: {
    SettingsSection,
    NcButton,
    NcCheckboxRadioSwitch,
  },
  // ...
}
</script>
```

---

## SoftwareCatalog

### Version Information Example

```vue
<!-- softwarecatalog/src/views/Settings.vue -->
<template>
  <div id="softwarecatalog_settings" class="section">
    <h2>{{ t('softwarecatalog', 'Software Catalog Settings') }}</h2>

    <!-- Version Information with custom labels -->
    <VersionInfoCard
      app-name="Software Catalog"
      :app-version="version"
      :loading="loadingVersion"
      :labels="{
        appName: 'Catalog Name',
        version: 'Release Version'
      }"
      :additional-items="systemInfo"
    />
  </div>
</template>

<script>
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'

export default {
  name: 'Settings',
  components: {
    VersionInfoCard,
  },
  data() {
    return {
      version: '3.0.1',
      loadingVersion: false,
      systemInfo: [
        { label: 'PHP Version', value: '8.1.0' },
        { label: 'Database', value: 'MySQL 8.0' },
        { label: 'Last Updated', value: '2024-01-15' },
      ],
    }
  },
}
</script>
```

### Software Settings Section Example

```vue
<!-- softwarecatalog/src/views/sections/GeneralSettings.vue -->
<template>
  <SettingsSection
    name="General Settings"
    description="Configure basic application settings"
    :loading="loading"
    :error="saveFailed"
    error-message="Failed to save settings. Please try again."
    :on-retry="loadSettings">
    
    <template #actions>
      <NcButton type="secondary" @click="refreshSettings">
        <template #icon>
          <Refresh :size="20" />
        </template>
        Refresh
      </NcButton>
    </template>

    <div class="general-settings">
      <div class="setting-group">
        <label>Software Catalog Location URL</label>
        <input 
          v-model="catalogUrl" 
          type="text" 
          placeholder="https://example.com"
          class="input-field"
        />
        <small>This URL will be used for external links to your software catalog.</small>
      </div>
    </div>

    <template #footer>
      <NcButton type="primary" :disabled="saving" @click="saveSettings">
        <template #icon>
          <NcLoadingIcon v-if="saving" :size="20" />
        </template>
        {{ saving ? 'Saving...' : 'Save General Settings' }}
      </NcButton>
    </template>
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
  name: 'GeneralSettings',
  components: {
    SettingsSection,
    NcButton,
    NcLoadingIcon,
    Refresh,
  },
  data() {
    return {
      loading: false,
      saving: false,
      saveFailed: false,
      catalogUrl: '',
    }
  },
  methods: {
    async loadSettings() {
      // Load settings...
    },
    async saveSettings() {
      // Save settings...
    },
    refreshSettings() {
      this.loadSettings()
    },
  },
}
</script>

<style scoped>
.general-settings {
  margin-top: 20px;
}

.setting-group {
  margin-bottom: 24px;
}

.setting-group label {
  display: block;
  font-weight: 500;
  margin-bottom: 8px;
}

.input-field {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  font-size: 14px;
}

.setting-group small {
  display: block;
  margin-top: 6px;
  color: var(--color-text-maxcontrast);
  font-size: 12px;
}
</style>
```

---

## Tips and Best Practices

### 1. Loading States

Always provide feedback when data is loading:

```vue
<VersionInfoCard
  :loading="loading"
  app-name="My App"
  :app-version="version"
/>
```

### 2. Error Handling

Use the built-in error state with retry:

```vue
<SettingsSection
  :error="error"
  :error-message="errorMsg"
  :on-retry="retryLoad"
>
  <!-- content -->
</SettingsSection>
```

### 3. Empty States

Provide helpful empty states:

```vue
<SettingsSection
  :empty="items.length === 0"
  empty-message="No items found. Click 'Add Item' to get started."
>
  <template #empty>
    <!-- Custom empty state -->
  </template>
</SettingsSection>
```

### 4. Consistent Styling

Use Nextcloud CSS variables for consistency:

```css
color: var(--color-main-text);
background: var(--color-background-hover);
border: 1px solid var(--color-border);
border-radius: var(--border-radius-large);
```

### 5. Responsive Design

Components are responsive by default, but test on mobile devices.

---

## Migration Checklist

When migrating an existing settings page to use these components:

- [ ] Copy `components/shared/` directory to your app
- [ ] Import `VersionInfoCard` in Settings.vue
- [ ] Replace version block with `<VersionInfoCard>`
- [ ] Remove old version styles
- [ ] Import `SettingsSection` in section components
- [ ] Wrap settings sections with `<SettingsSection>`
- [ ] Move action buttons to `#actions` slot
- [ ] Test on desktop and mobile
- [ ] Update documentation
- [ ] Check for linter errors

---

## Support

For questions or issues:
- Email: info@conduction.nl
- Documentation: https://www.conduction.nl

