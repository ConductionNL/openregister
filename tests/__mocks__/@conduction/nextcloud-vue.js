/* eslint-disable n/no-unpublished-import */
/**
 * Jest mock for @conduction/nextcloud-vue
 *
 * Re-exports the source modules needed by store tests without the CSS barrel
 * import that breaks Jest's transform pipeline.
 */

// Store factories
export { createCrudStore } from '../../../../nextcloud-vue/src/store/createCrudStore.js'
export { createObjectStore } from '../../../../nextcloud-vue/src/store/useObjectStore.js'

// Store plugins
export { filesPlugin } from '../../../../nextcloud-vue/src/store/plugins/files.js'
export { auditTrailsPlugin } from '../../../../nextcloud-vue/src/store/plugins/auditTrails.js'
export { relationsPlugin } from '../../../../nextcloud-vue/src/store/plugins/relations.js'
export { registerMappingPlugin } from '../../../../nextcloud-vue/src/store/plugins/registerMapping.js'
export { lifecyclePlugin } from '../../../../nextcloud-vue/src/store/plugins/lifecycle.js'
export { searchPlugin } from '../../../../nextcloud-vue/src/store/plugins/search.js'
export { selectionPlugin } from '../../../../nextcloud-vue/src/store/plugins/selection.js'

// Utilities
export { buildHeaders, buildQueryString } from '../../../../nextcloud-vue/src/utils/headers.js'
export { parseResponseError, networkError, genericError } from '../../../../nextcloud-vue/src/utils/errors.js'
