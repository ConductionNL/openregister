---
kind: fix
depends_on: []
---

## Why

Frontend audit of the Vue 2.7 app surfaced shipped defects and convention
violations:

1. **A faked list view ships to users (HIGH).**
   `src/views/templates/TemplatesIndex.vue:227-253` forces
   `this.templatesList = []` with a `// TODO: Replace with actual templates API`
   comment; the real `axios.get('/apps/openregister/api/templates')` and its
   error handling are commented out. The component is registered in
   `src/registry.js` and `src/manifest.json`, so users hit a real nav entry that
   always renders empty and silently swallows any future error.

2. **~141 inline hex colors bypass NL Design System theming/dark mode (HIGH).**
   e.g. `src/modals/schema/ExploreSchema.vue:67-100,1125-1161`
   (`#ddd`, `#0066cc`, `#e1e5e9`, …), with dense clusters in `UploadSchema.vue`,
   `ViewObject.vue`, `UploadObject.vue`, `DownloadObject.vue`, and the
   `src/components/i18n/*` cluster. Breaks WCAG contrast guarantees and dark-mode
   theming (project requires CSS custom properties, no hardcoded colors).

3. **Un-i18n'd user-facing strings (MED-HIGH).**
   `src/views/settings/sections/CacheManagement.vue:34-100+,295-297` renders
   headings/labels/dialog text as raw English, not wrapped in `t('openregister',
   …)` as the rest of the app does.

4. **Inline dialogs violate modal-isolation (MED).**
   `CacheManagement.vue:288` and `ChatIndex.vue:185,201,226` define
   `NcDialog`s inline in large parent views instead of extracting to
   `src/dialogs/` (as `src/dialogs/avg/`, `src/dialogs/workflow/` correctly do).

5. **apexcharts duplicated instead of consumed from nc-vue (MED).**
   `package.json:61,76` declares `apexcharts`/`vue-apexcharts` directly and
   `DashboardIndex.vue:182`, `RegisterDetail.vue:216`, `SchemaDetails.vue:193`
   import `vue-apexcharts` directly, though `@conduction/nextcloud-vue` already
   ships them — per project convention charts come from nc-vue.

## What Changes

- Wire `TemplatesIndex` to the real endpoint, or gate the nav entry behind an
  explicit "coming soon" state — no silent-empty shipped view.
- Replace inline hex colors with Nextcloud CSS variables
  (`--color-main-text`, `--color-border`, `--color-primary-element`, …).
- Wrap `CacheManagement.vue` user-facing strings in `t('openregister', …)`.
- Extract the inline dialogs into `src/dialogs/`.
- Import chart components from `@conduction/nextcloud-vue` and drop the direct
  `apexcharts`/`vue-apexcharts` deps.

## Impact

- Affected: `src/views/templates/TemplatesIndex.vue`,
  `src/modals/schema/ExploreSchema.vue` (+ the other hex-heavy files),
  `src/views/settings/sections/CacheManagement.vue`, `src/views/chat/ChatIndex.vue`,
  `package.json`, the three chart-importing views.
- Behavioural change: templates view no longer silently empty; colors follow
  theme; strings translatable. No API change.
- Risk: the hex-color sweep is large — do it file-by-file with visual + dark-mode
  check per the design-system convention; verify contrast (WCAG AA).
