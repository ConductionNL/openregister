<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { listDateTime } from '../../services/listDateTime.js'
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
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:activeFilters="activeFilters"
			:pagination="paginationData"
			:loading="registerStore.loading"
			:viewMode="registerStore.viewMode"
			:selectable="true"
			:selectedIds="selectedRegisters"
			:schema="registerSchema"
			v-bind="builtinActionsOff"
			showViewToggle
			:addLabel="t('openregister', 'Add Register')"
			rowKey="id"
			:emptyText="emptyContentName"
			:rowClass="getRowClass"
			:refreshing="isRefreshing"
			@create="onSaveRegister"
			@edit="onSaveRegister"
			@sort="onSort"
			@filterChange="onFilterChange"
			@refresh="handleRefresh"
			@pageChanged="onPageChanged"
			@pageSizeChanged="onPageSizeChanged"
			@viewModeChange="registerStore.setViewMode($event)"
			@select="onSelect"
			@rowClick="viewRegisterDetails">
			<!-- Custom form fields for the built-in CnFormDialog -->
			<template #form-fields="form">
				<RegisterFormFields
					v-bind="form"
					:schemaOptions="schemaSelectOptions"
					:schemasLoading="schemasLoading" />
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
						<ManagedBadge :config="getManagingConfiguration(row)" />
					</strong>
					<IndexRowDescription :text="row.description" />
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

			<!-- Custom columns: created and updated dates -->
			<template
				v-for="key in ['created', 'updated']"
				#[`column-${key}`]="{ row }">
				{{ listDateTime(row[key]) }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<ActionMenu :actions="registerActions(row)" />
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionButton, NcAppContent } from '@nextcloud/vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import RegisterSchemaCard from '../../components/cards/RegisterSchemaCard.vue'
import RegisterFormFields from '../../components/register/RegisterFormFields.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import ManagedBadge from '../../components/shared/ManagedBadge.vue'
import IndexRowDescription from '../IndexRowDescription.vue'
import {
	headerListData,
	headerListMethods,
} from '../../composables/headerListControls.js'
import {
	isExternalConfiguration,
	isLocalConfiguration,
	managedRowClass,
} from '../../composables/managedConfiguration.js'
import { filterRows } from '../../services/listFilter.js'
import { sortSchemas } from '../../services/listSort.js'
import { deleteAction, editAction } from '../../services/menuActions.js'
import {
	registerFormSchema,
	registerPayload,
	schemaOptionsOf,
} from '../../services/registerForm.js'
import { timestampColumns } from '../indexPageOptions.js'

/**
 * How the names cache changed in size, in words.
 *
 * @param {number} oldSize Entries before the warmup
 * @param {number} newSize Entries after the warmup
 * @return {string} The translated sentence
 */
function cacheChangeText(oldSize, newSize) {
	if (newSize > oldSize) {
		return t('openregister', 'Cache grew from {old} to {new} entries.', {
			old: oldSize,
			new: newSize,
		})
	}
	if (newSize < oldSize) {
		return t('openregister', 'Cache shrunk from {old} to {new} entries.', {
			old: oldSize,
			new: newSize,
		})
	}
	return t('openregister', 'Cache stayed the same at {size} entries.', {
		size: newSize,
	})
}

/**
 * The toast for a successful warmup, from the endpoint's answer.
 *
 * @param {object} data The warmup response body
 * @return {string} The translated message
 */
function warmupSuccessText(data) {
	const oldSize = data.old_cache?.distributed_name_cache_size || 0
	const newSize = data.new_cache?.distributed_name_cache_size || 0
	return t(
		'openregister',
		'Names cache warmed up successfully: {count} names loaded in {time}. {cache}',
		{
			count: data.loaded_names || 0,
			time: data.execution_time || '0ms',
			cache: cacheChangeText(oldSize, newSize),
		},
	)
}

/**
 * The reason a request failed: the server's message, else the error's own.
 *
 * @param {object} error The axios error
 * @return {string} The reason
 */
function failureText(error) {
	return error.response?.data?.message || error.message || 'Unknown error'
}

