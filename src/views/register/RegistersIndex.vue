<script setup>
import { dashboardStore, registerStore, navigationStore } from '../../store/store.js'
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
						{{ t('openregister', 'Showing {showing} of {total} registers', { showing: filteredRegisters.length, total: dashboardStore.registers.length }) }}
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
						<NcActionButton close-after-click @click="dashboardStore.fetchRegisters()">
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
			<NcEmptyContent v-if="dashboardStore.loading || dashboardStore.error || !filteredRegisters.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="dashboardStore.loading" :size="64" />
					<DatabaseOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="registerStore.viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="register in paginatedRegisters" :key="register.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="register.description">
									<DatabaseOutline :size="20" />
									{{ register.title }}
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
									<NcActionButton close-after-click
										@click="registerStore.setRegisterItem({
											...register,
											schemas: register.schemas.map(schema => schema.id)
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
							<!-- Register Statistics Table -->
							<table class="statisticsTable registerStats">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Type') }}</th>
										<th>{{ t('openregister', 'Total') }}</th>
										<th>{{ t('openregister', 'Size') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>{{ t('openregister', 'Objects') }}</td>
										<td>{{ register.stats?.objects?.total || 0 }}</td>
										<td>{{ formatBytes(register.stats?.objects?.size || 0) }}</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											{{ t('openregister', 'Invalid') }}
										</td>
										<td>{{ register.stats?.objects?.invalid || 0 }}</td>
										<td>-</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											{{ t('openregister', 'Deleted') }}
										</td>
										<td>{{ register.stats?.objects?.deleted || 0 }}</td>
										<td>-</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											{{ t('openregister', 'Locked') }}
										</td>
										<td>{{ register.stats?.objects?.locked || 0 }}</td>
										<td>-</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											{{ t('openregister', 'Published') }}
										</td>
										<td>{{ register.stats?.objects?.published || 0 }}</td>
										<td>-</td>
									</tr>
									<tr>
										<td>{{ t('openregister', 'Logs') }}</td>
										<td>{{ register.stats?.logs?.total || 0 }}</td>
										<td>{{ formatBytes(register.stats?.logs?.size || 0) }}</td>
									</tr>
									<tr>
										<td>{{ t('openregister', 'Files') }}</td>
										<td>{{ register.stats?.files?.total || 0 }}</td>
										<td>{{ formatBytes(register.stats?.files?.size || 0) }}</td>
									</tr>
									<tr>
										<td>{{ t('openregister', 'Schemas') }}</td>
										<td>{{ register.schemas?.length || 0 }}</td>
										<td>
											<button class="toggleButton" @click.stop="toggleSchemaVisibility(register.id)">
												{{ isSchemasVisible(register.id) ? t('openregister', 'Hide') : t('openregister', 'Show') }}
											</button>
										</td>
									</tr>
								</tbody>
							</table>

							<!-- Schemas section with v-show -->
							<div v-show="isSchemasVisible(register.id)" class="nestedCardContainer">
								<div v-for="schema in register.schemas" :key="schema.id" class="nestedCard">
									<div
										class="nestedCardHeader"
										@click="toggleSchema(schema.id)">
										<div class="nestedCardTitle">
											<FileCodeOutline :size="16" />
											<span>{{ schema.stats?.objects?.total || 0 }} </span>
											{{ schema.title }}
											<span class="schemaSize">({{ formatBytes(schema.stats?.objects?.size || 0) }})</span>
										</div>
										<button class="toggleButton">
											<ChevronUp v-if="isSchemaExpanded(schema.id)" :size="20" />
											<ChevronDown v-else :size="20" />
										</button>
									</div>

									<div v-show="isSchemaExpanded(schema.id)" class="nestedCardContent">
										<table class="statisticsTable schemaStats">
											<thead>
												<tr>
													<th>{{ t('openregister', 'Type') }}</th>
													<th>{{ t('openregister', 'Total') }}</th>
													<th>{{ t('openregister', 'Size') }}</th>
												</tr>
											</thead>
											<tbody>
												<tr>
													<td>{{ t('openregister', 'Objects') }}</td>
													<td>{{ schema.stats?.objects?.total || 0 }}</td>
													<td>{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
												</tr>
												<tr class="subRow">
													<td class="indented">
														{{ t('openregister', 'Invalid') }}
													</td>
													<td>{{ schema.stats?.objects?.invalid || 0 }}</td>
													<td>-</td>
												</tr>
												<tr class="subRow">
													<td class="indented">
														{{ t('openregister', 'Deleted') }}
													</td>
													<td>{{ schema.stats?.objects?.deleted || 0 }}</td>
													<td>-</td>
												</tr>
												<tr class="subRow">
													<td class="indented">
														{{ t('openregister', 'Locked') }}
													</td>
													<td>{{ schema.stats?.objects?.locked || 0 }}</td>
													<td>-</td>
												</tr>
												<tr class="subRow">
													<td class="indented">
														{{ t('openregister', 'Published') }}
													</td>
													<td>{{ schema.stats?.objects?.published || 0 }}</td>
													<td>-</td>
												</tr>
												<tr>
													<td>{{ t('openregister', 'Logs') }}</td>
													<td>{{ schema.stats?.logs?.total || 0 }}</td>
													<td>{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
												</tr>
												<tr>
													<td>{{ t('openregister', 'Files') }}</td>
													<td>{{ schema.stats?.files?.total || 0 }}</td>
													<td>{{ formatBytes(schema.stats?.files?.size || 0) }}</td>
												</tr>
											</tbody>
										</table>
									</div>
								</div>
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
									<th>{{ t('openregister', 'Objects (Total/Size)') }}</th>
									<th>{{ t('openregister', 'Logs (Total/Size)') }}</th>
									<th>{{ t('openregister', 'Files (Total/Size)') }}</th>
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
									:class="{ viewTableRowSelected: selectedRegisters.includes(register.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedRegisters.includes(register.id)"
											@update:checked="(checked) => toggleRegisterSelection(register.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ register.title }}</strong>
											<span v-if="register.description" class="textDescription textEllipsis">{{ register.description }}</span>
										</div>
									</td>
									<td>{{ register.stats?.objects?.total || 0 }}/{{ formatBytes(register.stats?.objects?.size || 0) }}</td>
									<td>{{ register.stats?.logs?.total || 0 }}/{{ formatBytes(register.stats?.logs?.size || 0) }}</td>
									<td>{{ register.stats?.files?.total || 0 }}/{{ formatBytes(register.stats?.files?.size || 0) }}</td>
									<td class="tableColumnConstrained">
										{{ register.schemas.map(schema => schema.title).join(', ') }}
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
											<NcActionButton close-after-click
												@click="registerStore.setRegisterItem({
													...register,
													schemas: register.schemas.map(schema => schema.id)
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
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
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
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import formatBytes from '../../services/formatBytes.js'
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
		DatabaseOutline,
		FileCodeOutline,
		ChevronDown,
		ChevronUp,
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
		PaginationComponent,
	},
	data() {
		return {
			expandedSchemas: [],
			calculating: null,
			showSchemas: {},
			selectedRegisters: [],
		}
	},
	computed: {
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		paginatedRegisters() {
			const start = ((registerStore.pagination.page || 1) - 1) * (registerStore.pagination.limit || 20)
			const end = start + (registerStore.pagination.limit || 20)
			return this.filteredRegisters.slice(start, end)
		},
		isSchemaExpanded() {
			return (schemaId) => this.expandedSchemas.includes(schemaId)
		},
		isSchemasVisible() {
			return (registerId) => this.showSchemas[registerId] || false
		},
		allSelected() {
			return this.filteredRegisters.length > 0 && this.filteredRegisters.every(register => this.selectedRegisters.includes(register.id))
		},
		someSelected() {
			return this.selectedRegisters.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (dashboardStore.error) {
				return dashboardStore.error
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers found')
			} else {
				return t('openregister', 'Loading registers...')
			}
		},
		emptyContentDescription() {
			if (dashboardStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!this.filteredRegisters.length) {
				return t('openregister', 'No registers are available.')
			} else {
				return t('openregister', 'Please wait while we fetch your registers.')
			}
		},
	},
	mounted() {
		dashboardStore.preload()
	},
	methods: {
		onPageChanged(page) {
			registerStore.setPagination(page, registerStore.pagination.limit)
		},
		onPageSizeChanged(pageSize) {
			registerStore.setPagination(1, pageSize)
		},
		toggleSchema(schemaId) {
			const index = this.expandedSchemas.indexOf(schemaId)
			if (index > -1) {
				this.expandedSchemas.splice(index, 1)
			} else {
				this.expandedSchemas.push(schemaId)
			}

			// Force reactivity update
			this.expandedSchemas = [...this.expandedSchemas]
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
				await dashboardStore.fetchRegisters()
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

		toggleSchemaVisibility(registerId) {
			this.$set(this.showSchemas, registerId, !this.showSchemas[registerId])
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
			navigationStore.setSelected('register-detail')
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
.schemaSize {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-inline-start: 4px;
}

/* So that the actions menu is not overlapped by the sidebar button when it is closed */
.sidebar-closed {
	margin-right: 35px;
}
</style>
