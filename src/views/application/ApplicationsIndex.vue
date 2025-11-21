<script setup>
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Applications') }}
				</h1>
				<p>{{ t('openregister', 'Manage your applications and modules') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} applications', { showing: paginatedApplications.length, total: applicationStore.applicationList.length }) }}
					</span>
					<span v-if="selectedApplications.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedApplications.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See applications as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See applications as a table'"
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
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="applicationStore.setApplicationItem(null); navigationStore.setModal('editApplication')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Application
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="applicationStore.refreshApplicationList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="applicationStore.loading || applicationStore.error || !applicationStore.applicationList.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="applicationStore.loading" :size="64" />
					<ApplicationOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="application in paginatedApplications" :key="application.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="application.description">
									<ApplicationOutline :size="20" />
									{{ application.name }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="applicationStore.setApplicationItem(application); navigationStore.setModal('editApplication')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="applicationStore.setApplicationItem(application); navigationStore.setDialog('deleteApplication')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Application Details -->
							<div class="applicationDetails">
								<p v-if="application.description" class="applicationDescription">
									{{ application.description }}
								</p>
								<div class="applicationInfo">
									<div v-if="application.version" class="applicationInfoItem">
										<strong>{{ t('openregister', 'Version') }}:</strong>
										<span>{{ application.version }}</span>
									</div>
									<div v-if="application.active !== undefined" class="applicationInfoItem">
										<strong>{{ t('openregister', 'Status') }}:</strong>
										<span :class="application.active ? 'status-active' : 'status-inactive'">
											{{ application.active ? 'Active' : 'Inactive' }}
										</span>
									</div>
									<div v-if="application.configurations" class="applicationInfoItem">
										<strong>{{ t('openregister', 'Configurations') }}:</strong>
										<span>{{ application.configurations.length }}</span>
									</div>
									<div v-if="application.registers" class="applicationInfoItem">
										<strong>{{ t('openregister', 'Registers') }}:</strong>
										<span>{{ application.registers.length }}</span>
									</div>
									<div v-if="application.schemas" class="applicationInfoItem">
										<strong>{{ t('openregister', 'Schemas') }}:</strong>
										<span>{{ application.schemas.length }}</span>
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
									<th>{{ t('openregister', 'Name') }}</th>
									<th>{{ t('openregister', 'Version') }}</th>
									<th>{{ t('openregister', 'Status') }}</th>
									<th>{{ t('openregister', 'Configurations') }}</th>
									<th>{{ t('openregister', 'Registers') }}</th>
									<th>{{ t('openregister', 'Schemas') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="application in paginatedApplications"
									:key="application.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedApplications.includes(application.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedApplications.includes(application.id)"
											@update:checked="(checked) => toggleApplicationSelection(application.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ application.name }}</strong>
											<span v-if="application.description" class="textDescription textEllipsis">{{ application.description }}</span>
										</div>
									</td>
									<td>{{ application.version || '-' }}</td>
									<td>
										<span :class="application.active ? 'status-active' : 'status-inactive'">
											{{ application.active ? 'Active' : 'Inactive' }}
										</span>
									</td>
									<td>{{ application.configurations?.length || 0 }}</td>
									<td>{{ application.registers?.length || 0 }}</td>
									<td>{{ application.schemas?.length || 0 }}</td>
									<td>{{ application.created ? new Date(application.created).toLocaleDateString() : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="applicationStore.setApplicationItem(application); navigationStore.setModal('editApplication')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="applicationStore.setApplicationItem(application); navigationStore.setDialog('deleteApplication')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
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
				v-if="applicationStore.applicationList.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(applicationStore.applicationList.length / (pagination.limit || 20))"
				:total-items="applicationStore.applicationList.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'ApplicationsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		ApplicationOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		PaginationComponent,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedApplications: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		paginatedApplications() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return applicationStore.applicationList.slice(start, end)
		},
		allSelected() {
			return applicationStore.applicationList.length > 0 && applicationStore.applicationList.every(application => this.selectedApplications.includes(application.id))
		},
		someSelected() {
			return this.selectedApplications.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (applicationStore.loading) {
				return t('openregister', 'Loading applications...')
			} else if (applicationStore.error) {
				return applicationStore.error
			} else if (!applicationStore.applicationList.length) {
				return t('openregister', 'No applications found')
			}
			return ''
		},
		emptyContentDescription() {
			if (applicationStore.loading) {
				return t('openregister', 'Please wait while we fetch your applications.')
			} else if (applicationStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!applicationStore.applicationList.length) {
				return t('openregister', 'No applications are available.')
			}
			return ''
		},
	},
	async mounted() {
		// Load Nextcloud groups into store first (needed for edit modal)
		await applicationStore.loadNextcloudGroups()
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		applicationStore.refreshApplicationList(null, true)
	},
	methods: {
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedApplications = applicationStore.applicationList.map(application => application.id)
			} else {
				this.selectedApplications = []
			}
		},
		toggleApplicationSelection(applicationId, checked) {
			if (checked) {
				this.selectedApplications.push(applicationId)
			} else {
				this.selectedApplications = this.selectedApplications.filter(id => id !== applicationId)
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
	},
}
</script>

<style scoped>
.applicationDetails {
	margin-top: 1rem;
}

.applicationDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.applicationInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.applicationInfoItem {
	display: flex;
	gap: 0.5rem;
}

.applicationInfoItem strong {
	min-width: 120px;
}

.status-active {
	color: var(--color-success);
	font-weight: 600;
}

.status-inactive {
	color: var(--color-text-lighter);
}
</style>
