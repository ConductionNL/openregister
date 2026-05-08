<script>
/**
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-90
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
		normalizedObjects() {
			return normalizeObjects(objectStore.searchCollection)
		},
		/**
		 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-90
		 */
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
			objectStore.setObjectItem(row)
			navigationStore.setModal('viewObject')
		},
		handleCopyRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('copyObject')
		},
		handleDeleteRow(row) {
			objectStore.setObjectItem(row)
			navigationStore.setDialog('deleteObject')
		},
		async handleMassDelete(ids) {
			const type = this.computedObjectType
			if (!objectStore.objectTypes.includes(type)) {
				const schemaId = objectStore.searchParams?.schema
				const registerId = objectStore.searchParams?.register
				objectStore.registerObjectType(type, schemaId, registerId)
			}

			try {
				const result = await objectStore.deleteObjects(type, ids)
				objectStore.clearSelectedObjects()
				objectStore.refetchSearchCollection()
				this.$refs.indexPage?.setMassDeleteResult({ success: result.successfulIds.length > 0 })
			} catch (error) {
				this.$refs.indexPage?.setMassDeleteResult({ success: false, error: error.message || 'Delete failed' })
			}
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
