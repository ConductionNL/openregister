<script>
import { NcAppContent } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'

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
		CnIndexPage,
	},
	data() {
		return {
			objectStore,
		}
	},
	computed: {
		normalizedObjects() {
			return normalizeObjects(objectStore.searchCollection)
		},
		hasSelectedRegisters() {
			return objectStore.searchParams.register != null
		},
		hasSelectedSchemas() {
			return objectStore.searchParams.schema != null
		},
		pageTitle() {
			if (!this.hasSelectedRegisters) return 'No register selected'
			const reg = registerStore.registerList.find((r) => r.id === objectStore.searchParams.register)
			const regTitle = reg ? (reg.label || reg.title) : 'Register'
			if (!this.hasSelectedSchemas) return `${regTitle} / No schema selected`
			const schema = schemaStore.schemaList.find((s) => s.id === objectStore.searchParams.schema)
			const schemaTitle = schema ? (schema.label || schema.title) : 'Schema'
			return `${regTitle} / ${schemaTitle}`
		},
		selectedIdsForPage() {
			const list = objectStore.selectedObjects
			return Array.isArray(list) ? list.map(String) : []
		},
		computedObjectType() {
			return objectStore.createObjectTypeSlug(objectStore.searchRegister, objectStore.searchSchema)
		},
		normalizedSchema() {
			const schema = objectStore.searchSchema
			if (!schema || !schema.properties) return schema
			const properties = {}
			for (const [key, prop] of Object.entries(schema.properties)) {
				properties[key] = prop.order !== undefined
					? { ...prop, order: Number(prop.order) }
					: prop
			}
			return { ...schema, properties }
		},
	},
	methods: {
		handleRefresh() {
			objectStore.refetchSearchCollection()
		},
		handleSort({ key, order }) {
			objectStore.updateSearchParams({ sortKey: key, sortOrder: order })
			objectStore.refetchSearchCollection()
		},
		handlePageChanged(page) {
			objectStore.updateSearchParams({ page })
			objectStore.refetchSearchCollection()
		},
		handlePageSizeChanged(limit) {
			objectStore.updateSearchParams({ page: 1, limit })
			objectStore.refetchSearchCollection()
		},
		handleSelect(ids) {
			objectStore.setSelectedObjects(ids)
		},
		handleRowClick(row) {
			navigationStore.setModal('viewObject')
			objectStore.setObjectItem(row)
		},
		handleDelete(id) {
			const row = this.normalizedObjects.find((r) => String(r.id) === String(id))
			if (row) {
				navigationStore.setDialog('deleteObject')
				objectStore.setObjectItem({ '@self': row['@self'] || { id: row.id } })
			}
		},
		handleCopy({ id, newName: _newName }) {
			const row = this.normalizedObjects.find((r) => String(r.id) === String(id))
			if (row) {
				objectStore.setObjectItem(row)
				navigationStore.setDialog('copyObject')
			}
		},
		handleMassDelete(ids) {
			const rows = this.normalizedObjects.filter((r) => ids.includes(String(r.id)))
			objectStore.setSelectedObjects(rows.map((r) => r['@self']?.id ?? r.id))
			navigationStore.setDialog('massDeleteObject')
		},
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
		<!-- creation logic is handled inside CnIndexPage due to store and object-type props -->
		<CnIndexPage
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
			show-mass-copy
			show-mass-delete
			mass-action-name-field="title"
			empty-text="No objects found. Select registers and schemas in the sidebar, then search."
			@refresh="handleRefresh"
			@delete="handleDelete"
			@copy="handleCopy"
			@mass-delete="handleMassDelete"
			@mass-copy="handleMassCopy"
			@row-click="handleRowClick"
			@sort="handleSort"
			@page-changed="handlePageChanged"
			@page-size-changed="handlePageSizeChanged"
			@select="handleSelect" />
	</NcAppContent>
</template>
