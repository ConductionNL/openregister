<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Configurations') }}
				</h1>
				<p>{{ t('openregister', 'Manage your system configurations and settings') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} configurations', { showing: paginatedConfigurations.length, total: configurationStore.configurationList.length }) }}
					</span>
					<span v-if="selectedConfigurations.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedConfigurations.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See configurations as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See configurations as a table'"
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
						:inline="3"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="configurationStore.setConfigurationItem(null); navigationStore.setModal('editConfiguration')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Configuration
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="navigationStore.setModal('importConfiguration')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Import Configuration
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="configurationStore.refreshConfigurationList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="configurationStore.loading || configurationStore.error || !configurationStore.configurationList.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="configurationStore.loading" :size="64" />
					<CogOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="configuration in paginatedConfigurations" :key="configuration.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="configuration.description">
									<CogOutline :size="20" />
									{{ configuration.title }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('viewConfiguration')">
										<template #icon>
											<Eye :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('editConfiguration')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('exportConfiguration')">
										<template #icon>
											<Download :size="20" />
										</template>
										Export
									</NcActionButton>
									<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setDialog('deleteConfiguration')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Configuration Details -->
							<div class="configurationDetails">
								<p v-if="configuration.description" class="configurationDescription">
									{{ configuration.description }}
								</p>
								<div class="configurationInfo">
									<div class="configurationInfoItem">
										<strong>{{ t('openregister', 'Type') }}:</strong>
										<span>{{ configuration.type || 'Unknown' }}</span>
									</div>
									<div v-if="configuration.owner" class="configurationInfoItem">
										<strong>{{ t('openregister', 'Owner') }}:</strong>
										<span>{{ configuration.owner }}</span>
									</div>
									<div v-if="configuration.configuration" class="configurationInfoItem">
										<strong>{{ t('openregister', 'Configuration Keys') }}:</strong>
										<span>{{ Object.keys(configuration.configuration || {}).length }} keys</span>
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
									<th>{{ t('openregister', 'Type') }}</th>
									<th>{{ t('openregister', 'Owner') }}</th>
									<th>{{ t('openregister', 'Config Keys') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th>{{ t('openregister', 'Updated') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="configuration in paginatedConfigurations"
									:key="configuration.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedConfigurations.includes(configuration.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedConfigurations.includes(configuration.id)"
											@update:checked="(checked) => toggleConfigurationSelection(configuration.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ configuration.title }}</strong>
											<span v-if="configuration.description" class="textDescription textEllipsis">{{ configuration.description }}</span>
										</div>
									</td>
									<td>{{ configuration.type || 'Unknown' }}</td>
									<td>{{ configuration.owner || '-' }}</td>
									<td>{{ Object.keys(configuration.configuration || {}).length }}</td>
									<td>{{ configuration.created ? new Date(configuration.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(configuration.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td>{{ configuration.updated ? new Date(configuration.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(configuration.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('viewConfiguration')">
												<template #icon>
													<Eye :size="20" />
												</template>
												View
											</NcActionButton>
											<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('editConfiguration')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setModal('exportConfiguration')">
												<template #icon>
													<Download :size="20" />
												</template>
												Export
											</NcActionButton>
											<NcActionButton close-after-click @click="configurationStore.setConfigurationItem(configuration); navigationStore.setDialog('deleteConfiguration')">
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
				v-if="configurationStore.configurationList.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(configurationStore.configurationList.length / (pagination.limit || 20))"
				:total-items="configurationStore.configurationList.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'ConfigurationsIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		CogOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Download,
		Upload,
		Refresh,
		Plus,
		Eye,
		PaginationComponent,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedConfigurations: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		paginatedConfigurations() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return configurationStore.configurationList.slice(start, end)
		},
		allSelected() {
			return configurationStore.configurationList.length > 0 && configurationStore.configurationList.every(configuration => this.selectedConfigurations.includes(configuration.id))
		},
		someSelected() {
			return this.selectedConfigurations.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (configurationStore.loading) {
				return t('openregister', 'Loading configurations...')
			} else if (configurationStore.error) {
				return configurationStore.error
			} else if (!configurationStore.configurationList.length) {
				return t('openregister', 'No configurations found')
			}
			return ''
		},
		emptyContentDescription() {
			if (configurationStore.loading) {
				return t('openregister', 'Please wait while we fetch your configurations.')
			} else if (configurationStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!configurationStore.configurationList.length) {
				return t('openregister', 'No configurations are available.')
			}
			return ''
		},
	},
	mounted() {
		configurationStore.refreshConfigurationList()
	},
	methods: {
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedConfigurations = configurationStore.configurationList.map(configuration => configuration.id)
			} else {
				this.selectedConfigurations = []
			}
		},
		toggleConfigurationSelection(configurationId, checked) {
			if (checked) {
				this.selectedConfigurations.push(configurationId)
			} else {
				this.selectedConfigurations = this.selectedConfigurations.filter(id => id !== configurationId)
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
.configurationDetails {
	margin-top: 1rem;
}

.configurationDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.configurationInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.configurationInfoItem {
	display: flex;
	gap: 0.5rem;
}

.configurationInfoItem strong {
	min-width: 120px;
}
</style>
