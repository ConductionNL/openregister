<script>
/**
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-across-registers-global-search
 */
import { NcAppContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

/**
 * Normalize list so each row has top-level id for CnIndexPage rowKey.
 * @param {Array} raw - Raw collection from store
 * @return {Array} Rows with row.id set from @self.id
 */
function normalizeObjects(raw) {
	if (!Array.isArray(raw)) return []
	return raw.map((row) => {
		const id = row['@self']?.id ?? row.id
		return { ...row, id }
	})
}

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		CnIndexPage,
		Pencil,
		ContentCopy,
		TrashCanOutline,
	},
	data() {
		return {
			objectStore,
			navigationStore,
			isAddingNewObject: false,
		}
	},
	computed: {
		/**
		 * Normalized search result objects for table display.
		 *
		 * @spec exclude UI plumbing — derived view state from the store
		 * @return {Array<object>}
		 */
		normalizedObjects() {
			return normalizeObjects(objectStore.searchCollection)
		},
		/**
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-across-registers-global-search
		 */
		hasSelectedRegisters() {
			return objectStore.searchParams.register != null
		},
		hasSelectedSchemas() {
			return objectStore.searchParams.schema != null
		},
		/**
		 * Page title derived from the selected register and schema.
		 *
		 * @spec exclude UI plumbing — derived header title for display
		 * @return {string}
		 */
		pageTitle() {
			if (!this.hasSelectedRegisters) return 'No register selected'
			const reg = registerStore.registerList.find((r) => r.id === objectStore.searchParams.register)
			const regTitle = reg ? (reg.label || reg.title) : 'Register'
			if (!this.hasSelectedSchemas) return `${regTitle} / No schema selected`
			const schema = schemaStore.schemaList.find((s) => s.id === objectStore.searchParams.schema)
			const schemaTitle = schema ? (schema.label || schema.title) : 'Schema'
			return `${regTitle} / ${schemaTitle}`
		},
		/**
		 * Selected object ids for the current page, as strings.
		 *
		 * @spec exclude UI plumbing — derived selection view state
		 * @return {Array<string>}
		 */
		selectedIdsForPage() {
			const list = objectStore.selectedObjects
			return Array.isArray(list) ? list.map(String) : []
		},
		/**
		 * Object-type slug derived from the selected register and schema.
		 *
		 * @spec exclude UI plumbing — derived identifier for the index page
		 * @return {string}
		 */
		computedObjectType() {
			return objectStore.createObjectTypeSlug(objectStore.searchRegister, objectStore.searchSchema)
		},
		/**
		 * Search schema with inherited (allOf) properties merged in, for columns.
		 *
		 * @spec exclude UI plumbing — derived schema view state for display
		 * @return {object}
		 */
		normalizedSchema() {
			const schema = objectStore.searchSchema
			if (!schema || !schema.properties) return schema
			// Merge inherited properties from allOf parent schemas so extended schemas
			// expose the full property set (own + inherited) for columns and form fields.
			const allOf = schema.allOf || []
			const inheritedProperties = {}
			for (const ref of allOf) {
				const schemaId = typeof ref === 'object' ? ref.id : ref
				const parentSchema = schemaStore.schemaList.find(s =>
					s.id === schemaId || s.uuid === schemaId || String(s.id) === String(schemaId),
				)
				if (parentSchema?.properties) {
					Object.assign(inheritedProperties, parentSchema.properties)
				}
			}
			// Own properties take precedence over inherited; normalize order values
			const rawProperties = { ...inheritedProperties, ...schema.properties }
			const properties = {}
			for (const [key, prop] of Object.entries(rawProperties)) {
				properties[key] = prop.order !== undefined
					? { ...prop, order: Number(prop.order) }
					: prop
			}
			return { ...schema, properties }
		},
	},
	watch: {
		'navigationStore.modal'(newVal, oldVal) {
			if (oldVal === 'viewObject' && !newVal && this.isAddingNewObject) {
				this.isAddingNewObject = false
			}
		},
	},
	methods: {
		/**
		 * Open the new-object dialog for the selected register and schema.
		 *
		 * @spec exclude UI plumbing — opens the create-object modal
		 * @return {void}
		 */
		handleAddObject() {
			if (!this.hasSelectedRegisters || !this.hasSelectedSchemas) return
			this.isAddingNewObject = true
			objectStore.setObjectItem(null)
			if (registerStore.registerItem) {
				registerStore.setRegisterItem(registerStore.registerItem)
			}
			if (schemaStore.schemaItem) {
				schemaStore.setSchemaItem(schemaStore.schemaItem)
			}
			navigationStore.setModal('viewObject')
		},
		/**
		 * Re-run the current search.
		 *
		 * @spec exclude UI plumbing — refresh delegates to the object store
		 * @return {void}
		 */
		handleRefresh() {
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a sort change and re-run the search.
		 *
		 * @param {object} root0 The sort event payload.
		 * @param {string} root0.key The sort column key.
		 * @param {string} root0.order The sort order.
		 * @spec exclude UI plumbing — sort handler delegates to the store
		 * @return {void}
		 */
		handleSort({ key, order }) {
			objectStore.updateSearchParams({ sortKey: key, sortOrder: order })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a page change and re-run the search.
		 *
		 * @param {number} page The new page number.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		handlePageChanged(page) {
			objectStore.updateSearchParams({ page })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Apply a page-size change and re-run the search.
		 *
		 * @param {number} limit The new page size.
		 * @spec exclude UI plumbing — pagination handler delegates to the store
		 * @return {void}
		 */
		handlePageSizeChanged(limit) {
			objectStore.updateSearchParams({ page: 1, limit })
			objectStore.refetchSearchCollection()
		},
		/**
		 * Track the selected object ids.
		 *
		 * @param {Array} ids The selected ids.
		 * @spec exclude UI plumbing — selection state delegates to the store
		 * @return {void}
		 */
		handleSelect(ids) {
			objectStore.setSelectedObjects(ids)
		},
		/**
		 * Open the view-object modal for a clicked row.
		 *
		 * @param {object} row The clicked object row.
		 * @spec exclude UI plumbing — opens the view-object modal
		 * @return {void}
		 */
		handleRowClick(row) {
			objectStore.setObjectItem(row)
			navigationStore.setModal('viewObject')
		},
		/**
		 * Open the copy-object dialog for a row.
		 *
		 * @param {object} row The object row to copy.
		 * @spec exclude UI plumbing — opens the copy-object dialog
		 * @return {void}
		 */
		handleCopyRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('copyObject')
		},
		/**
		 * Open the delete-object dialog for a row.
		 *
		 * @param {object} row The object row to delete.
		 * @spec exclude UI plumbing — opens the delete-object dialog
		 * @return {void}
		 */
		handleDeleteRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('deleteObject')
		},
		/**
		 * Open the mass-delete dialog for the selected ids.
		 *
		 * @param {Array} ids The selected row ids.
		 * @spec exclude UI plumbing — opens the mass-delete dialog
		 * @return {void}
		 */
		handleMassDelete(ids) {
			const rows = this.normalizedObjects.filter((r) => ids.includes(String(r.id)))
			objectStore.setSelectedObjects(rows.map((r) => r['@self']?.id ?? r.id))
			navigationStore.setDialog('massDeleteObject')
		},
		/**
		 * Open the mass-copy dialog for the selected ids.
		 *
		 * @param {object} payload The mass-copy event payload.
		 * @spec exclude UI plumbing — opens the mass-copy dialog
		 * @return {void}
		 */
		handleMassCopy(payload) {
			const ids = payload?.ids || []
			const rows = this.normalizedObjects.filter((r) => ids.includes(String(r.id)))
			objectStore.setSelectedObjects(rows.map((r) => r['@self']?.id ?? r.id))
			navigationStore.setDialog('massCopyObjects')
		},
	},
}
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:class="{ 'add-button-disabled': !hasSelectedRegisters || !hasSelectedSchemas }"
			:title="pageTitle"
			:schema="normalizedSchema"
			:register="objectStore.searchRegister"
			:objects="normalizedObjects"
			:store="objectStore"
			:object-type="computedObjectType"
			:loading="objectStore.searchLoading"
			:pagination="objectStore.searchPagination"
			row-key="id"
			:include-columns="objectStore.searchVisibleColumns && objectStore.searchVisibleColumns.length ? objectStore.searchVisibleColumns : null"
			:selectable="hasSelectedRegisters && hasSelectedSchemas"
			:selected-ids="selectedIdsForPage"
			:sort-key="objectStore.searchParams.sortKey"
			:sort-order="objectStore.searchParams.sortOrder"
			:show-title="false"
			:show-mass-import="false"
			:show-mass-export="false"
			use-advanced-form-dialog
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			show-mass-copy
			show-mass-delete
			mass-action-name-field="title"
			empty-text="No objects found. Select registers and schemas in the sidebar, then search."
			@add="handleAddObject"
			@refresh="handleRefresh"
			@mass-delete="handleMassDelete"
			@mass-copy="handleMassCopy"
			@row-click="handleRowClick"
			@sort="handleSort"
			@page-changed="handlePageChanged"
			@page-size-changed="handlePageSizeChanged"
			@select="handleSelect">
			<template #row-actions="{ row }">
				<NcActions>
					<NcActionButton close-after-click @click="handleRowClick(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click @click="handleCopyRow(row)">
						<template #icon>
							<ContentCopy :size="20" />
						</template>
						Copy
					</NcActionButton>
					<NcActionButton close-after-click @click="handleDeleteRow(row)">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<style scoped>
.add-button-disabled :deep(.cn-actions-bar .button-vue--vue-primary) {
	opacity: 0.5;
	cursor: not-allowed;
	pointer-events: none;
}
</style>