/**
 * Whether objects are still attached to a register.
 *
 * @param {object} register The register row
 * @return {boolean} True while it holds objects
 */
function hasAttachedObjects(register) {
	return register.stats?.total > 0
}

/**
 * Why the delete entry is locked: '' unless objects are still attached.
 *
 * @param {object} register The register row
 * @return {string} The reason
 */
function deleteLockReason(register) {
	if (!hasAttachedObjects(register)) {
		return ''
	}
	return t('openregister', 'Cannot delete: objects are still attached')
}

export default {
	name: 'RegistersIndex',
	components: {
		IndexRowDescription,
		ActionMenu,
		NcActionButton,
		Upload,
		ApiIcon,
		CloudUploadOutline,
		RegisterFormFields,
		NcAppContent,
		CnIndexPage,
		ManagedBadge,
		RegisterSchemaCard,
	},

	data() {
		return {
			...headerListData(),
			selectedRegisters: [],
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
			return registerFormSchema({
				// Read-only, so the form leaves them out; they tell the
				// Created and Updated header filters to filter by date.
				created: {
					type: 'string',
					format: 'date-time',
					title: t('openregister', 'Created'),
					readOnly: true,
				},

				updated: {
					type: 'string',
					format: 'date-time',
					title: t('openregister', 'Updated'),
					readOnly: true,
				},
			})
		},

		/**
		 * The registers to list: synthetic rows dropped, header filters
		 * applied, then sorted by the clicked header when there is one.
		 *
		 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
		 */
		filteredRegisters() {
			const registers = filterRows(
				registerStore.registerList.filter(
					(register) =>
						register.title !== 'System Totals'
						&& register.title !== 'Orphaned Items',
				),
				this.activeFilters,
			)
			return this.sortKey
				? sortSchemas(registers, this.sortKey, this.sortOrder)
				: registers
		},

		/**
		 * @spec exclude list-view table column definitions (computed)
		 */
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'schemas', label: t('openregister', 'Schemas') },
				...timestampColumns(),
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
			this.schemaSelectOptions = schemaOptionsOf(schemaStore.schemaList)
		} catch (error) {
			console.error('Failed to load data:', error)
		} finally {
			this.schemasLoading = false
		}
	},

	methods: {
		...headerListMethods(() => registerStore),

		/**
		 * The menu of one register.
		 *
		 * @spec exclude UI plumbing — menu entries; each delegates to the stores.
		 * @param {object} register - list row
		 * @return {Array<object>}
		 */
		registerActions(register) {
			const selected = (action) => () => {
				registerStore.setRegisterItem(register)
				action()
			}
			return [
				this.lockedEdit(register),
				...this.publishActions(selected),
				...this.apiActions(register, selected),
				this.lockedDelete(register, selected),
				this.detailsAction(register),
			]
		},

		/**
		 * The publish and import entries.
		 *
		 * @spec exclude UI plumbing — menu entries; each delegates to the stores.
		 * @param {(action: () => void) => () => void} selected - selects the register first
		 * @return {Array<object>}
		 */
		publishActions(selected) {
			return [
				{
					label: t('openregister', 'Publish OAS'),
					icon: CloudUploadOutline,
					run: selected(() => navigationStore.setModal('publishRegister')),
				},
				{
					label: t('openregister', 'Import'),
					icon: Upload,
					run: selected(() => navigationStore.setModal('importRegister')),
				},
			]
		},

		/**
		 * The API documentation and specification entries.
		 *
		 * @spec exclude UI plumbing — menu entries; each delegates to the page.
		 * @param {object} register - register row
		 * @param {(action: () => void) => () => void} selected - selects the register first
		 * @return {Array<object>}
		 */
		apiActions(register, selected) {
			return [
				{
					label: t('openregister', 'View API Documentation'),
					icon: ApiIcon,
					run: selected(() => this.viewOasDoc(register)),
				},
				{
					label: t('openregister', 'Download API Specification'),
					icon: Download,
					run: selected(() => this.downloadOas(register)),
				},
			]
		},

		/**
		 * The entry that opens the register detail page.
		 *
		 * @spec exclude UI plumbing — menu entry.
		 * @param {object} register - register row
		 * @return {object}
		 */
		detailsAction(register) {
			return {
				label: t('openregister', 'View Details'),
				icon: InformationOutline,
				run: () => this.viewRegisterDetails(register),
			}
		},

		/**
		 * The edit entry, locked while an external configuration manages the register.
		 *
		 * @spec exclude UI plumbing — menu entry state.
		 * @param {object} register - register row
		 * @return {object}
		 */
		lockedEdit(register) {
			const openForm = () => this.$refs.indexPage.openFormDialog(register)
			return editAction(openForm, {
				title: this.editLockReason(register),
				disabled: this.isManagedByExternalConfig(register),
			})
		},

		/**
		 * Why the edit entry is locked: '' unless an external configuration
		 * manages the register.
		 *
		 * @spec exclude UI plumbing — menu entry state.
		 * @param {object} register - register row
		 * @return {string}
		 */
		editLockReason(register) {
			if (!this.isManagedByExternalConfig(register)) {
				return ''
			}
			const title = this.getManagingConfiguration(register)?.title
			return t(
				'openregister',
				'Cannot edit: This register is managed by external configuration {title}',
				{ title },
			)
		},

		/**
		 * The delete entry, locked while objects are attached.
		 *
		 * @spec exclude UI plumbing — menu entry state.
		 * @param {object} register - register row
		 * @param {(action: () => void) => () => void} selected - selects the register first
		 * @return {object}
		 */
		lockedDelete(register, selected) {
			const run = selected(() => navigationStore.setDialog('deleteRegister'))
			return deleteAction(run, {
				title: deleteLockReason(register),
				disabled: hasAttachedObjects(register),
			})
		},

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
		 * @param {number} page
		 * @spec exclude list-view pagination page-change handler
		 */
		onPageChanged(page) {
			registerStore.setPagination(page, registerStore.pagination.limit)
		},

		/**
		 * @param {number} pageSize
		 * @spec exclude list-view pagination page-size-change handler
		 */
		onPageSizeChanged(pageSize) {
			registerStore.setPagination(1, pageSize)
		},

		/**
		 * @param {Array<string|number>} ids
		 * @spec exclude list-view row-selection state setter
		 */
		onSelect(ids) {
			this.selectedRegisters = ids
		},

		/**
		 * @param {object} register
		 * @spec exclude list-view row CSS-class helper based on managing-configuration state
		 */
		getRowClass(register) {
			return managedRowClass(this.getManagingConfiguration(register))
		},

		/**
		 * @param {object} register
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
		 * @param {object} register
		 * @spec exclude list-view display predicate; whether a register is externally managed
		 */
		isManagedByExternalConfig(register) {
			return isExternalConfiguration(this.getManagingConfiguration(register))
		},

		/**
		 * @param {object} register
		 * @spec exclude list-view display predicate; whether a register is locally managed
		 */
		isManagedByLocalConfig(register) {
			return isLocalConfiguration(this.getManagingConfiguration(register))
		},

		/**
		 * @param {object} formData
		 * @spec exclude list-view form-submit wiring; delegates to registerStore.saveRegister (registers-management contract)
		 */
		async onSaveRegister(formData) {
			try {
				await registerStore.saveRegister(registerPayload(formData))
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message })
			}
		},

		/**
		 * @param {object} register
		 * @spec exclude list-view row-action; router-navigates to the register detail page
		 */
		viewRegisterDetails(register) {
			registerStore.setRegisterItem({ id: register.id })
			this.$router.push(`/registers/${register.id}`)
		},

		/**
		 * @param {object} register
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
		 * @param {object} register
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
					showSuccess(warmupSuccessText(response.data))
				} else {
					showSuccess(t('openregister', 'Names cache warmup completed'))
				}
			} catch (error) {
				console.error('Error warming up names cache:', error)
				showError(
					t('openregister', 'Failed to warmup names cache: {error}', {
						error: failureText(error),
					}),
				)
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../managedRowAccents.scss' as *;

@include managed-row-accents;
</style>
