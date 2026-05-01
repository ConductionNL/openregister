<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<CnSchemaFormDialog
		ref="schemaFormDialog"
		:item="schemaStore.schemaItem"
		:dialog-title="schemaStore.schemaItem?.id ? t('openregister', 'Edit Schema') : t('openregister', 'Add Schema')"
		:available-schemas="computedAvailableSchemas"
		:available-registers="computedAvailableRegisters"
		:inherited-properties="computedInheritedProperties"
		:user-groups="userGroups"
		:loading-groups="loadingGroups"
		:available-tags="availableTags"
		:object-count="schemaStore.schemaItem?.stats?.objects?.total || 0"
		show-extend-schema
		show-analyze-properties
		show-validate-objects
		show-delete-objects
		show-publish-objects
		show-delete
		:cancel-label="t('openregister', 'Cancel')"
		:close-label="t('openregister', 'Close')"
		:confirm-label="schemaStore.schemaItem?.id ? t('openregister', 'Save') : t('openregister', 'Create')"
		:success-text="schemaStore.schemaItem?.id
			? t('openregister', 'Schema successfully updated')
			: t('openregister', 'Schema successfully created')"
		:extend-schema-label="t('openregister', 'Extend Schema')"
		:analyze-properties-label="t('openregister', 'Analyze Properties')"
		:validate-objects-label="t('openregister', 'Validate Objects')"
		:delete-objects-label="t('openregister', 'Delete Objects')"
		:publish-objects-label="t('openregister', 'Publish Objects')"
		:delete-label="t('openregister', 'Delete')"
		:delete-objects-tooltip="t('openregister', 'Delete all objects in this schema')"
		:publish-objects-tooltip="t('openregister', 'Publish all objects in this schema')"

		:cannot-delete-tooltip="t('openregister', 'Cannot delete: objects are still attached')"
		@confirm="onConfirm"
		@close="closeModal"
		@extend-schema="extendSchema"
		@analyze-properties="analyzeProperties"
		@validate-objects="validateObjects"
		@delete-objects="deleteObjects"
		@publish-objects="publishObjects"
		@delete-schema="deleteSchema" />
</template>

<script>
import { CnSchemaFormDialog } from '@conduction/nextcloud-vue'

export default {
	name: 'EditSchema',
	components: {
		CnSchemaFormDialog,
	},
	data() {
		return {
			userGroups: [],
			loadingGroups: false,
			availableTags: [],
		}
	},
	computed: {
		computedAvailableSchemas() {
			const currentId = schemaStore.schemaItem?.id
			const currentUuid = schemaStore.schemaItem?.uuid
			const currentSlug = schemaStore.schemaItem?.slug

			return schemaStore.schemaList
				.filter(schema =>
					schema.id !== currentId
					&& schema.uuid !== currentUuid
					&& schema.slug !== currentSlug,
				)
				.map(schema => ({
					id: schema.id || schema.uuid || schema.slug,
					title: schema.title || schema.name || `Schema ${schema.id}`,
					description: schema.description || schema.summary || '',
					reference: `#/components/schemas/${schema.slug || schema.title || schema.id}`,
				}))
		},
		computedAvailableRegisters() {
			return registerStore.registerList.map(register => ({
				id: register.id,
				label: register.title || register.name || register.id,
			}))
		},
		computedInheritedProperties() {
			const allOf = schemaStore.schemaItem?.allOf || []
			if (!allOf.length) return {}

			const merged = {}
			for (const ref of allOf) {
				const schemaId = typeof ref === 'object' ? ref.id : ref
				const parentSchema = schemaStore.schemaList.find(s =>
					s.id === schemaId || s.uuid === schemaId || s.slug === schemaId,
				)
				if (parentSchema?.properties) {
					Object.assign(merged, parentSchema.properties)
				}
			}
			return merged
		},
	},
	mounted() {
		this.loadRegistersAndSchemas()
		this.loadUserGroups()
		this.fetchAvailableTags()
	},
	methods: {
		async loadRegistersAndSchemas() {
			try {
				if (!registerStore.registerList.length) {
					await registerStore.refreshRegisterList()
				}
				if (!schemaStore.schemaList.length) {
					await schemaStore.refreshSchemaList()
				}
			} catch (error) {
				console.error('Error loading registers and schemas:', error)
			}
		},
		async loadUserGroups() {
			this.loadingGroups = true
			try {
				const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
					headers: { 'OCS-APIRequest': 'true' },
				})

				if (response.ok) {
					const data = await response.json()
					if (data.ocs?.data?.groups) {
						this.userGroups = data.ocs.data.groups.map(groupId => ({
							id: groupId,
							displayname: groupId,
						}))
					} else {
						this.setFallbackGroups()
					}
				} else {
					this.setFallbackGroups()
				}
			} catch (error) {
				console.error('Error loading user groups:', error)
				this.setFallbackGroups()
			} finally {
				this.loadingGroups = false
			}
		},
		setFallbackGroups() {
			this.userGroups = [
				{ id: 'users', displayname: 'All Users' },
				{ id: 'editors', displayname: 'Editors' },
				{ id: 'managers', displayname: 'Managers' },
				{ id: 'viewers', displayname: 'Viewers' },
			]
		},
		async fetchAvailableTags() {
			try {
				const response = await fetch('/index.php/apps/openregister/api/tags')
				if (response.ok) {
					const tags = await response.json()
					this.availableTags = Array.isArray(tags) ? tags : []
				} else {
					this.availableTags = []
				}
			} catch (error) {
				console.error('Error fetching available tags:', error)
				this.availableTags = []
			}
		},
		async onConfirm(schemaData) {
			try {
				const { response } = await schemaStore.saveSchema(schemaData)
				this.$refs.schemaFormDialog.setResult({
					success: response.ok,
					error: response.ok ? undefined : 'Failed to save schema',
				})
			} catch (error) {
				this.$refs.schemaFormDialog.setResult({
					error: error.message || 'An error occurred while saving the schema',
				})
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
		},
		extendSchema() {
			const currentItem = schemaStore.schemaItem
			const newSchema = {
				title: `Extended ${currentItem.title}`,
				description: `Schema extending ${currentItem.title}`,
				allOf: [currentItem.id],
				properties: {},
				required: [],
			}
			schemaStore.setSchemaItem(newSchema)
		},
		analyzeProperties() {
			navigationStore.setDialog('exploreSchema')
		},
		validateObjects() {
			navigationStore.setDialog('validateSchema')
		},
		deleteObjects() {
			navigationStore.setDialog('deleteSchemaObjects')
		},
		publishObjects() {
			navigationStore.setDialog('publishSchemaObjects')
		},
		deleteSchema() {
			navigationStore.setDialog('deleteSchema')
		},
	},
}
</script>
