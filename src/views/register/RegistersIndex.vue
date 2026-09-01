<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	configurationStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Registers')"
			:description="
				t(
					'openregister',
					'Manage your data registers and their configurations',
				)
			"
			:showTitle="true"
			:objects="paginatedRegisters"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="registerStore.loading"
			:viewMode="registerStore.viewMode"
			:selectable="true"
			:selectedIds="selectedRegisters"
			:schema="registerSchema"
			:showEditAction="false"
			:showCopyAction="false"
			:showDeleteAction="false"
			:showMassImport="false"
			:showMassExport="false"
			:showMassCopy="false"
			:showMassDelete="false"
			showViewToggle
			:addLabel="t('openregister', 'Add Register')"
			rowKey="id"
			:emptyText="emptyContentName"
			:rowClass="getRowClass"
			:refreshing="isRefreshing"
			@create="onSaveRegister"
			@edit="onSaveRegister"
			@refresh="handleRefresh"
			@pageChanged="onPageChanged"
			@pageSizeChanged="onPageSizeChanged"
			@viewModeChange="registerStore.setViewMode($event)"
			@select="onSelect"
			@rowClick="viewRegisterDetails">
			<!-- Custom form fields for the built-in CnFormDialog -->
			<template #form-fields="{ formData, errors, updateField }">
				<div class="formContainer">
					<NcTextField
						:label="t('openregister', 'Title') + ' *'"
						:modelValue="formData.title || ''"
						:error="!!errors.title"
						:helperText="errors.title"
						@update:modelValue="(v) => updateField('title', v)" />
					<NcTextField
						:label="t('openregister', 'Slug') + ' *'"
						:modelValue="formData.slug || ''"
						:error="!!errors.slug"
						:helperText="errors.slug"
						@update:modelValue="(v) => updateField('slug', v)" />
					<NcTextArea
						:label="t('openregister', 'Description')"
						:modelValue="formData.description || ''"
						@update:modelValue="(v) => updateField('description', v)" />
					<NcSelect
						:inputLabel="t('openregister', 'Schemas')"
						:options="schemaSelectOptions"
						:modelValue="getSchemaSelectValue(formData.schemas)"
						:multiple="true"
						:closeOnSelect="false"
						:loading="schemasLoading"
						@update:modelValue="
							(vals) => updateField('schemas', vals)
						" />
				</div>
			</template>

			<!-- Custom action items in actions bar -->
			<template #action-items>
				<NcActionButton
					closeAfterClick
					@click="
						() => {
							registerStore.setRegisterItem(null)
							navigationStore.setModal('importRegister')
						}
					">
					<template #icon>
						<Upload :size="20" />
					</template>
					{{ t('openregister', 'Import') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="openAllApisDoc">
					<template #icon>
						<ApiIcon :size="20" />
					</template>
					{{ t('openregister', 'View APIs') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="warmupNamesCache">
					<template #icon>
						<CloudUploadOutline :size="20" />
					</template>
					{{ t('openregister', 'Warmup Names Cache') }}
				</NcActionButton>
			</template>

			<!-- Custom card template -->
			<template #card="{ object }">
				<RegisterSchemaCard
					:item="object"
					type="register"
					@refresh="handleRefresh" />
			</template>

			<!-- Custom column: title with managed badge -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>
						{{ row.title }}
						<span
							v-if="isManagedByExternalConfig(row)"
							class="managedBadge managedBadge--external">
							<CogOutline :size="16" />
							{{ t('openregister', 'Managed') }}
						</span>
						<span
							v-else-if="isManagedByLocalConfig(row)"
							class="managedBadge managedBadge--local">
							<CogOutline :size="16" />
							{{ t('openregister', 'Local') }}
						</span>
					</strong>
					<span
						v-if="row.description"
						class="textDescription textEllipsis"
						>{{ row.description }}</span
					>
				</div>
			</template>

			<!-- Custom column: schemas count -->
			<template #column-schemas="{ row }">
				{{
					n(
						'openregister',
						'{count} schema',
						'{count} schemas',
						row.schemas?.length || 0,
						{ count: row.schemas?.length || 0 },
					)
				}}
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{
					row.created
						? new Date(row.created).toLocaleDateString({
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							})
							+ ', '
							+ new Date(row.created).toLocaleTimeString({
								hour: '2-digit',
								minute: '2-digit',
								second: '2-digit',
							})
						: '-'
				}}
			</template>

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{
					row.updated
						? new Date(row.updated).toLocaleDateString({
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							})
							+ ', '
							+ new Date(row.updated).toLocaleTimeString({
								hour: '2-digit',
								minute: '2-digit',
								second: '2-digit',
							})
						: '-'
				}}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						:title="
							isManagedByExternalConfig(row)
								? t(
										'openregister',
										'Cannot edit: This register is managed by external configuration {title}',
										{
											title: getManagingConfiguration(row)
												?.title,
										},
									)
								: ''
						"
						closeAfterClick
						:disabled="isManagedByExternalConfig(row)"
						@click="$refs.indexPage.openFormDialog(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								registerStore.setRegisterItem(row)
								navigationStore.setModal('publishRegister')
							}
						">
						<template #icon>
							<CloudUploadOutline :size="20" />
						</template>
						{{ t('openregister', 'Publish OAS') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								registerStore.setRegisterItem(row)
								navigationStore.setModal('importRegister')
							}
						">
						<template #icon>
							<Upload :size="20" />
						</template>
						{{ t('openregister', 'Import') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								registerStore.setRegisterItem(row)
								viewOasDoc(row)
							}
						">
						<template #icon>
							<ApiIcon :size="20" />
						</template>
						{{ t('openregister', 'View API Documentation') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								registerStore.setRegisterItem(row)
								downloadOas(row)
							}
						">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Download API Specification') }}
					</NcActionButton>
					<NcActionButton
						:title="
							row.stats?.total > 0
								? t(
										'openregister',
										'Cannot delete: objects are still attached',
									)
								: ''
						"
						closeAfterClick
						:disabled="row.stats?.total > 0"
						@click="
							() => {
								registerStore.setRegisterItem(row)
								navigationStore.setDialog('deleteRegister')
							}
						">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="viewRegisterDetails(row)">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						{{ t('openregister', 'View Details') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
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
		/**
		 * @spec exclude list-view store-reference passthrough (computed)
		 */
		registerStore() {
			return registerStore
		},

		/**
		 * @spec exclude list-view inline form-schema definition for the register editor (computed)
		 */
		registerSchema() {
			return {
				title: t('openregister', 'Register'),
				properties: {
					title: {
						type: 'string',
						title: t('openregister', 'Title'),
						required: true,
						minLength: 1,
						order: 1,
					},

					slug: {
						type: 'string',
						title: t('openregister', 'Slug'),
						required: true,
						minLength: 1,
						order: 2,
					},

					description: {
						type: 'string',
						title: t('openregister', 'Description'),
						order: 3,
					},

					schemas: {
						type: 'array',
						title: t('openregister', 'Schemas'),
						order: 4,
					},
				},

				required: ['title', 'slug'],
			}
		},

		/**
		 * @spec exclude list-view list filtering of synthetic rows (computed)
		 */
		filteredRegisters() {
			return registerStore.registerList.filter(
				(register) =>
					register.title !== 'System Totals'
					&& register.title !== 'Orphaned Items',
			)
		},

		/**
		 * @spec exclude list-view table column definitions (computed)
		 */
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'schemas', label: t('openregister', 'Schemas') },
				{
					key: 'created',
					label: t('openregister', 'Created'),
					sortable: true,
				},
				{
					key: 'updated',
					label: t('openregister', 'Updated'),
					sortable: true,
				},
			]
		},

		/**
		 * @spec exclude list-view pagination summary helper (computed)
		 */
		paginationData() {
			const page = registerStore.pagination.page || 1
			const limit = registerStore.pagination.limit || 20
			const total = this.filteredRegisters.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},

		/**
		 * The registers for the current page. The full list is loaded client-side,
		 * so CnIndexPage (prop mode) does not slice — we slice here so paging works.
		 *
		 * @spec exclude list-view derived per-page slice for display
		 */
		paginatedRegisters() {
			const { page, limit } = this.paginationData
			const start = (page - 1) * limit
			return this.filteredRegisters.slice(start, start + limit)
		},

		/**
		 * @spec exclude list-view empty-state title text helper (computed)
		 */
		emptyContentName() {
			if (registerStore.error) {
				return registerStore.error
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers found')
			}
			return t('openregister', 'Loading registers...')
		},
	},

	/**
	 * @spec exclude list-view lifecycle; parallel-loads registers/configurations/schemas on mount
	 */
	async mounted() {
		try {
			this.schemasLoading = true
			await Promise.all([
				registerStore.refreshRegisterList(),
				configurationStore.refreshConfigurationList(),
				schemaStore.refreshSchemaList(),
			])
			this.schemaSelectOptions = schemaStore.schemaList.map((s) => ({
				id: s.id,
				label: s.title,
			}))
		} catch (error) {
			console.error('Failed to load data:', error)
		} finally {
			this.schemasLoading = false
		}
	},

	methods: {
		/**
		 * @spec exclude list-view manual refresh plumbing
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await registerStore.refreshRegisterList()
			} finally {
				this.isRefreshing = false
			}
		},

		/**
		 * @param page
		 * @spec exclude list-view pagination page-change handler
		 */
		onPageChanged(page) {
			registerStore.setPagination(page, registerStore.pagination.limit)
		},

		/**
		 * @param pageSize
		 * @spec exclude list-view pagination page-size-change handler
		 */
		onPageSizeChanged(pageSize) {
			registerStore.setPagination(1, pageSize)
		},

		/**
		 * @param ids
		 * @spec exclude list-view row-selection state setter
		 */
		onSelect(ids) {
			this.selectedRegisters = ids
		},

		/**
		 * @param register
		 * @spec exclude list-view row CSS-class helper based on managing-configuration state
		 */
		getRowClass(register) {
			if (this.isManagedByExternalConfig(register))
				return 'viewTableRow--managed'
			if (this.isManagedByLocalConfig(register)) return 'viewTableRow--local'
			return ''
		},

		/**
		 * @param register
		 * @spec exclude list-view lookup helper; finds the configuration managing a register
		 */
		getManagingConfiguration(register) {
			if (!register || !register.id) return null
			return (
				configurationStore.configurationList.find(
					(config) =>
						config.registers && config.registers.includes(register.id),
				) || null
			)
		},

		/**
		 * @param register
		 * @spec exclude list-view display predicate; whether a register is externally managed
		 */
		isManagedByExternalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false
			return (
				(config.sourceType
					&& ['github', 'gitlab', 'url'].includes(config.sourceType))
				|| config.isLocal === false
			)
		},

		/**
		 * @param register
		 * @spec exclude list-view display predicate; whether a register is locally managed
		 */
		isManagedByLocalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false
			return (
				config.sourceType === 'local'
				|| config.sourceType === 'manual'
				|| config.isLocal === true
			)
		},

		/**
		 * @param schemas
		 * @spec exclude list-view form-control mapping helper; resolves schema ids to select options
		 */
		getSchemaSelectValue(schemas) {
			if (!Array.isArray(schemas)) return []
			return schemas.map((s) => {
				const id = typeof s === 'object' ? s.id : s
				return (
					this.schemaSelectOptions.find(
						(o) => String(o.id) === String(id),
					) || { id, label: String(id) }
				)
			})
		},

		/**
		 * @param formData
		 * @spec exclude list-view form-submit wiring; delegates to registerStore.saveRegister (registers-management contract)
		 */
		async onSaveRegister(formData) {
			try {
				await registerStore.saveRegister({
					...formData,
					schemas: (formData.schemas || []).map((s) =>
						typeof s === 'object' ? s.id : s,
					),
				})
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message })
			}
		},

		/**
		 * @param register
		 * @spec exclude list-view row-action; router-navigates to the register detail page
		 */
		viewRegisterDetails(register) {
			registerStore.setRegisterItem({ id: register.id })
			this.$router.push(`/registers/${register.id}`)
		},

		/**
		 * @param register
		 * @spec exclude list-view row-action; fetches and downloads the register OAS as JSON (oas-validation contract)
		 */
		async downloadOas(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], {
					type: 'application/json',
				})
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

		/**
		 * @param register
		 * @spec exclude list-view row-action; opens the register OAS in the Redoc viewer (oas-validation contract)
		 */
		viewOasDoc(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			window.open(
				`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`,
				'_blank',
			)
		},

		/**
		 * @spec exclude list-view action; opens the combined all-registers OAS in the Redoc viewer (oas-validation contract)
		 */
		openAllApisDoc() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/apps/openregister/api/registers/oas`
			window.open(
				`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`,
				'_blank',
			)
		},

		/**
		 * @spec exclude list-view action; POSTs to the names-cache warmup endpoint and reports results via toast
		 */
		async warmupNamesCache() {
			const baseUrl = window.location.origin
			// Was `/api/names/warmup`, which was #[PublicPage] — anyone could make
			// the server rebuild the entire name cache. That route is gone
			// (SEC-CTRL-2); this is the admin-only equivalent, which is what a
			// maintenance action on an admin screen should have been calling.
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/settings/cache/warmup-names`

			try {
				showSuccess(t('openregister', 'Starting names cache warmup...'))

				const response = await axios.post(
					apiUrl,
					{},
					{
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
						},
					},
				)

				if (response.data && response.data.success) {
					const loadedCount = response.data.loaded_names || 0
					const executionTime = response.data.execution_time || '0ms'
					const oldCacheSize =
						response.data.old_cache?.distributed_name_cache_size || 0
					const newCacheSize =
						response.data.new_cache?.distributed_name_cache_size || 0

					let cacheMessage = ''
					if (newCacheSize > oldCacheSize) {
						cacheMessage = t(
							'openregister',
							'Cache grew from {old} to {new} entries.',
							{
								old: oldCacheSize,
								new: newCacheSize,
							},
						)
					} else if (newCacheSize < oldCacheSize) {
						cacheMessage = t(
							'openregister',
							'Cache shrunk from {old} to {new} entries.',
							{
								old: oldCacheSize,
								new: newCacheSize,
							},
						)
					} else {
						cacheMessage = t(
							'openregister',
							'Cache stayed the same at {size} entries.',
							{
								size: newCacheSize,
							},
						)
					}

					showSuccess(
						t(
							'openregister',
							'Names cache warmed up successfully: {count} names loaded in {time}. {cache}',
							{
								count: loadedCount,
								time: executionTime,
								cache: cacheMessage,
							},
						),
					)
				} else {
					showSuccess(t('openregister', 'Names cache warmup completed'))
				}
			} catch (error) {
				console.error('Error warming up names cache:', error)
				const errorMessage =
					error.response?.data?.message || error.message || 'Unknown error'
				showError(
					t('openregister', 'Failed to warmup names cache: {error}', {
						error: errorMessage,
					}),
				)
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* Table row accents. Drawn with an inset box-shadow, never border-left: a
   border adds layout width and shifts the row's cell content sideways, while
   box-shadow paints inside the box. Matches .cn-table-row--selected.

   Skipped on a selected row so the library's .cn-table-row--selected accent
   wins. Scoping adds a [data-v-*] attribute, which would otherwise outweigh
   the library's single-class rule and leave a selected row showing its
   managed/local colour instead of the selection colour. */

/* Managed registers (external - green) */
:deep(.viewTableRow--managed:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

/* Local configurations (orange) */
:deep(.viewTableRow--local:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
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
	color: var(--color-main-text);
}
</style>
