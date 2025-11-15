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
							Create Configuration
						</NcActionButton>
					<NcActionButton
						close-after-click
						@click="navigationStore.setModal('importConfiguration')">
						<template #icon>
							<CloudUpload :size="20" />
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
									<template v-if="configuration.isLocal">
										<span class="configBadge configBadge--local">
											<CheckCircle :size="16" />
											Local
										</span>
										<span v-if="configuration.app" class="configBadge configBadge--app">
											<ApplicationCog :size="16" />
											{{ configuration.app }}
										</span>
									</template>
									<span v-else class="configBadge configBadge--external">
										<Cloud :size="16" />
										External
									</span>
									<span v-if="!configuration.isLocal && configuration.syncEnabled" class="configBadge" :class="'configBadge--sync-' + configuration.syncStatus">
										<Sync v-if="configuration.syncStatus === 'success'" :size="16" />
										<AlertCircle v-else-if="configuration.syncStatus === 'failed'" :size="16" />
										<ClockOutline v-else :size="16" />
										{{ getSyncStatusText(configuration) }}
									</span>
									<span v-if="hasUpdateAvailable(configuration)" class="configBadge configBadge--update">
										<Update :size="16" />
										Update Available
									</span>
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
									<NcActionButton v-if="isRemoteConfiguration(configuration)" close-after-click @click="checkVersion(configuration)">
										<template #icon>
											<Sync :size="20" />
										</template>
										Check Version
									</NcActionButton>
									<NcActionButton v-if="hasUpdateAvailable(configuration)" close-after-click @click="previewUpdate(configuration)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										Preview Update
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
										<strong>{{ t('openregister', 'Source') }}:</strong>
										<span>{{ getSourceTypeLabel(configuration.sourceType) }}</span>
									</div>
									<div v-if="configuration.sourceUrl" class="configurationInfoItem">
										<strong>{{ t('openregister', 'URL') }}:</strong>
										<span class="urlText">{{ configuration.sourceUrl }}</span>
									</div>
									<div v-if="configuration.localVersion" class="configurationInfoItem">
										<strong>{{ t('openregister', 'Local Version') }}:</strong>
										<span>{{ configuration.localVersion }}</span>
									</div>
									<div v-if="configuration.remoteVersion" class="configurationInfoItem">
										<strong>{{ t('openregister', 'Remote Version') }}:</strong>
										<span>{{ configuration.remoteVersion }}</span>
									</div>
									<div v-if="configuration.autoUpdate" class="configurationInfoItem">
										<strong>{{ t('openregister', 'Auto-Update') }}:</strong>
										<span class="badge-success">Enabled</span>
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
									<th>{{ t('openregister', 'Source') }}</th>
									<th>{{ t('openregister', 'Local Version') }}</th>
									<th>{{ t('openregister', 'Remote Version') }}</th>
									<th>{{ t('openregister', 'Status') }}</th>
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
									<td>{{ getSourceTypeLabel(configuration.sourceType) }}</td>
									<td>{{ configuration.localVersion || '-' }}</td>
									<td>{{ configuration.remoteVersion || '-' }}</td>
									<td>
										<div class="statusPillsContainer">
											<template v-if="configuration.isLocal">
												<span class="configBadge configBadge--local">
													<CheckCircle :size="16" />
													Local
												</span>
												<span v-if="configuration.app" class="configBadge configBadge--app">
													<ApplicationCog :size="16" />
													{{ configuration.app }}
												</span>
											</template>
											<span v-else class="configBadge configBadge--external">
												<Cloud :size="16" />
												External
											</span>
											<span v-if="!configuration.isLocal && configuration.syncEnabled" class="configBadge" :class="'configBadge--sync-' + configuration.syncStatus">
												<Sync v-if="configuration.syncStatus === 'success'" :size="16" />
												<AlertCircle v-else-if="configuration.syncStatus === 'failed'" :size="16" />
												<ClockOutline v-else :size="16" />
												{{ getSyncStatusText(configuration) }}
											</span>
											<span v-if="hasUpdateAvailable(configuration)" class="configBadge configBadge--update">
												<Update :size="16" />
												Update Available
											</span>
										</div>
									</td>
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
											<NcActionButton v-if="isRemoteConfiguration(configuration)" close-after-click @click="checkVersion(configuration)">
												<template #icon>
													<Sync :size="20" />
												</template>
												Check Version
											</NcActionButton>
											<NcActionButton v-if="hasUpdateAvailable(configuration)" close-after-click @click="previewUpdate(configuration)">
												<template #icon>
													<EyeOutline :size="20" />
												</template>
												Preview Update
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
		CloudUpload,
		Refresh,
		Plus,
		Eye,
		EyeOutline,
		Update,
		Sync,
		CheckCircle,
		Cloud,
		AlertCircle,
		ClockOutline,
		ApplicationCog,
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
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		configurationStore.refreshConfigurationList(null, true)
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
		hasUpdateAvailable(configuration) {
			if (!configuration.localVersion || !configuration.remoteVersion) {
				return false
			}
			// Simple version comparison - remote version different from local
			return configuration.remoteVersion !== configuration.localVersion
		},
		isRemoteConfiguration(configuration) {
			return configuration.sourceType && configuration.sourceType !== 'local'
		},
		isManualConfiguration(configuration) {
			return !configuration.sourceType || configuration.sourceType === 'local'
		},
		getSourceTypeLabel(sourceType) {
			const labels = {
				local: 'Local',
				github: 'GitHub',
				gitlab: 'GitLab',
				url: 'URL',
			}
			return labels[sourceType] || 'Unknown'
		},
		async checkVersion(configuration) {
			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/configurations/${configuration.id}/check-version`),
				)

				if (response.data.hasUpdate) {
					showSuccess(
						`Update available: ${response.data.localVersion} → ${response.data.remoteVersion}`,
					)
				} else {
					showSuccess('Configuration is up to date')
				}

				// Refresh the list to show updated version info
				await configurationStore.refreshConfigurationList()
			} catch (error) {
				console.error('Failed to check version:', error)
				showError('Failed to check version: ' + (error.response?.data?.error || error.message))
			}
		},
		previewUpdate(configuration) {
			// Set the configuration and open preview modal
			configurationStore.setConfigurationItem(configuration)
			navigationStore.setModal('previewConfiguration')
		},
		getSyncStatusText(configuration) {
			if (configuration.syncStatus === 'success' && configuration.lastSyncDate) {
				const now = new Date()
				const lastSync = new Date(configuration.lastSyncDate)
				const diffInHours = Math.floor((now - lastSync) / (1000 * 60 * 60))
				
				if (diffInHours < 1) {
					return 'Synced just now'
				} else if (diffInHours < 24) {
					return `Synced ${diffInHours}h ago`
				} else {
					const diffInDays = Math.floor(diffInHours / 24)
					return `Synced ${diffInDays}d ago`
				}
			} else if (configuration.syncStatus === 'failed') {
				return 'Sync failed'
			} else if (configuration.syncStatus === 'pending') {
				return 'Sync pending'
			} else {
				return 'Never synced'
			}
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

.urlText {
	font-family: monospace;
	font-size: 0.9em;
	color: var(--color-primary);
	word-break: break-all;
}

.badge-success {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	background-color: var(--color-success);
	color: white;
	font-size: 0.85em;
	font-weight: 500;
}

/* Configuration Badges */
.configBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

/* Local configuration badge (green/success) */
.configBadge--local {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

/* External configuration badge (blue/primary) */
.configBadge--external {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

/* App source badge (gray/secondary) */
.configBadge--app {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

/* Sync status badges */
.configBadge--sync-success {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

.configBadge--sync-failed {
	background-color: var(--color-error-light);
	color: var(--color-error-dark);
}

.configBadge--sync-pending,
.configBadge--sync-never {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

/* Update available badge */
.configBadge--update {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

/* Badge container for table view */
.statusPillsContainer {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	align-items: center;
}
</style>
