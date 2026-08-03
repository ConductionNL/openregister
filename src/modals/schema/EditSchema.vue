<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<!-- Vue 2 needs a single root; both dialogs teleport out of it anyway. -->
	<div class="edit-schema">
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
			:delete-label="t('openregister', 'Delete')"
			:delete-objects-tooltip="t('openregister', 'Delete all objects in this schema')"
			:cannot-delete-tooltip="t('openregister', 'Cannot delete: objects are still attached')"
			@confirm="onConfirm"
			@close="closeModal"
			@extend-schema="extendSchema"
			@analyze-properties="analyzeProperties"
			@validate-objects="validateObjects"
			@delete-objects="deleteObjects"
			@delete-schema="deleteSchema" />

		<!--
			The server classified the edit as BREAKING and will not apply it until it is
			acknowledged. Without this the edit was simply impossible from this app: the
			store's fetch threw the response body away and the dialog showed
			"HTTP error! status: 409". The wording comes from nc-vue's shared
			describeSchemaChange, so this app and OpenBuild cannot say different things
			about the same refusal.
		-->
		<NcDialog v-if="pendingBreaking"
			:name="t('openregister', 'Breaking change')"
			size="normal"
			class="cn-dialog--nested"
			@closing="cancelBreaking">
			<p class="breaking-lead">
				<strong>{{ t('openregister', 'This change breaks the existing data model:') }}</strong>
			</p>
			<ul class="breaking-changes">
				<li v-for="(change, i) in pendingBreaking.changes" :key="`bc-${i}`">
					{{ describeChange(change) }}
				</li>
			</ul>
			<p>{{ t('openregister', 'Objects already stored under this schema may no longer match it. Save anyway?') }}</p>
			<template #actions>
				<NcButton variant="tertiary" :disabled="savingBreaking" @click="cancelBreaking">
					{{ t('openregister', 'Back to editing') }}
				</NcButton>
				<NcButton variant="warning" :disabled="savingBreaking" @click="confirmBreaking">
					{{ t('openregister', 'Save anyway') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcDialog, NcButton } from '@nextcloud/vue'
import { CnSchemaFormDialog, describeSchemaChange, SchemaBreakingChangeError } from '@conduction/nextcloud-vue'

export default {
	name: 'EditSchema',
	components: {
		CnSchemaFormDialog,
		NcDialog,
		NcButton,
	},
	data() {
		return {
			userGroups: [],
			loadingGroups: false,
			availableTags: [],
			// Set when the server refused the save as a breaking change:
			// `{ schema, changes }`. Drives the acknowledgement dialog.
			pendingBreaking: null,
			savingBreaking: false,
		}
	},
	computed: {
		/**
		 * @spec exclude computed display helper listing other schemas for extension
		 */
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
					label: schema.title || schema.name || `Schema ${schema.id}`,
					description: schema.description || schema.summary || '',
					slug: schema.slug,
					properties: schema.properties || {},
					reference: `#/components/schemas/${schema.slug || schema.title || schema.id}`,
				}))
		},
		/**
		 * @spec exclude computed display helper listing registers for select
		 */
		computedAvailableRegisters() {
			return registerStore.registerList.map(register => ({
				id: register.id,
				label: register.title || register.name || register.id,
			}))
		},
		/**
		 * @spec exclude computed display helper merging inherited schema properties
		 */
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
	/**
	 * @spec exclude Vue lifecycle hook loading initial modal data
	 */
	mounted() {
		this.loadRegistersAndSchemas()
		this.loadUserGroups()
		this.fetchAvailableTags()
	},
	methods: {
		/**
		 * @spec exclude form-state loader for registers and schemas
		 */
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
		/**
		 * @spec exclude form-state loader for user groups via OCS API
		 */
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
		/**
		 * @spec exclude form-state fallback group defaults
		 */
		setFallbackGroups() {
			this.userGroups = [
				{ id: 'users', displayname: 'All Users' },
				{ id: 'editors', displayname: 'Editors' },
				{ id: 'managers', displayname: 'Managers' },
				{ id: 'viewers', displayname: 'Viewers' },
			]
		},
		/**
		 * @spec exclude form-state loader for available tags
		 */
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
		/**
		 * Save the schema. A breaking change comes back as a question rather than a
		 * failure — see the catch below.
		 *
		 * @param {object} schemaData - The schema payload from the editor.
		 * @param {boolean} [acknowledgeBreaking] - Re-save accepting the breaking change.
		 * @return {Promise<void>}
		 * @spec exclude modal submit handler delegating to schemaStore.saveSchema
		 */
		async onConfirm(schemaData, acknowledgeBreaking = false) {
			try {
				const { response } = await schemaStore.saveSchema(schemaData, { acknowledgeBreaking })
				this.pendingBreaking = null
				this.$refs.schemaFormDialog.setResult({
					success: response.ok,
					error: response.ok ? undefined : 'Failed to save schema',
				})
			} catch (error) {
				// A breaking change is a QUESTION, not a failure: show what the server
				// objected to and let the user decide. Never acknowledge on their behalf —
				// the first save always goes without the flag.
				//
				// The shared contract only raises this when the attempt was NOT already
				// acknowledged, so an acknowledged save that is still refused falls through
				// to the error branch instead of re-opening this prompt forever.
				if (error instanceof SchemaBreakingChangeError) {
					this.pendingBreaking = { schema: schemaData, changes: error.changes }
					return
				}
				this.pendingBreaking = null
				this.$refs.schemaFormDialog.setResult({
					error: error.message || 'An error occurred while saving the schema',
				})
			}
		},
		/**
		 * Re-save, this time accepting the breaking change.
		 *
		 * @spec exclude modal submit handler delegating to schemaStore.saveSchema
		 */
		async confirmBreaking() {
			const pending = this.pendingBreaking
			if (!pending) return
			this.savingBreaking = true
			try {
				await this.onConfirm(pending.schema, true)
			} finally {
				this.savingBreaking = false
			}
		},
		/**
		 * Abandon the acknowledgement; the editor stays open with the edits intact.
		 *
		 * @spec exclude modal close UI handler
		 */
		cancelBreaking() {
			this.pendingBreaking = null
		},
		/**
		 * Render one flagged change. Shared with OpenBuild's editor so the two apps
		 * describe the same refusal identically.
		 *
		 * @param {object} change - One change descriptor from the server.
		 * @return {string} The description.
		 * @spec exclude pure presentation helper
		 */
		describeChange(change) {
			return describeSchemaChange(change, t)
		},
		/**
		 * @spec exclude modal close UI handler
		 */
		closeModal() {
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
		},
		/**
		 * @spec exclude form-state helper seeding an extending schema
		 */
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
		/**
		 * @spec exclude dialog-open UI handler for schema analysis
		 */
		analyzeProperties() {
			navigationStore.setDialog('exploreSchema')
		},
		/**
		 * @spec exclude dialog-open UI handler for object validation
		 */
		validateObjects() {
			navigationStore.setDialog('validateSchema')
		},
		/**
		 * @spec exclude dialog-open UI handler for object deletion
		 */
		deleteObjects() {
			navigationStore.setDialog('deleteSchemaObjects')
		},
		/**
		 * @spec exclude dialog-open UI handler for schema deletion
		 */
		deleteSchema() {
			navigationStore.setDialog('deleteSchema')
		},
	},
}
</script>
