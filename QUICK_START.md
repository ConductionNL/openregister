# Shared Components - Quick Start Guide

## ✅ What's Been Done

1. **Created reusable components** for all Conduction apps
2. **Enhanced VersionInfoCard** with update buttons and actions menu
3. **Consistent action menu positioning** across all settings sections
4. **Software Catalog layout** adopted (clean, left-aligned, no whitespace)

## 📦 Available Components

Located in `openregister/src/components/shared/`:
- ✅ `VersionInfoCard.vue` - Application version display with update button
- ✅ `SettingsSection.vue` - Reusable settings section wrapper
- ✅ `README.md` - Component documentation
- ✅ `EXAMPLES.md` - Usage examples for each app
- ✅ `INSTALLATION.md` - Installation instructions

## 🚀 5-Minute Quick Start

### 1. Copy Components to Other Apps (30 seconds)

```bash
# From apps-extra directory
./openregister/sync-shared-components.sh
```

### 2. Update Your Settings Page (2 minutes)

**Before:**
```vue
<NcSettingsSection name="Version Information">
  <div class="version-card">
    <!-- 50+ lines of version info code -->
  </div>
</NcSettingsSection>

<!-- Plus 50+ lines of CSS -->
```

**After:**
```vue
<VersionInfoCard
  app-name="My App"
  :app-version="version"
  :loading="loading"
/>
```

### 3. Add Update Button (1 minute)

```vue
<VersionInfoCard
  app-name="My App"
  :app-version="version"
  :is-up-to-date="versionsMatch"
  :show-update-button="true"
  @update="handleUpdate"
/>
```

### 4. Add Actions Menu (1 minute)

```vue
<VersionInfoCard
  app-name="My App"
  :app-version="version"
  :show-update-button="true"
  @update="handleUpdate">
  
  <template #actions>
    <NcButton @click="loadSchemas">
      Load Schemas
    </NcButton>
  </template>
</VersionInfoCard>
```

### 5. Test (30 seconds)

- Version info displays ✓
- Update button works ✓
- Actions menu works ✓
- No whitespace issues ✓

## 🎯 Update Button States

| State | Button Style | Icon | Enabled |
|-------|-------------|------|---------|
| Needs Update | 🔴 Error (Red) | Update icon | ✓ Yes |
| Up to Date | ✅ Success (Green) | Check icon | ✗ No (disabled) |
| Updating | 🔄 Loading | Spinner | ✗ No (disabled) |

Set with `is-up-to-date` prop:
- `false` = Red update button (enabled)
- `true` = Green check button (disabled)

## 📝 Real-World Examples

### OpenConnector
```vue
<VersionInfoCard
  app-name="Open Connector"
  :app-version="version"
  :is-up-to-date="versionsMatch"
  :show-update-button="true"
  @update="handleUpdate">
  
  <template #actions>
    <NcActions>
      <NcActionButton @click="loadSchemas">
        Load Schemas
      </NcActionButton>
    </NcActions>
  </template>
</VersionInfoCard>
```

### OpenCatalogi
```vue
<VersionInfoCard
  app-name="Open Catalogi"
  :app-version="version"
  :is-up-to-date="!needsUpdate"
  :show-update-button="true"
  @update="handleUpdate">
  
  <template #actions>
    <NcButton @click="syncCatalogs">
      Sync Catalogs
    </NcButton>
  </template>
</VersionInfoCard>
```

### SoftwareCatalog
```vue
<VersionInfoCard
  app-name="Software Catalog"
  :app-version="version"
  :is-up-to-date="versionsMatch"
  :show-update-button="true"
  update-button-text="Force Update"
  @update="handleForceUpdate">
  
  <template #actions>
    <NcButton @click="autoConfig">
      Auto Configure
    </NcButton>
    <NcButton @click="resetConfig">
      Reset Auto-Config
    </NcButton>
  </template>
</VersionInfoCard>
```

## 🎨 Add Status Items

```vue
<VersionInfoCard
  :additional-items="[
    { 
      label: 'Status', 
      value: '✓ Up to date',
      statusClass: 'status-ok'    // Green
    },
    {
      label: 'Status',
      value: '⚠ Update needed',
      statusClass: 'status-warning' // Orange
    },
    {
      label: 'Status',
      value: '✗ Failed',
      statusClass: 'status-error'   // Red
    }
  ]"
/>
```

## 📚 Documentation

| File | Purpose |
|------|---------|
| `README.md` | Component API reference |
| `EXAMPLES.md` | Complete usage examples |
| `INSTALLATION.md` | Installation guide |
| `ENHANCED_VERSION_INFO_COMPONENT.md` | New features guide |
| `SHARED_COMPONENTS_MIGRATION.md` | Migration guide |
| `SHARED_COMPONENTS_UPDATE_SUMMARY.md` | Complete summary |
| `QUICK_START.md` | This file |

## ⚡ Sync Script

```bash
# Sync to all apps
./openregister/sync-shared-components.sh

# Sync to specific app
./openregister/sync-shared-components.sh connector

# Preview changes (dry run)
./openregister/sync-shared-components.sh --dry-run

# Help
./openregister/sync-shared-components.sh --help
```

## 🐛 Common Issues

**Update button not showing?**
→ Add `show-update-button={true}`

**Button wrong color?**
→ Check `is-up-to-date` prop (true=green, false=red)

**Actions not aligned?**
→ Verify you have the latest version

**Whitespace issues?**
→ New layout eliminates these - update component

## ✅ Checklist

- [ ] Copy shared components
- [ ] Update Settings.vue
- [ ] Remove old version styles
- [ ] Add update button (optional)
- [ ] Add actions menu (optional)
- [ ] Test on desktop
- [ ] Test on mobile
- [ ] Run linter
- [ ] Commit changes

## 🎉 Benefits

- **Saves ~100 lines of code** per app
- **Consistent UX** across all apps
- **Professional appearance**
- **Easy to maintain**
- **Well documented**

## 📞 Need Help?

- **Email:** info@conduction.nl
- **Docs:** See files listed above
- **Examples:** Check `EXAMPLES.md`

---

**Ready to start?** Run the sync script and update your first app!

```bash
./openregister/sync-shared-components.sh
```

---

EUPL-1.2 © 2024 Conduction B.V.

