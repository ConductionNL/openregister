<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { registerStore, dashboardStore, navigationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<Fragment>
		<NcAppSidebar
			v-if="register"
			ref="sidebar"
			v-model="activeTab"
			:name="register.title"
			:subtitle="register.description"
			subname="Register Details"
			:open="navigationStore.sidebarState.register"
			@update:open="(e) => {
				navigationStore.setSidebarState('register', e)
			}">
			<template #secondary-actions>
				<NcActionButton @click="showEditDialog = true">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('openregister', 'Edit Register') }}
				</NcActionButton>
				<NcActionButton @click="calculateSizes">
					<template #icon>
						<Calculator :size="20" />
					</template>
					{{ t('openregister', 'Calculate Sizes') }}
				</NcActionButton>
				<NcActionButton @click="downloadOas">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('openregister', 'Download API Spec') }}
				</NcActionButton>
				<NcActionButton @click="viewOasDoc">
					<template #icon>
						<ApiIcon :size="20" />
					</template>
					{{ t('openregister', 'View API Docs') }}
				</NcActionButton>
			</template>

			<NcAppSidebarTab id="stats-tab" name="Statistics" :order="1">
				<template #icon>
					<ChartBar :size="20" />
				</template>

				<div class="section">
					<div class="sectionTitle">
						{{ t('openregister', 'Statistics') }}
					</div>
					<div class="statsStack">
						<CnStatsBlock
							:title="t('openregister', 'Objects')"
							:count="register.stats?.objects?.total || 0"
							:count-label="t('openregister', 'object{plural}', {
								plural: register.stats?.objects?.total !== 1 ? 's' : ''
							})"
							:icon="PackageVariantClosed"
							variant="primary"
							horizontal
							show-zero-count
							:breakdown="objectsBreakdown" />
						<CnStatsBlock
							:title="t('openregister', 'Logs')"
							:count="register.stats?.logs?.total || 0"
							:count-label="t('openregister', 'log{plural}', {
								plural: register.stats?.logs?.total !== 1 ? 's' : ''
							})"
							:icon="TextBoxOutline"
							horizontal
							show-zero-count
							:breakdown="sizeBreakdown(register.stats?.logs?.size)" />
						<CnStatsBlock
							:title="t('openregister', 'Files')"
							:count="register.stats?.files?.total || 0"
							:count-label="t('openregister', 'file{plural}', {
								plural: register.stats?.files?.total !== 1 ? 's' : ''
							})"
							:icon="FileDocumentOutline"
							horizontal
							show-zero-count
							:breakdown="sizeBreakdown(register.stats?.files?.size)" />
						<CnStatsBlock
							:title="t('openregister', 'Schemas')"
							:count="register.schemas?.length || 0"
							:count-label="t('openregister', 'schema{plural}', {
								plural: register.schemas?.length !== 1 ? 's' : ''
							})"
							:icon="FileCodeOutline"
							horizontal
							show-zero-count />
					</div>
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="schemas-tab" name="Schemas" :order="2">
				<template #icon>
					<FileCodeOutline :size="20" />
				</template>

				<div class="section">
					<div class="sectionTitle">
						{{ t('openregister', 'Schemas') }}
					</div>
					<div v-if="!register.schemas?.length" class="emptyContainer">
						<NcEmptyContent
							:title="t('openregister', 'No schemas found')"
							icon="icon-folder">
							<template #action>
								<NcButton @click="showEditDialog = true">
									{{ t('openregister', 'Add Schema') }}
								</NcButton>
							</template>
						</NcEmptyContent>
					</div>
					<div v-else class="schemaList">
						<CnItemCard
							v-for="schema in register.schemas"
							:key="schema.id"
							:title="schema.title"
							:icon="FileCodeOutline">
							<template #actions>
								<NcActions :primary="true" menu-name="Schema Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="editSchema(schema)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit Schema
									</NcActionButton>
								</NcActions>
							</template>
							<CnKpiGrid :columns="2">
								<CnStatsBlock
									:title="t('openregister', 'Total Objects')"
									:count="schema.stats?.objects?.total || 0"
									show-zero-count />
								<CnStatsBlock
									:title="t('openregister', 'Total Size')"
									:count="0"
									show-zero-count
									:breakdown="sizeBreakdown(schema.stats?.objects?.size)" />
							</CnKpiGrid>
						</CnItemCard>
					</div>
				</div>
			</NcAppSidebarTab>
		</NcAppSidebar>

		<CnFormDialog
			v-if="showEditDialog"
			ref="formDialog"
			:schema="registerSchema"
			:item="register"
			:dialog-title="t('openregister', 'Edit Register')"
			@confirm="onSaveRegister"
			@close="showEditDialog = false">
			<template #form="{ formData, errors, updateField }">
				<div class="formContainer">
					<NcTextField
						:label="t('openregister', 'Title') + ' *'"
						:value="formData.title || ''"
						:error="!!errors.title"
						:helper-text="errors.title"
						@update:value="v => updateField('title', v)" />
					<NcTextField
						:label="t('openregister', 'Slug') + ' *'"
						:value="formData.slug || ''"
						:error="!!errors.slug"
						:helper-text="errors.slug"
						@update:value="v => updateField('slug', v)" />
					<NcTextArea
						:label="t('openregister', 'Description')"
						:value="formData.description || ''"
						@update:value="v => updateField('description', v)" />
					<NcSelect
						:input-label="t('openregister', 'Schemas')"
						:options="schemaSelectOptions"
						:value="getSchemaSelectValue(formData.schemas)"
						:multiple="true"
						:close-on-select="false"
						:loading="schemasLoading"
						@input="vals => updateField('schemas', vals)" />
					<RegisterLanguagesEditor
						:value="formData.languages || []"
						:label="t('openregister', 'Languages')"
						:helper-text="t('openregister', 'Ordered BCP 47 language tags. The first language is the register default and drives Accept-Language fallback for translatable properties.')"
						@input="vals => updateField('languages', vals)" />
				</div>
			</template>
		</CnFormDialog>
	</Fragment>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcEmptyContent, NcActions, NcActionButton, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
