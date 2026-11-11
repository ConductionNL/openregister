/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the dashboard and search sidebars share about the register and schema
 * lists behind their pickers and the object columns of the picked schema.
 *
 * @spec exclude Client-side list loading shared by two sidebars.
 */
import { objectStore, registerStore, schemaStore } from '../store/store.js'

/**
 * Load the register list when it is still empty; the sidebar stops showing
 * it loading once the list is there.
 *
 * @param {object} sidebar The sidebar, with `registerLoading`
 * @return {void}
 */
function loadRegisterList(sidebar) {
	if (!registerStore.registerList.length) {
		registerStore
			.refreshRegisterList()
			.finally(() => (sidebar.registerLoading = false))
	} else {
		sidebar.registerLoading = false
	}
}

/**
 * Load the schema list when it is still empty; the sidebar stops showing it
 * loading once the list is there.
 *
 * @param {object} sidebar The sidebar, with `schemaLoading`
 * @return {void}
 */
function loadSchemaList(sidebar) {
	if (!schemaStore.schemaList.length) {
		schemaStore
			.refreshSchemaList()
			.finally(() => (sidebar.schemaLoading = false))
	} else {
		sidebar.schemaLoading = false
	}
}

/**
 * A mixin for a sidebar with `registerLoading` and `schemaLoading` data.
 */
export const scopeListLoader = {
	computed: {
		/**
		 * Flatten the object metadata map into column descriptors for display.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Array} Metadata column descriptors
		 */
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta,
			}))
		},
	},

	methods: {
		/**
		 * Load the register and schema lists when they are still empty, and
		 * clear each loading flag once its list is there.
		 *
		 * @spec exclude Lifecycle plumbing; fire-and-forget load of the register and schema lists.
		 * @return {void}
		 */
		loadScopeLists() {
			loadRegisterList(this)
			loadSchemaList(this)
		},

		/**
		 * Initialise the object properties for a newly selected schema, or
		 * clear them when the schema is cleared.
		 *
		 * @param {object|null} newSchema The selected schema
		 * @spec exclude Vue watch handler plumbing; re-initialises object properties when the selected schema changes.
		 * @return {void}
		 */
		syncSchemaProperties(newSchema) {
			if (newSchema) {
				objectStore.initializeProperties(newSchema)
			} else {
				objectStore.properties = {}
				objectStore.initializeColumnFilters()
			}
		},
	},
}
