# Shared Components Migration - Complete Guide

This document provides a comprehensive guide for migrating all Conduction Nextcloud apps to use shared, reusable Vue components.

## 📋 Table of Contents

1. [Overview](#overview)
2. [What's New](#whats-new)
3. [Benefits](#benefits)
4. [Quick Start](#quick-start)
5. [Detailed Migration Steps](#detailed-migration-steps)
6. [Component Reference](#component-reference)
7. [Troubleshooting](#troubleshooting)
8. [Maintenance](#maintenance)

---

## Overview

We've created reusable Vue components for common UI patterns across all Conduction Nextcloud apps:

- **OpenRegister** ✓ (Reference implementation)
- **OpenConnector** (Pending)
- **OpenCatalogi** (Pending)
- **SoftwareCatalog** (Pending)

### Location

Shared components are located in:
```
openregister/src/components/shared/
├── VersionInfoCard.vue     # Application version display
├── SettingsSection.vue     # Settings section wrapper
├── README.md               # Component documentation
├── EXAMPLES.md             # Usage examples for each app
└── INSTALLATION.md         # Installation guide
```

---

## What's New

### 1. VersionInfoCard Component

**Before:**
```vue
<NcSettingsSection name="Version Information">
  <div v-if="!loading" class="version-info">
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
  <NcLoadingIcon v-else :size="64" />
</NcSettingsSection>

<!-- Plus 50+ lines of CSS -->
```

**After:**
```vue
<VersionInfoCard
  :app-name="appName"
  :app-version="version"
  :loading="loading"
/>
```

**Savings:** ~70 lines of code per app!

### 2. SettingsSection Component

**Features:**
- Consistent action menu positioning (top-right, aligned with title)
- Built-in loading, error, and empty states
- Flexible slots for customization
- Responsive design
- Standardized styling

---

## Benefits

### 1. Consistency
✅ All apps have the same look and feel  
✅ Consistent UX patterns across the platform  
✅ Professional, polished appearance

### 2. Maintainability
✅ Single source of truth for common components  
✅ Bug fixes propagate to all apps  
✅ Easier to onboard new developers

### 3. Development Speed
✅ Less code to write  
✅ No need to recreate common patterns  
✅ Focus on app-specific features

### 4. Quality
✅ Well-tested components  
✅ Proper TypeScript hints  
✅ Accessibility built-in  
✅ Responsive by default

---

## Quick Start

### Option 1: Automatic Sync (Recommended)

```bash
# From apps-extra directory
cd /path/to/apps-extra

# Make script executable (first time only)
chmod +x openregister/sync-shared-components.sh

# Sync to all apps
./openregister/sync-shared-components.sh

# Or sync to specific app
./openregister/sync-shared-components.sh connector
```

### Option 2: Manual Copy

```bash
# From apps-extra directory
cp -r openregister/src/components/shared openconnector/src/components/
cp -r openregister/src/components/shared opencatalogi/src/components/
cp -r openregister/src/components/shared softwarecatalog/src/components/
```

---

## Detailed Migration Steps

### Step 1: Copy Components

```bash
cd /path/to/apps-extra
./openregister/sync-shared-components.sh [app-name]
```

Or manually:
```bash
cp -r openregister/src/components/shared [target-app]/src/components/
```

### Step 2: Update Settings.vue (Version Information)

**OpenConnector Example:**

```vue
<!-- openconnector/src/views/Settings.vue -->
<template>
  <div id="openconnector_settings" class="section">
    <h2>{{ t('openconnector', 'OpenConnector Settings') }}</h2>

    <!-- OLD CODE - REMOVE THIS -->
    <!--
    <NcSettingsSection name="Version Information">
      <div class="version-info">
        ...
      </div>
    </NcSettingsSection>
    -->

    <!-- NEW CODE - ADD THIS -->
    <VersionInfoCard
      app-name="Open Connector"
      :app-version="version"
      :loading="loadingVersion"
      title="Version Information"
      description="Information about the current OpenConnector installation"
    />

    <!-- Rest of your sections... -->
  </div>
</template>

<script>
// Add this import
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'

export default {
  name: 'Settings',
  components: {
    VersionInfoCard, // Add this
    // ... other components
  },
  data() {
    return {
      version: '1.0.5',
      loadingVersion: false,
    }
  },
}
</script>

<style scoped>
/* REMOVE these old version styles */
/*
.version-info { ... }
.version-card { ... }
.version-details { ... }
.version-item { ... }
.version-label { ... }
.version-value { ... }
*/

/* KEEP your other app-specific styles */
</style>
```

### Step 3: Update Section Components (Optional)

For consistent section styling:

```vue
<!-- Example: openconnector/src/views/sections/EndpointSettings.vue -->
<template>
  <!-- OLD -->
  <!--
  <NcSettingsSection name="Endpoint Configuration">
    <div class="section-actions">
      <NcButton>Action</NcButton>
    </div>
    <div v-if="loading">Loading...</div>
    <div v-else>Content...</div>
  </NcSettingsSection>
  -->

  <!-- NEW -->
  <SettingsSection
    name="Endpoint Configuration"
    description="Manage API endpoints"
    :loading="loading"
    :error="error"
    :error-message="errorMsg"
    :on-retry="retryLoad">
    
    <template #actions>
      <NcButton>Action</NcButton>
    </template>

    <!-- Your content -->
    <div class="endpoint-content">
      <!-- ... -->
    </div>
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'
import { NcButton } from '@nextcloud/vue'

export default {
  name: 'EndpointSettings',
  components: {
    SettingsSection,
    NcButton,
  },
  // ...
}
</script>
```

### Step 4: Test

1. **Start development server:**
   ```bash
   npm run dev
   ```

2. **Check browser console** for errors

3. **Test functionality:**
   - Version information displays correctly
   - Actions menu works
   - Loading states work
   - Responsive on mobile

4. **Run linter:**
   ```bash
   npm run lint
   ```

### Step 5: Document Changes

Update your app's documentation to mention shared components.

---

## Component Reference

### VersionInfoCard

**Full Documentation:** See `components/shared/README.md`

**Basic Usage:**
```vue
<VersionInfoCard
  app-name="My App"
  :app-version="version"
  :loading="loading"
/>
```

**With Additional Info:**
```vue
<VersionInfoCard
  app-name="My App"
  :app-version="version"
  :loading="loading"
  :additional-items="[
    { label: 'Build Date', value: '2024-01-15' },
    { label: 'PHP Version', value: '8.1' },
  ]"
/>
```

### SettingsSection

**Full Documentation:** See `components/shared/README.md`

**Basic Usage:**
```vue
<SettingsSection
  name="My Section"
  description="Section description">
  <!-- content -->
</SettingsSection>
```

**With Actions:**
```vue
<SettingsSection name="My Section">
  <template #actions>
    <NcButton>Action</NcButton>
    <NcActions>
      <NcActionButton>Menu Item</NcActionButton>
    </NcActions>
  </template>
  <!-- content -->
</SettingsSection>
```

---

## Troubleshooting

### Import Errors

**Problem:** `Cannot find module '../components/shared/VersionInfoCard.vue'`

**Solution:** Check import path based on file location:
- From `src/views/Settings.vue`: `../components/shared/`
- From `src/views/settings/Settings.vue`: `../../components/shared/`
- From `src/views/settings/sections/MySection.vue`: `../../../components/shared/`

### Styling Issues

**Problem:** Component doesn't match Nextcloud theme

**Solution:** Components use Nextcloud CSS variables. Ensure your app has Nextcloud Vue installed:
```bash
npm install @nextcloud/vue
```

### Icons Not Displaying

**Problem:** Material Design Icons not showing

**Solution:**
```bash
npm install vue-material-design-icons
```

### Components Not Updating

**Problem:** Props not reactive

**Solution:** Ensure data is reactive:
```js
// ✓ Good
data() {
  return {
    version: '1.0.0',
  }
}

// ✗ Bad
const version = '1.0.0' // Not reactive!
```

---

## Maintenance

### Updating Shared Components

When components are updated in OpenRegister:

1. **Review changes:**
   ```bash
   cd openregister
   git log -- src/components/shared/
   ```

2. **Sync to other apps:**
   ```bash
   cd ../
   ./openregister/sync-shared-components.sh
   ```

3. **Test each app:**
   ```bash
   cd openconnector && npm run dev
   cd opencatalogi && npm run dev
   cd softwarecatalog && npm run dev
   ```

4. **Commit changes:**
   ```bash
   git add */src/components/shared/
   git commit -m "Update shared components from OpenRegister"
   ```

### Adding New Shared Components

1. **Create in OpenRegister:**
   ```bash
   cd openregister/src/components/shared/
   # Create new component
   ```

2. **Document it:**
   - Add to `README.md`
   - Add examples to `EXAMPLES.md`

3. **Test in OpenRegister:**
   ```bash
   npm run dev
   npm run lint
   ```

4. **Sync to other apps:**
   ```bash
   cd ../../..
   ./openregister/sync-shared-components.sh
   ```

### Version Control

Consider tagging shared component releases:

```bash
cd openregister
git tag -a shared-components-v1.0.0 -m "Shared components v1.0.0"
git push origin shared-components-v1.0.0
```

---

## Migration Checklist

Use this checklist for each app:

### OpenConnector
- [ ] Copy shared components
- [ ] Update Settings.vue (version block)
- [ ] Remove old version styles
- [ ] Update section components (optional)
- [ ] Test on desktop
- [ ] Test on mobile
- [ ] Run linter
- [ ] Update documentation
- [ ] Commit changes

### OpenCatalogi
- [ ] Copy shared components
- [ ] Update Settings.vue (version block)
- [ ] Remove old version styles
- [ ] Update section components (optional)
- [ ] Test on desktop
- [ ] Test on mobile
- [ ] Run linter
- [ ] Update documentation
- [ ] Commit changes

### SoftwareCatalog
- [ ] Copy shared components
- [ ] Update Settings.vue (version block)
- [ ] Remove old version styles
- [ ] Update section components (optional)
- [ ] Test on desktop
- [ ] Test on mobile
- [ ] Run linter
- [ ] Update documentation
- [ ] Commit changes

---

## Support

**Questions?** Contact: info@conduction.nl

**Documentation:**
- `components/shared/README.md` - Component API reference
- `components/shared/EXAMPLES.md` - Usage examples
- `components/shared/INSTALLATION.md` - Installation guide

**Issues?** See troubleshooting section above or contact the development team.

---

## License

EUPL-1.2 © 2024 Conduction B.V.

---

**Last Updated:** 2024-01-15  
**Version:** 1.0.0  
**Maintained By:** Conduction Development Team

