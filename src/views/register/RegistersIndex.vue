<script setup>
import { dashboardStore, registerStore, navigationStore, configurationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Registers') }}
				</h1>
				<p>{{ t('openregister', 'Manage your data registers and their configurations') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} registers', { showing: filteredRegisters.length, total: registerStore.registerList.length }) }}
					</span>
					<span v-if="selectedRegisters.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedRegisters.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="registerStore.viewMode"
							v-tooltip="'See registers as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="registerStore.viewMode"
							v-tooltip="'See registers as a table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="2"
						:class="{ 'sidebar-closed': !navigationStore.sidebarState.registers }"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Register
						</NcActionButton>
						<NcActionButton close-after-click @click="registerStore.refreshRegisterList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
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
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="registerStore.loading || registerStore.error || !filteredRegisters.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="registerStore.loading" :size="64" />
					<DatabaseOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="registerStore.viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="register in paginatedRegisters"
							:key="register.id"
							class="card"
							:class="{
								'card--managed': isManagedByExternalConfig(register),
								'card--local': isManagedByLocalConfig(register)
							}">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="register.description">
									<DatabaseOutline :size="20" />
									{{ register.title }}
									<span v-if="isManagedByExternalConfig(register)" class="managedBadge managedBadge--external">
										<CogOutline :size="16" />
										{{ t('openregister', 'Managed') }}
									</span>
									<span v-else-if="isManagedByLocalConfig(register)" class="managedBadge managedBadge--local">
										<CogOutline :size="16" />
										{{ t('openregister', 'Local') }}
									</span>
							</h2>
							<NcActions :primary="true" menu-name="Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton
									v-tooltip="isManagedByExternalConfig(register) ? 'Cannot edit: This register is managed by external configuration ' + getManagingConfiguration(register).title : ''"
									close-after-click
									:disabled="isManagedByExternalConfig(register)"
									@click="registerStore.setRegisterItem({
										...register,
										schemas: Array.isArray(register.schemas)
											? register.schemas.map(schema => typeof schema === 'object' ? schema.id : schema)
											: []
									}); navigationStore.setModal('editRegister')">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit
								</NcActionButton>
									<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('exportRegister')">
										<template #icon>
											<Export :size="20" />
										</template>
										Export
									</NcActionButton>
									<NcActionButton
										v-if="!register.published || (register.depublished && new Date(register.depublished) <= new Date())"
										close-after-click
										@click="publishRegister(register)">
										<template #icon>
											<Publish :size="20" />
										</template>
										Publish
									</NcActionButton>
									<NcActionButton
										v-if="register.published && (!register.depublished || new Date(register.depublished) > new Date())"
										close-after-click
										@click="depublishRegister(register)">
										<template #icon>
											<PublishOff :size="20" />
										</template>
										Depublish
									</NcActionButton>
									<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('publishRegister')">
										<template #icon>
											<CloudUploadOutline :size="20" />
										</template>
										Publish OAS
									</NcActionButton>
									<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('importRegister')">
										<template #icon>
											<Upload :size="20" />
										</template>
										Import
									</NcActionButton>
									<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); viewOasDoc(register)">
										<template #icon>
											<ApiIcon :size="20" />
										</template>
										View API Documentation
									</NcActionButton>
									<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); downloadOas(register)">
										<template #icon>
											<Download :size="20" />
										</template>
										Download API Specification
									</NcActionButton>
									<NcActionButton v-tooltip="register.stats?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
										close-after-click
										:disabled="register.stats?.total > 0"
										@click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
									<NcActionButton close-after-click @click="viewRegisterDetails(register)">
										<template #icon>
											<InformationOutline :size="20" />
										</template>
										View Details
									</NcActionButton>
								</NcActions>
							</div>

							<!-- Register Description -->
							<div class="registerDescription"
								:class="{ 'registerDescription--expanded': isDescriptionExpanded(register.id), 'registerDescription--empty': !register.description }"
								@click="register.description ? toggleDescriptionExpanded(register.id) : null">
								{{ register.description || t('openregister', 'No description found') }}
							</div>

							<!-- Schemas section -->
							<table class="statisticsTable registerSchemas">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Schema Name') }}</th>
										<th>{{ t('openregister', 'Objects') }}</th>
										<th>{{ t('openregister', 'Configuration') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="schema in getDisplayedSchemas(register)" :key="schema.id">
										<td class="schemaNameCell">
											<Table v-if="hasMagicMapping(schema)" v-tooltip="'Magic Table'" :size="18" class="schemaIcon schemaIcon--magic" />
											<DatabaseOutline v-else v-tooltip="'Blob Storage'" :size="18" class="schemaIcon schemaIcon--blob" />
											{{ schema.title }}
										</td>
										<td>{{ schema.stats?.objects?.total || 0 }}</td>
										<td class="tableColumnActions">
											<NcActions :primary="false">
												<template #icon>
													<DotsHorizontal :size="20" />
												</template>
												<NcActionButton close-after-click @click="setSchemaConfiguration(register, schema, 'magic')">
													<template #icon>
														<Table :size="20" />
													</template>
													{{ hasMagicMapping(schema) ? '✓ ' : '' }}Use Magic Table
												</NcActionButton>
												<NcActionButton close-after-click @click="setSchemaConfiguration(register, schema, 'blob')">
													<template #icon>
														<DatabaseOutline :size="20" />
													</template>
													{{ !hasMagicMapping(schema) ? '✓ ' : '' }}Use Blob Storage
												</NcActionButton>
												<NcActionButton 
													v-tooltip="!hasMagicMapping(schema) ? t('openregister', 'This schema must use Magic Table configuration to sync') : ''"
													:disabled="!hasMagicMapping(schema)"
													close-after-click 
													@click="syncMagicTable(register, schema)">
													<template #icon>
														<Sync :size="20" />
													</template>
													{{ t('openregister', 'Sync Table') }}
												</NcActionButton>
												<NcActionButton 
													close-after-click 
													@click="validateSchemaObjects(register, schema)">
													<template #icon>
														<CheckCircle :size="20" />
													</template>
													{{ t('openregister', 'Validate') }}
												</NcActionButton>
												<NcActionButton 
													v-tooltip="getSchemaObjectCount(schema) > 0 ? t('openregister', 'Cannot remove schema with existing objects ({count} objects)', { count: getSchemaObjectCount(schema) }) : ''"
													:disabled="getSchemaObjectCount(schema) > 0"
													close-after-click 
													@click="removeSchemaFromRegister(register, schema)">
													<template #icon>
														<TrashCanOutline :size="20" />
													</template>
													{{ t('openregister', 'Remove') }}
												</NcActionButton>
											</NcActions>
										</td>
									</tr>
									<tr v-if="!register.schemas || register.schemas.length === 0">
										<td colspan="3" class="emptyText">
											{{ t('openregister', 'No schemas found') }}
										</td>
									</tr>
								</tbody>
							</table>

							<!-- View More Button -->
							<div v-if="getRemainingSchemasCount(register) > 0" class="viewMoreContainer">
								<NcButton
									type="secondary"
									@click="toggleRegisterExpanded(register.id)">
									<template #icon>
										<ChevronDown v-if="!isRegisterExpanded(register.id)" :size="20" />
										<ChevronUp v-else :size="20" />
									</template>
									{{ isRegisterExpanded(register.id)
										? t('openregister', 'Show less')
										: t('openregister', 'View {count} more', { count: getRemainingSchemasCount(register) })
									}}
								</NcButton>
							</div>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('openregister', 'Title') }}</th>
									<th>{{ t('openregister', 'Schemas') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th>{{ t('openregister', 'Updated') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="register in paginatedRegisters"
									:key="register.id"
									class="viewTableRow"
									:class="{
										viewTableRowSelected: selectedRegisters.includes(register.id),
										'viewTableRow--managed': isManagedByExternalConfig(register),
										'viewTableRow--local': isManagedByLocalConfig(register)
									}">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedRegisters.includes(register.id)"
											@update:checked="(checked) => toggleRegisterSelection(register.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>
												{{ register.title }}
												<span v-if="isManagedByExternalConfig(register)" class="managedBadge managedBadge--external">
													<CogOutline :size="16" />
													{{ t('openregister', 'Managed') }}
												</span>
												<span v-else-if="isManagedByLocalConfig(register)" class="managedBadge managedBadge--local">
													<CogOutline :size="16" />
													{{ t('openregister', 'Local') }}
												</span>
											</strong>
											<span v-if="register.description" class="textDescription textEllipsis">{{ register.description }}</span>
										</div>
									</td>
									<td class="tableColumnConstrained">
										{{ register.schemas?.length || 0 }} {{ t('openregister', 'schema{plural}', {
											plural: register.schemas?.length !== 1 ? 's' : ''
										}) }}
									</td>
									<td>{{ register.created ? new Date(register.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(register.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td>{{ register.updated ? new Date(register.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(register.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
								<td class="tableColumnActions">
									<NcActions :primary="false">
										<template #icon>
											<DotsHorizontal :size="20" />
										</template>
										<NcActionButton
											v-tooltip="isManagedByExternalConfig(register) ? 'Cannot edit: This register is managed by external configuration ' + getManagingConfiguration(register).title : ''"
											close-after-click
											:disabled="isManagedByExternalConfig(register)"
											@click="registerStore.setRegisterItem({
												...register,
												schemas: Array.isArray(register.schemas)
													? register.schemas.map(schema => typeof schema === 'object' ? schema.id : schema)
													: []
											}); navigationStore.setModal('editRegister')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
											<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('exportRegister')">
												<template #icon>
													<Export :size="20" />
												</template>
												Export
											</NcActionButton>
											<NcActionButton
												v-if="!register.published || (register.depublished && new Date(register.depublished) <= new Date())"
												close-after-click
												@click="publishRegister(register)">
												<template #icon>
													<Publish :size="20" />
												</template>
												Publish
											</NcActionButton>
											<NcActionButton
												v-if="register.published && (!register.depublished || new Date(register.depublished) > new Date())"
												close-after-click
												@click="depublishRegister(register)">
												<template #icon>
													<PublishOff :size="20" />
												</template>
												Depublish
											</NcActionButton>
											<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('publishRegister')">
												<template #icon>
													<CloudUploadOutline :size="20" />
												</template>
												Publish OAS
											</NcActionButton>
											<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); navigationStore.setModal('importRegister')">
												<template #icon>
													<Upload :size="20" />
												</template>
												Import
											</NcActionButton>
											<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); viewOasDoc(register)">
												<template #icon>
													<ApiIcon :size="20" />
												</template>
												View API Documentation
											</NcActionButton>
											<NcActionButton close-after-click @click="registerStore.setRegisterItem(register); downloadOas(register)">
												<template #icon>
													<Download :size="20" />
												</template>
												Download API Specification
											</NcActionButton>
											<NcActionButton v-tooltip="register.stats?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
												close-after-click
												:disabled="register.stats?.total > 0"
												@click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
											</NcActionButton>
											<NcActionButton close-after-click @click="viewRegisterDetails(register)">
												<template #icon>
													<InformationOutline :size="20" />
												</template>
												View Details
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="filteredRegisters.length > 0"
				:current-page="registerStore.pagination.page || 1"
				:total-pages="Math.ceil(filteredRegisters.length / (registerStore.pagination.limit || 20))"
				:total-items="filteredRegisters.length"
				:current-page-size="registerStore.pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Export from 'vue-material-design-icons/Export.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Table from 'vue-material-design-icons/Table.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'RegistersIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		NcButton,
		DatabaseOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Upload,
		Export,
		ApiIcon,
		Download,
		Refresh,
		Plus,
		InformationOutline,
		ChevronDown,
		ChevronUp,
		CogOutline,
		CloudUploadOutline,
		Publish,
		PublishOff,
		Sync,
		Table,
		CheckCircle,
		PaginationComponent,
	},
	data() {
		return {
			selectedRegisters: [],
			expandedRegisters: [], // Track which registers are expanded
			expandedDescriptions: [], // Track which descriptions are expanded
		}
	},
	computed: {
		registerStore() {
			return registerStore
		},
		filteredRegisters() {
			return registerStore.registerList.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		paginatedRegisters() {
			const start = ((registerStore.pagination.page || 1) - 1) * (registerStore.pagination.limit || 20)
			const end = start + (registerStore.pagination.limit || 20)
			return this.filteredRegisters.slice(start, end)
		},

		allSelected() {
			return this.filteredRegisters.length > 0 && this.filteredRegisters.every(register => this.selectedRegisters.includes(register.id))
		},
		someSelected() {
			return this.selectedRegisters.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (registerStore.error) {
				return registerStore.error
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers found')
			} else {
				return t('openregister', 'Loading registers...')
			}
		},
		emptyContentDescription() {
			if (registerStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers are available.')
			} else {
				return t('openregister', 'Please wait while we fetch your registers.')
			}
		},
	},
	async mounted() {
		try {
			// Load registers and configurations in parallel
			await Promise.all([
				registerStore.refreshRegisterList(),
				configurationStore.refreshConfigurationList(),
			])
		} catch (error) {
			console.error('Failed to load data:', error)
		}
	},
	methods: {
		onPageChanged(page) {
			registerStore.setPagination(page, registerStore.pagination.limit)
		},
		onPageSizeChanged(pageSize) {
			registerStore.setPagination(1, pageSize)
		},

		/**
		 * Check if a register is expanded
		 *
		 * @param {number} registerId - Register ID
		 * @return {boolean} True if register is expanded
		 */
		isRegisterExpanded(registerId) {
			return this.expandedRegisters.includes(registerId)
		},

		/**
		 * Toggle register expanded state
		 *
		 * @param {number} registerId - Register ID
		 * @return {void}
		 */
		toggleRegisterExpanded(registerId) {
			const index = this.expandedRegisters.indexOf(registerId)
			if (index > -1) {
				this.expandedRegisters.splice(index, 1)
			} else {
				this.expandedRegisters.push(registerId)
			}
		},

		/**
		 * Check if a description is expanded
		 *
		 * @param {number} registerId - Register ID
		 * @return {boolean} True if description is expanded
		 */
		isDescriptionExpanded(registerId) {
			return this.expandedDescriptions.includes(registerId)
		},

		/**
		 * Toggle description expanded state
		 *
		 * @param {number} registerId - Register ID
		 * @return {void}
		 */
		toggleDescriptionExpanded(registerId) {
			const index = this.expandedDescriptions.indexOf(registerId)
			if (index > -1) {
				this.expandedDescriptions.splice(index, 1)
			} else {
				this.expandedDescriptions.push(registerId)
			}
		},

		/**
		 * Get displayed schemas for a register (first 5 or all if expanded)
		 *
		 * @param {object} register - Register object
		 * @return {Array} Schemas to display
		 */
		getDisplayedSchemas(register) {
			if (!register.schemas || register.schemas.length === 0) {
				return []
			}

			if (this.isRegisterExpanded(register.id)) {
				return register.schemas
			}

			// Show only first 5 schemas
			return register.schemas.slice(0, 5)
		},

		/**
		 * Get count of remaining schemas not displayed
		 *
		 * @param {object} register - Register object
		 * @return {number} Count of remaining schemas
		 */
		getRemainingSchemasCount(register) {
			const total = register.schemas?.length || 0
			return Math.max(0, total - 5)
		},

		/**
		 * Get the configuration that manages this register
		 *
		 * @param {object} register - Register object
		 * @return {object|null} Configuration object or null if not managed
		 */
		getManagingConfiguration(register) {
			if (!register || !register.id) return null

			return configurationStore.configurationList.find(
				config => config.registers && config.registers.includes(register.id),
			) || null
		},
		/**
		 * Check if register is managed by an external (imported) configuration
		 * External configurations are locked and cannot be edited
		 *
		 * @param {object} register - Register object
		 * @return {boolean} True if managed by external configuration
		 */
		isManagedByExternalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false

			// External configurations: github, gitlab, url sources, or isLocal === false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},
		/**
		 * Check if register is managed by a local configuration
		 * Local configurations are editable
		 *
		 * @param {object} register - Register object
		 * @return {boolean} True if managed by local configuration
		 */
		isManagedByLocalConfig(register) {
			const config = this.getManagingConfiguration(register)
			if (!config) return false

			// Local configurations: sourceType === 'local' or 'manual', or isLocal === true
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},

		async calculateSizes(register) {
			// Set the active register in the store
			registerStore.setRegisterItem(register)

			// Set the calculating state for this register
			this.calculating = register.id
			try {
				// Call the dashboard store to calculate sizes
				await dashboardStore.calculateSizes(register.id)
				// Refresh the registers list to get updated sizes
				await registerStore.refreshRegisterList()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
			} finally {
				this.calculating = null
			}
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

		/**
		 * Warmup the names cache
		 *
		 * @return {Promise<void>}
		 */
		async warmupNamesCache() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/names/warmup`

			try {
				showSuccess(t('openregister', 'Starting names cache warmup...'))
				
				const response = await axios.post(apiUrl, {}, {
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const loadedCount = response.data.loaded_names || 0
					const executionTime = response.data.execution_time || '0ms'
					
					showSuccess(t('openregister', 'Names cache warmed up successfully: {count} names loaded in {time}', {
						count: loadedCount,
						time: executionTime
					}))
					
					console.log('Names cache warmup completed:', response.data)
				} else {
					showSuccess(t('openregister', 'Names cache warmup completed'))
				}
			} catch (error) {
				console.error('Error warming up names cache:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to warmup names cache: {error}', { 
					error: errorMessage 
				}))
			}
		},

		viewRegisterDetails(register) {
			// Set the register ID in the register store for reference
			registerStore.setRegisterItem({ id: register.id })
			// Navigate to detail view which will use dashboard store data
			this.$router.push(`/registers/${register.id}`)
		},

		toggleSelectAll(checked) {
			if (checked) {
				this.selectedRegisters = this.filteredRegisters.map(register => register.id)
			} else {
				this.selectedRegisters = []
			}
		},

		toggleRegisterSelection(registerId, checked) {
			if (checked) {
				this.selectedRegisters.push(registerId)
			} else {
				this.selectedRegisters = this.selectedRegisters.filter(id => id !== registerId)
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

		/**
		 * Check if a schema has magic mapping configuration
		 *
		 * @param {object} schema - Schema object
		 * @return {boolean} True if schema has magic mapping
		 */
		hasMagicMapping(schema) {
			if (!schema || !schema.properties) {
				return false
			}

			// Check if any property has a table configuration (magic mapping)
			return Object.values(schema.properties).some(property => 
				property && property.table && typeof property.table === 'object'
			)
		},

		/**
		 * Sync magic table for a schema
		 * Calls the /api/tables/sync/{register}/{schema} endpoint
		 *
		 * @param {object} register - Register object
		 * @param {object} schema - Schema object
		 * @return {Promise<void>}
		 */
		async syncMagicTable(register, schema) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/tables/sync/${register.id}/${schema.id}`

			try {
				showSuccess(t('openregister', 'Syncing magic table for {schema}...', { schema: schema.title }))
				
				const response = await axios.post(apiUrl, {}, {
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json',
					},
				})

				if (response.data && response.data.success) {
					const stats = response.data.statistics
					
					// Build detailed message
					const details = []
					
					// Metadata and properties summary
					if (stats.metadata && stats.properties) {
						details.push(`${stats.metadata.count} metadata columns, ${stats.properties.count} property columns`)
					}
					
					// Column changes
					if (stats.columns) {
						const changes = []
						if (stats.columns.added.count > 0) {
							changes.push(`${stats.columns.added.count} added`)
						}
						if (stats.columns.removed.count > 0) {
							changes.push(`${stats.columns.removed.count} removed`)
						}
						if (stats.columns.deRequired.count > 0) {
							changes.push(`${stats.columns.deRequired.count} de-required`)
						}
						if (stats.columns.unchanged.count > 0) {
							changes.push(`${stats.columns.unchanged.count} unchanged`)
						}
						
						if (changes.length > 0) {
							details.push(`Columns: ${changes.join(', ')}`)
						}
					}
					
					const message = details.length > 0 
						? `Magic table synced: ${details.join(' • ')}`
						: `Magic table synced successfully for ${schema.title}`
					
					showSuccess(t('openregister', message))
					
					// Log detailed stats to console for debugging
					console.log('Magic table sync statistics:', stats)
				} else {
					showSuccess(t('openregister', 'Magic table sync completed for {schema}', { schema: schema.title }))
				}
			} catch (error) {
				console.error('Error syncing magic table:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to sync magic table for {schema}: {error}', { 
					schema: schema.title, 
					error: errorMessage 
				}))
			}
		},

		/**
		 * Set schema configuration (magic table or blob storage)
		 *
		 * @param {object} register - Register object
		 * @param {object} schema - Schema object
		 * @param {string} configurationType - 'magic' or 'blob'
		 * @return {Promise<void>}
		 */
		async setSchemaConfiguration(register, schema, configurationType) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/schemas/${schema.id}`

			try {
				// First, fetch the current schema from the API to get clean data
				const fetchResponse = await axios.get(apiUrl, {
					headers: {
						'Accept': 'application/json',
					},
				})

				const currentSchema = fetchResponse.data

				// Prepare the schema data for update
				const updatedSchema = {
					title: currentSchema.title,
					type: currentSchema.type || 'object',
					properties: currentSchema.properties || {},
					required: currentSchema.required || [],
					description: currentSchema.description || '',
				}

				if (configurationType === 'magic') {
					// Convert to magic table: ensure all properties have table configuration
					if (!updatedSchema.properties || typeof updatedSchema.properties !== 'object') {
						updatedSchema.properties = {}
					}

					// Add basic table configuration to all properties if they don't have it
					Object.keys(updatedSchema.properties).forEach(key => {
						if (!updatedSchema.properties[key].table) {
							updatedSchema.properties[key].table = {
								enabled: true,
								indexed: false,
								searchable: false,
							}
						}
					})

					showSuccess(t('openregister', 'Converting {schema} to magic table...', { schema: schema.title }))
				} else {
					// Convert to blob storage: remove all table configurations
					if (updatedSchema.properties && typeof updatedSchema.properties === 'object') {
						Object.keys(updatedSchema.properties).forEach(key => {
							if (updatedSchema.properties[key].table) {
								delete updatedSchema.properties[key].table
							}
						})
					}

					showSuccess(t('openregister', 'Converting {schema} to blob storage...', { schema: schema.title }))
				}

				// Send the update to the backend
				await axios.put(apiUrl, updatedSchema, {
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json',
					},
				})

				showSuccess(t('openregister', 'Schema configuration updated successfully for {schema}', { schema: schema.title }))

				// Refresh the register list to reflect changes
				await registerStore.refreshRegisterList()
			} catch (error) {
				console.error('Error updating schema configuration:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to update schema configuration for {schema}: {error}', { 
					schema: schema.title, 
					error: errorMessage 
				}))
			}
		},

		/**
		 * Get the object count for a schema
		 *
		 * @param {object} schema - Schema object
		 * @return {number} - Number of objects in the schema
		 */
		getSchemaObjectCount(schema) {
			if (!schema || !schema.stats || !schema.stats.objects) {
				return 0
			}
			return schema.stats.objects.total || 0
		},

		/**
		 * Remove a schema from a register
		 *
		 * @param {object} register - Register object
		 * @param {object} schema - Schema object
		 * @return {Promise<void>}
		 */
		async removeSchemaFromRegister(register, schema) {
			const objectCount = this.getSchemaObjectCount(schema)
			
			if (objectCount > 0) {
				showError(t('openregister', 'Cannot remove schema {schema} because it contains {count} objects', { 
					schema: schema.title,
					count: objectCount 
				}))
				return
			}

			// Confirm deletion
			if (!confirm(t('openregister', 'Are you sure you want to remove the schema "{schema}" from register "{register}"? This action cannot be undone.', {
				schema: schema.title,
				register: register.title
			}))) {
				return
			}

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/schemas/${schema.id}`

			try {
				showSuccess(t('openregister', 'Removing schema {schema}...', { schema: schema.title }))
				
				// Delete the schema
				await axios.delete(apiUrl, {
					headers: {
						'Accept': 'application/json',
					},
				})

				showSuccess(t('openregister', 'Schema {schema} removed successfully', { schema: schema.title }))

				// Refresh the register list to reflect changes
				await registerStore.refreshRegisterList()
			} catch (error) {
				console.error('Error removing schema:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to remove schema {schema}: {error}', { 
					schema: schema.title, 
					error: errorMessage 
				}))
			}
		},

		/**
		 * Validate all objects in a schema
		 *
		 * @param {object} register - Register object
		 * @param {object} schema - Schema object
		 * @return {Promise<void>}
		 */
		async validateSchemaObjects(register, schema) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/objects/validate`

			try {
				showSuccess(t('openregister', 'Starting validation for {schema}...', { schema: schema.title }))
				
				const response = await axios.post(apiUrl, {
					register: register.id,
					schema: schema.id,
				}, {
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json',
					},
				})

			if (response.data && response.data.success) {
				const stats = response.data.statistics
				showSuccess(t('openregister', 'Validation completed for {schema}: {processed} processed, {updated} updated, {failed} failed', {
					schema: schema.title,
					processed: stats.processed,
					updated: stats.updated,
					failed: stats.failed
				}))
				
				console.log('Validation completed:', stats)
				if (stats.errors && stats.errors.length > 0) {
					console.warn('Validation errors:', stats.errors)
				}
				
				// Refresh the register list to reflect updated counts
				await registerStore.refreshRegisterList()
			} else {
				showSuccess(t('openregister', 'Validation completed for {schema}', { schema: schema.title }))
			}
			} catch (error) {
				console.error('Error validating schema objects:', error)
				const errorMessage = error.response?.data?.message || error.message || 'Unknown error'
				showError(t('openregister', 'Failed to validate {schema}: {error}', { 
					schema: schema.title, 
					error: errorMessage 
				}))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* Register card description */
.registerDescription {
	padding: 16px;
	margin: 12px 0 12px 0;
	background-color: var(--color-background-hover);
	color: var(--color-text-lighter);
	font-size: 0.95em;
	line-height: 1.5;
	min-height: 80px;
	max-height: 100px;
	overflow: hidden;
	word-wrap: break-word;
	overflow-wrap: break-word;
	word-break: break-word;
	hyphens: auto;
	box-sizing: border-box;
	cursor: pointer;
	transition: max-height 0.3s ease;
	display: -webkit-box;
	-webkit-line-clamp: 4;
	line-clamp: 4;
	-webkit-box-orient: vertical;
}

.registerDescription:hover {
	background-color: var(--color-background-dark);
}

.registerDescription--expanded {
	max-height: none !important;
	display: block;
	-webkit-line-clamp: unset;
	line-clamp: unset;
}

.registerDescription--empty {
	cursor: default;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.registerDescription--empty:hover {
	background-color: var(--color-background-hover);
}

/* View more button container */
.viewMoreContainer {
	display: flex;
	justify-content: stretch;
	padding: 0;
}

.viewMoreContainer button {
	width: 100%;
	border-radius: 0 0 8px 8px;
}

/* Empty text styling */
.emptyText {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: 16px !important;
}

/* Remove all borders between sections */
.card .registerSchemas {
	border-top: none !important;
	margin-top: 0 !important;
}

.card .registerSchemas thead {
	border-top: none !important;
}

.card .registerSchemas thead tr {
	border-top: none !important;
}

.card .registerSchemas thead th {
	border-top: none !important;
}

/* Remove border after card header */
.card .cardHeader {
	border-bottom: none !important;
	margin-bottom: 0 !important;
	padding-bottom: 0 !important;
}

.card .cardHeader h2 {
	margin-bottom: 0;
}

/* So that the actions menu is not overlapped by the sidebar button when it is closed */
.sidebar-closed {
	margin-right: 35px;
}

/* Card borders for managed registers (external - green) */
.card--managed {
	border: 2px solid var(--color-success);
}

/* Card borders for local configurations (orange) */
.card--local {
	border: 2px solid var(--color-warning);
}

/* Table row borders for managed registers (external - green) */
.viewTableRow--managed {
	border-left: 4px solid var(--color-success);
}

/* Table row borders for local configurations (orange) */
.viewTableRow--local {
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

/* Schema icons */
.schemaNameCell {
	display: table-cell;
	vertical-align: middle;
}

.schemaNameCell .schemaIcon {
	margin-right: 8px;
	vertical-align: middle;
	display: inline-block;
}

.schemaIcon--magic {
	color: var(--color-success);
}

.schemaIcon--blob {
	color: var(--color-primary);
}
</style>
