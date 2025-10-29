# Shared Components Installation Guide

Quick guide to install these shared components in your Conduction Nextcloud apps.

## Quick Copy Commands

### To OpenConnector

```bash
# From the workspace root (apps-extra/)
cp -r openregister/src/components/shared openconnector/src/components/
```

### To OpenCatalogi

```bash
# From the workspace root (apps-extra/)
cp -r openregister/src/components/shared opencatalogi/src/components/
```

### To SoftwareCatalog

```bash
# From the workspace root (apps-extra/)
cp -r openregister/src/components/shared softwarecatalog/src/components/
```

## After Copying

1. **Import in your Settings page:**

```vue
<script>
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'
// or from ../../components/shared/VersionInfoCard.vue depending on your structure
</script>
```

2. **Use the component:**

```vue
<template>
  <VersionInfoCard
    app-name="Your App Name"
    :app-version="yourVersion"
    :loading="loading"
  />
</template>
```

3. **Check for errors:**

```bash
npm run lint
```

## Full Integration Steps

### Step 1: Copy Components

```bash
cd /path/to/apps-extra
cp -r openregister/src/components/shared [target-app]/src/components/
```

### Step 2: Update Your Settings Page

Replace your existing version block:

**Before:**
```vue
<NcSettingsSection name="Version Information">
  <div class="version-info">
    <div class="version-card">
      <h4>Application Information</h4>
      <div class="version-item">
        <span>Application Name:</span>
        <span>{{ appName }}</span>
      </div>
      <!-- ... -->
    </div>
  </div>
</NcSettingsSection>
```

**After:**
```vue
<VersionInfoCard
  :app-name="appName"
  :app-version="appVersion"
  :loading="loading"
/>
```

### Step 3: Remove Old Styles

Remove version-related CSS from your Settings.vue:

```css
/* DELETE THESE */
.version-info { ... }
.version-card { ... }
.version-details { ... }
.version-item { ... }
.version-label { ... }
.version-value { ... }
```

### Step 4: Update Sections (Optional)

For consistent section styling:

```vue
<template>
  <SettingsSection
    name="My Section"
    description="Section description"
    :loading="loading">
    
    <template #actions>
      <!-- Your action buttons -->
    </template>

    <!-- Your content -->
  </SettingsSection>
</template>

<script>
import SettingsSection from '../../components/shared/SettingsSection.vue'

export default {
  components: {
    SettingsSection,
  },
}
</script>
```

### Step 5: Test

1. **Start development server:**
   ```bash
   npm run dev
   ```

2. **Check browser console** for errors

3. **Test on mobile** (responsive design)

4. **Run linter:**
   ```bash
   npm run lint
   ```

## Troubleshooting

### Import Error: Module not found

**Problem:** `Cannot find module '../components/shared/VersionInfoCard.vue'`

**Solution:** Check your import path. From `src/views/Settings.vue`, use:
```js
import VersionInfoCard from '../components/shared/VersionInfoCard.vue'
```

From `src/views/settings/sections/MySection.vue`, use:
```js
import VersionInfoCard from '../../../components/shared/VersionInfoCard.vue'
```

### Nextcloud Vue Components Not Found

**Problem:** `Cannot find module '@nextcloud/vue'`

**Solution:** Install Nextcloud Vue:
```bash
npm install @nextcloud/vue
```

### Icons Not Displaying

**Problem:** Material Design Icons not showing

**Solution:** Install material design icons:
```bash
npm install vue-material-design-icons
```

### Styles Not Applied

**Problem:** Component looks unstyled

**Solution:** 
1. Check that `scoped` attribute is present in `<style scoped>`
2. Verify Nextcloud CSS variables are available
3. Check browser console for CSS errors

### Component Not Updating

**Problem:** Component doesn't show updated data

**Solution:**
1. Check that props are reactive (use `data()` or `computed`)
2. Verify prop names match (case-sensitive)
3. Check for TypeScript/prop validation errors in console

## Verification Checklist

After installation, verify:

- [ ] Components directory copied successfully
- [ ] No import errors in console
- [ ] Version information displays correctly
- [ ] Loading state works
- [ ] Styles match Nextcloud theme
- [ ] Responsive on mobile (< 768px)
- [ ] No linter errors
- [ ] All existing features still work

## Example: Complete OpenConnector Integration

```bash
# 1. Copy components
cd /Ubuntu-20.04/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra
cp -r openregister/src/components/shared openconnector/src/components/

# 2. Edit OpenConnector Settings.vue
# Replace version block with VersionInfoCard

# 3. Test
cd openconnector
npm run dev

# 4. Check
npm run lint
```

## Keeping Components Updated

When shared components are updated in OpenRegister:

```bash
# Re-copy to all apps
cd apps-extra

# Update OpenConnector
cp -r openregister/src/components/shared openconnector/src/components/

# Update OpenCatalogi
cp -r openregister/src/components/shared opencatalogi/src/components/

# Update SoftwareCatalog
cp -r openregister/src/components/shared softwarecatalog/src/components/
```

**Tip:** Consider creating a shell script to automate this:

```bash
#!/bin/bash
# sync-shared-components.sh

APPS=("openconnector" "opencatalogi" "softwarecatalog")

for app in "${APPS[@]}"; do
  echo "Syncing to $app..."
  cp -r openregister/src/components/shared "$app/src/components/"
  echo "✓ $app updated"
done

echo "All apps synced!"
```

Make it executable:
```bash
chmod +x sync-shared-components.sh
./sync-shared-components.sh
```

## Need Help?

- **Email:** info@conduction.nl
- **Documentation:** See `README.md` and `EXAMPLES.md`
- **Issues:** Check troubleshooting section above

## License

EUPL-1.2 © 2024 Conduction B.V.

