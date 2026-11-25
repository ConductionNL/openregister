// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * The parts of @conduction/nextcloud-vue the integration bootstrap needs,
 * imported from their own modules instead of the package barrel.
 *
 * The bootstrap runs from `openregister-integration-global.js`, which Nextcloud
 * loads on EVERY page. The package marks its `.vue` modules as having side
 * effects, so importing even one name from the barrel kept every component the
 * barrel re-exports (and through them the whole @nextcloud/vue index and
 * @mdi/js) in that script. These three modules import only the registry and the
 * descriptors; the tab and card components behind the descriptors load lazily
 * when an integration is first rendered.
 *
 * `webpack.config.js` maps `@conduction/nextcloud-vue/dist/esm` onto the
 * library's `src/` when building against a sibling checkout (USE_LOCAL_LIB).
 */
export { getSharedRegistry } from '@conduction/nextcloud-vue/dist/esm/integrations/registry.js'
export { registerBuiltinIntegrations } from '@conduction/nextcloud-vue/dist/esm/integrations/builtin/index.js'
export { registerLeafIntegrations } from '@conduction/nextcloud-vue/dist/esm/integrations/builtin/leaves.js'
