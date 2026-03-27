<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { registerStore, navigationStore, configurationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Registers')"
			:description="t('openregister', 'Manage your data registers and their configurations')"
			:show-title="true"
			:objects="filteredRegisters"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="registerStore.loading"
			:view-mode="registerStore.viewMode"
			:selectable="true"
			:selected-ids="selectedRegisters"
			:schema="registerSchema"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Register"
			row-key="id"
			:empty-text="emptyContentName"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			@create="onSaveRegister"
			@edit="onSaveRegister"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="registerStore.setViewMode($event)"
			@select="onSelect"
			@row-click="viewRegisterDetails">
			<!-- Custom form fields for the built-in CnFormDialog -->
			<template #form-fields="{ formData, errors, updateField }">
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
						input-label="Schemas"
						:options="schemaSelectOptions"
						:value="getSchemaSelectValue(formData.schemas)"
						:multiple="true"
						:close-on-select="false"
						:loading="schemasLoading"
						@input="vals => updateField('schemas', vals)" />
				</div>
			</template>

			<!-- Custom action items in actions bar -->
			<template #action-items>
				<NcActionButton close-after-click @click="registerStore.setRegisterItem(null); navigationStore.setModal('importRegister')">
					<template #icon>
						<Upload :size="20" />
					</template>
					Import
				</NcActionButton>
				<NcActionButton close-after-click @click="openAllApisDoc">
					<template #icon>
						<ApiIcon :size="20" />
					</template>
					View APIs
				</NcActionButton>
				<NcActionButton close-after-click @click="warmupNamesCache">
					<template #icon>
						<CloudUploadOutline :size="20" />
					</template>
					{{ t('openregister', 'Warmup Names Cache') }}
				</NcActionButton>
			</template>

			<!-- Custom card template -->
			<template #card="{ object }">
				<RegisterSchemaCard :item="object" type="register" @refresh="handleRefresh" />
			</template>

			<!-- Custom column: title with managed badge -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>
						{{ row.title }}
						<span v-if="isManagedByExternalConfig(row)" class="managedBadge managedBadge--external">
							<CogOutline :size="16" />
							{{ t('openregister', 'Managed') }}
						</span>
						<span v-else-if="isManagedByLocalConfig(row)" class="managedBadge managedBadge--local">
							<CogOutline :size="16" />
							{{ t('openregister', 'Local') }}
						</span>
					</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: schemas count -->
			<template #column-schemas="{ row }">
				{{ row.schemas?.length || 0 }} {{ t('openregister', 'schema{plural}', {
					plural: row.schemas?.length !== 1 ? 's' : ''
				}) }}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{ row.created ? new Date(row.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{ row.updated ? new Date(row.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						v-tooltip="isManagedByExternalConfig(row) ? 'Cannot edit: This register is managed by external configuration ' + getManagingConfiguration(row)?.title : ''"
						close-after-click
						:disabled="isManagedByExternalConfig(row)"
						@click="$refs.indexPage.openFormDialog(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton
						v-if="!row.published || (row.depublished && new Date(row.depublished) <= new Date())"
						close-after-click
						@click="publishRegister(row)">
						<template #icon>
							<Publish :size="20" />
						</template>
						Publish
					</NcActionButton>
					<NcActionButton
						v-if="row.published && (!row.depublished || new Date(row.depublished) > new Date())"
						close-after-click
						@click="depublishRegister(row)">
						<template #icon>
							<PublishOff :size="20" />
						</template>
						Depublish
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(row); navigationStore.setModal('publishRegister')">
						<template #icon>
							<CloudUploadOutline :size="20" />
						</template>
						Publish OAS
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(row); navigationStore.setModal('importRegister')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Import
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(row); viewOasDoc(row)">
						<template #icon>
							<ApiIcon :size="20" />
						</template>
						View API Documentation
					</NcActionButton>
					<NcActionButton close-after-click @click="registerStore.setRegisterItem(row); downloadOas(row)">
						<template #icon>
							<Download :size="20" />
						</template>
						Download API Specification
					</NcActionButton>
					<NcActionButton v-tooltip="row.stats?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
						close-after-click
						:disabled="row.stats?.total > 0"
						@click="registerStore.setRegisterItem(row); navigationStore.setDialog('deleteRegister')">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
					<NcActionButton close-after-click @click="viewRegisterDetails(row)">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						View Details
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActions, NcActionButton, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Download from 'vue-material-design-icons/Download.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'

export default {
	name: 'RegistersIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Upload,
		ApiIcon,
		Download,
		InformationOutline,
		CogOutline,
		CloudUploadOutline,
		Publish,
		PublishOff,
		RegisterSchemaCard,
	},
	data() {
		return {
			selectedRegisters: [],
			isRefreshing: false,
			schemaSelectOptions: [],
			schemasLoading: false,
		}
	},
	computed: {
		registerStore() {
			return registerStore
		},
		registerSchema() {
			return {
				title: t('openregister', 'Register'),
				properties: {
					title: { type: 'string', title: t('openregister', 'Title'), required: true, minLength: 1, order: 1 },
					slug: { type: 'string', title: t('openregister', 'Slug'), required: true, minLength: 1, order: 2 },
					description: { type: 'string', title: t('openregister', 'Description'), order: 3 },
					schemas: { type: 'array', title: t('openregister', 'Schemas'), order: 4 },
				},
				required: ['title', 'slug'],
			}
		},
		filteredRegisters() {
			return registerStore.registerList.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'schemas', label: t('openregister', 'Schemas') },
				{ key: 'created', label: t('openregister', 'Created'), sortable: true },
				{ key: 'updated', label: t('openregister', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = registerStore.pagination.page || 1
			const limit = registerStore.pagination.limit || 20
			const total = this.filteredRegisters.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		emptyContentName() {
			if (registerStore.error) {
				return registerStore.error
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers found')
			}
			return t('openregister', 'Loading registers...')
		},
	},
	async mounted() {
		try {
			this.schemasLoading = true
			await Promise.all([
				registerStore.refreshRegisterList(),
				configurationStore.refreshConfigurationList(),
				schemaStore.refreshSchemaList(),
			])
			this.schemaSelectOptions = schemaStore.schemaList.map(s => ({ id: s.id, label: s.title }))
		} catch (error) {
			console.error('Failed to load data:', error)
		} finally {
			this.schemasLoading = false
		}
	},
	methods: {
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await registerStore.refreshRegisterList()
			} finally {
				this.isRefreshing = false
			}
		},

		onPageChanged(page) {
			registerStore.setPagination(page, registerStore.pagination.limit)
		},

		onPageSizeChanged(pageSize) {
			registerStore.setPagination(1, pageSize)
		},

		onSelect(ids) {
			this.selectedRegisters = ids
		},

		getRowClass(register) {
			if (this.isManagedByExternalConfig(register)) return 'viewTableRow--managed'
			if (this.isManagedByLocalConfig(register)) return 'viewTableRow--local'
			return ''
		},

		getManagingConfiguration(register) {
			if (!register || !register.id) return null
			return configurationStore.configurationList.find(
				config => config.registers && config.registers.includes(register.id),
			) || null
		},

		isManagedByExternalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},

		isManagedByLocalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},

		getSchemaSelectValue(schemas) {
			if (!Array.isArray(schemas)) return []
			return schemas.map(s => {
				const id = typeof s === 'object' ? s.id : s
				return this.schemaSelectOptions.find(o => String(o.id) === String(id))
					|| { id, label: String(id) }
			})
		},

		async onSaveRegister(formData) {
			try {
				await registerStore.saveRegister({
					...formData,
					schemas: (formData.schemas || []).map(s => typeof s === 'object' ? s.id : s),
				})
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message })
			}
		},

		async publishRegister(register) {
			try {
				await registerStore.publishRegister(register.id)
				showSuccess(t('openregister', 'Register published successfully'))
			} catch (error) {
				console.error('Error publishing register:', error)
				showError(t('openregister', 'Failed to publish register: {error}', { error: error.message }))
			}
		},

		async depublishRegister(register) {
			try {
				await registerStore.depublishRegister(register.id)
				showSuccess(t('openregister', 'Register depublished successfully'))
			} catch (error) {
				console.error('Error depublishing register:', error)
				showError(t('openregister', 'Failed to depublish register: {error}', { error: error.message }))
			}
		},

		viewRegisterDetails(register) {
			registerStore.setRegisterItem({ id: register.id })
			this.$router.push(`/registers/${register.id}`)
		},

		async downloadOas(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		viewOasDoc(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		openAllApisDoc() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/apps/openregister/api/registers/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		async warmupNamesCache() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/names/warmup`

			try {
				showSuccess(t('openregister', 'Starting names cache warmup...'))

				const response = await axios.post(apiUrl, {}, {
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const loadedCount = response.data.loaded_names || 0
					const executionTime = response.data.execution_time || '0ms'
					const oldCacheSize = response.data.old_cache?.distributed_name_cache_size || 0
					const newCacheSize = response.data.new_cache?.distributed_name_cache_size || 0

					let cacheMessage = ''
					if (newCacheSize > oldCacheSize) {
						cacheMessage = t('openregister', 'Cache grew from {old} to {new} entries.', {
							old: oldCacheSize,
							new: newCacheSize,
						})
					} else if (newCacheSize < oldCacheSize) {
						cacheMessage = t('openregister', 'Cache shrunk from {old} to {new} entries.', {
							old: oldCacheSize,
							new: newCacheSize,
						})
					} else {
						cacheMessage = t('openregister', 'Cache stayed the same at {size} entries.', {
							size: newCacheSize,
						})
					}

					showSuccess(t('openregister', 'Names cache warmed up successfully: {count} names loaded in {time}. {cache}', {
						count: loadedCount,
						time: executionTime,
						cache: cacheMessage,
					}))
				} else {
					showSuccess(t('openregister', 'Names cache warmup completed'))
				}
			} catch (error) {
				console.error('Error warming up names cache:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to warmup names cache: {error}', {
					error: errorMessage,
				}))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* Table row borders for managed registers (external - green) */
:deep(.viewTableRow--managed) {
	border-left: 4px solid var(--color-success);
}

/* Table row borders for local configurations (orange) */
:deep(.viewTableRow--local) {
	border-left: 4px solid var(--color-warning);
}

/* Managed by Configuration badge */
.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

/* External (managed) badge - green */
.managedBadge--external {
	background: var(--color-success);
	color: white;
}

/* Local configuration badge - orange */
.managedBadge--local {
	background: var(--color-warning);
	color: var(--color-main-background);
}
</style>
