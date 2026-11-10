## 1. Templates view

- [ ] 1.1 In `src/views/templates/TemplatesIndex.vue:227-253`, wire `loadTemplates()` to the real `/apps/openregister/api/templates` endpoint (restore the commented call + error handling), OR gate the `src/registry.js`/`src/manifest.json` nav entry behind an explicit "coming soon" empty state — no silent-empty list, no swallowed errors.

## 2. NL Design colors

- [ ] 2.1 Replace inline hex colors with NC CSS variables across the hex-heavy files, starting with `src/modals/schema/ExploreSchema.vue` (`:67-100,1125-1161`), then `UploadSchema.vue`, `ViewObject.vue`, `UploadObject.vue`, `DownloadObject.vue`, `src/components/i18n/*`. Map to `--color-main-text`, `--color-border`, `--color-primary-element`, `--color-background-*`, etc.
- [ ] 2.2 Verify each in light AND dark mode; confirm WCAG AA contrast.

## 3. i18n

- [ ] 3.1 Wrap all user-facing strings in `src/views/settings/sections/CacheManagement.vue` (`:34-100+,295-297`) in `t('openregister', …)` / `n(...)`. Keys are English source.

## 4. Modal isolation

- [ ] 4.1 Extract the inline `NcDialog` in `CacheManagement.vue:288` to a file under `src/dialogs/`.
- [ ] 4.2 Extract the three inline `NcDialog`s in `ChatIndex.vue:185,201,226` to `src/dialogs/`.

## 5. Chart dependency

- [ ] 5.1 Import chart components from `@conduction/nextcloud-vue` in `DashboardIndex.vue:182`, `RegisterDetail.vue:216`, `SchemaDetails.vue:193`.
- [ ] 5.2 Remove `apexcharts`/`vue-apexcharts` from `package.json` direct deps; rebuild; confirm bundle still builds and charts render.

## 6. Verification

- [ ] 6.1 `npm run lint` / stylelint pass; no hardcoded hex in the touched files.
- [ ] 6.2 Templates view renders real data or an explicit coming-soon state.
- [ ] 6.3 Dark-mode visual check on the recolored views.
- [ ] 6.4 Charts render from the nc-vue-provided dependency.

## Acceptance criteria

- No shipped view silently renders empty while swallowing errors.
- Touched views use CSS variables, not inline hex; pass dark mode + WCAG AA.
- CacheManagement strings are translatable (English keys).
- Dialogs live under `src/dialogs/`, not inline.
- apexcharts is consumed from nc-vue, not a direct dep.