import { CnStatsBlock, CnKpiGrid, CnItemCard, CnFormDialog } from '@conduction/nextcloud-vue'
import RegisterLanguagesEditor from '../../components/i18n/RegisterLanguagesEditor.vue'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import formatBytes from '../../services/formatBytes.js'

export default {
	name: 'RegisterSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		CnStatsBlock,
		CnKpiGrid,
		CnItemCard,
		CnFormDialog,
		RegisterLanguagesEditor,
		ChartBar,
		FileCodeOutline,
		Pencil,
		Calculator,
		Download,
		ApiIcon,
		DotsHorizontal,
	},
	data() {
		return {
			// Icon components for CnStatsBlock
			PackageVariantClosed,
			TextBoxOutline,
			FileDocumentOutline,
			showEditDialog: false,
			schemaSelectOptions: [],
			schemasLoading: false,
		}
	},
	computed: {
		/**
		 * Resolve the active register (with stats) from the dashboard store.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @return {object|undefined} The active register record
		 */
		register() {
			// Find the register in the dashboard store using the ID from the register
			// store, falling back to the route param on a deep link / page refresh.
			// Mirrors RegisterDetail.register — the whole sidebar is v-if'd on this.
			const registerId = registerStore.getRegisterItem?.id || this.$route.params.id
			if (!registerId) {
				return undefined
			}
			// Route params are strings, the API returns numeric ids — compare as strings.
			return dashboardStore.registers.find(r => String(r.id) === String(registerId))
		},
		activeTab: {
			/**
			 * @spec exclude Tab-switch state accessor; proxies the active tab to/from the register store, no domain behavior.
			 */
			get() {
				return registerStore.getActiveTab
			},
			/**
			 * @param value
			 * @spec exclude Tab-switch state mutator; proxies the active tab to the register store, no domain behavior.
			 */
			set(value) {
				registerStore.setActiveTab(value)
			},
		},
		/**
		 * @spec exclude Presentation-only formatter; builds the object stats breakdown (size/invalid/deleted/locked) for display.
		 * @return {object} Breakdown descriptor for the objects stats block
		 */
		objectsBreakdown() {
			const stats = this.register?.stats?.objects
			const breakdown = {
				size: formatBytes(stats.size),
				invalid: stats.invalid || 0,
				deleted: stats.deleted || 0,
				locked: stats.locked || 0,
			}
			return breakdown
		},
		/**
		 * Build the schema definition used by the inline register-edit form dialog.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @return {object} JSON-schema-like definition for the edit form
		 */
		registerSchema() {
			return {
				title: t('openregister', 'Register'),
				properties: {
					title: { type: 'string', title: t('openregister', 'Title'), required: true, minLength: 1, order: 1 },
					slug: { type: 'string', title: t('openregister', 'Slug'), required: true, minLength: 1, order: 2 },
					description: { type: 'string', title: t('openregister', 'Description'), order: 3 },
					schemas: { type: 'array', title: t('openregister', 'Schemas'), order: 4 },
					languages: { type: 'array', title: t('openregister', 'Languages'), order: 5 },
				},
				required: ['title', 'slug'],
			}
		},
	},
	watch: {
		/**
		 * Load schema options when the register-edit dialog opens.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @param {boolean} val - Whether the edit dialog is open
		 * @return {void}
		 */
		showEditDialog(val) {
			if (val) {
				this.loadSchemaOptions()
			}
		},
	},
	methods: {
		/**
		 * @spec exclude Presentation-only formatter; renders a byte size as a stats breakdown descriptor.
		 * @param {number} size - Byte size
		 * @return {object|null} Breakdown descriptor or null
		 */
		sizeBreakdown(size) {
			if (!size) return null
			return { size: formatBytes(size) }
		},

		/**
		 * Load the schema list and build select options for the register-edit form.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @return {Promise<void>}
		 */
		async loadSchemaOptions() {
			this.schemasLoading = true
			try {
				await schemaStore.refreshSchemaList()
				this.schemaSelectOptions = schemaStore.schemaList.map(s => ({ id: s.id, label: s.title }))
			} catch (error) {
				console.error('Failed to load schemas:', error)
			} finally {
				this.schemasLoading = false
			}
		},

		/**
		 * Resolve persisted schema references into select-option shape for the edit form.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @param {Array} schemas - Schema ids or objects
		 * @return {Array} Select options
		 */
		getSchemaSelectValue(schemas) {
			if (!Array.isArray(schemas)) return []
			return schemas.map(s => {
				const id = typeof s === 'object' ? s.id : s
				return this.schemaSelectOptions.find(o => String(o.id) === String(id))
					|| { id, label: String(id) }
			})
		},

		/**
		 * Submit the register-edit form through the register store and refresh dashboard stats.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @param {object} formData - Edited register fields
		 * @return {Promise<void>}
		 */
		async onSaveRegister(formData) {
			try {
				await registerStore.saveRegister({
					...formData,
					schemas: (formData.schemas || []).map(s => typeof s === 'object' ? s.id : s),
				})
				this.$refs.formDialog.setResult({ success: true })
				await dashboardStore.fetchRegisters()
			} catch (error) {
				this.$refs.formDialog.setResult({ error: error.message })
			}
		},

		/**
		 * Trigger a server-side size recalculation for the register and refresh stats.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Promise<void>}
		 */
		async calculateSizes() {
			if (!this.register) return

			try {
				await dashboardStore.calculateSizes(this.register.id)
				await dashboardStore.fetchRegisters()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
			}
		},

		/**
		 * Fetch the register's generated OpenAPI spec and download it as a JSON file.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-10
		 * @return {Promise<void>}
		 */
		async downloadOas() {
			if (!this.register) return

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${this.register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		/**
		 * Open the register's generated OpenAPI spec in an interactive ReDoc viewer.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-10
		 * @return {void}
		 */
		viewOasDoc() {
			if (!this.register) return

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		/**
		 * Stage a schema for editing and open the schema-edit modal.
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @param {object} schema - The schema to edit
		 * @return {void}
		 */
		editSchema(schema) {
			registerStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},
	},
}
</script>

<style lang="scss" scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.statsStack {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 8px;
}

.schemaList {
	padding: 0 16px;
}

.emptyContainer {
	padding: 0 16px;
}
</style>
