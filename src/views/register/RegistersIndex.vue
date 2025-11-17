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
						<div v-for="register in paginatedRegisters" :key="register.id" class="card" :class="{ 'card--managed': !!getManagingConfiguration(register) }">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="register.description">
									<DatabaseOutline :size="20" />
									{{ register.title }}
									<span v-if="getManagingConfiguration(register)" class="managedBadge">
										<CogOutline :size="16" />
										{{ t('openregister', 'Managed') }}
									</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click :disabled="calculating === register.id" @click="calculateSizes(register)">
										<template #icon>
											<Calculator :size="20" />
										</template>
										Calculate Sizes
									</NcActionButton>
									<NcActionButton 
										v-tooltip="getManagingConfiguration(register) ? 'Cannot edit: This register is managed by ' + getManagingConfiguration(register).title : ''"
										close-after-click
										:disabled="!!getManagingConfiguration(register)"
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
									<th>{{ t('openregister', 'Type') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(schema, index) in getDisplayedSchemas(register)" :key="schema.id">
									<td>{{ schema.title }}</td>
									<td>{{ schema.type || 'object' }}</td>
								</tr>
								<tr v-if="!register.schemas || register.schemas.length === 0">
									<td colspan="2" class="emptyText">
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
										'viewTableRow--managed': !!getManagingConfiguration(register)
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
												<span v-if="getManagingConfiguration(register)" class="managedBadge">
													<CogOutline :size="16" />
													{{ t('openregister', 'Managed') }}
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
											<NcActionButton close-after-click :disabled="calculating === register.id" @click="calculateSizes(register)">
												<template #icon>
													<Calculator :size="20" />
												</template>
												Calculate Sizes
											</NcActionButton>
											<NcActionButton 
												v-tooltip="getManagingConfiguration(register) ? 'Cannot edit: This register is managed by ' + getManagingConfiguration(register).title : ''"
												close-after-click
												:disabled="!!getManagingConfiguration(register)"
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
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Export from 'vue-material-design-icons/Export.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
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
		FileCodeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Upload,
		Export,
		ApiIcon,
		Download,
		Calculator,
		Refresh,
		Plus,
		InformationOutline,
		ChevronDown,
		ChevronUp,
		CogOutline,
		PaginationComponent,
	},
	data() {
		return {
			calculating: null,
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
				config => config.registers && config.registers.includes(register.id)
			) || null
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

/* Card borders for managed registers */
.card--managed {
	border: 2px solid var(--color-success);
}

/* Table row borders for managed registers */
.viewTableRow--managed {
	border-left: 4px solid var(--color-success);
}

/* Managed by Configuration badge */
.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: var(--color-success);
	color: white;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}
</style>
