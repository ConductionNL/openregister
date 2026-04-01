<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Configurations"
			description="Manage your system configurations and settings"
			:show-title="true"
			:schema="configurationSchema"
			:objects="configurationStore.list"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="true"
			:selected-ids="selectedConfigurations"
			:show-form-dialog="false"
			:show-copy-action="false"
			:show-edit-action="false"
			:actions="customActions"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Create Configuration"
			empty-text="No configurations found"
			:loading="configurationStore.loading"
			:refreshing="isRefreshing"
			@add="configurationStore.setItem(null); navigationStore.setModal('editConfiguration')"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event"
			@delete="onDeleteConfiguration"
			@select="selectedConfigurations = $event">
			<!-- Action bar: import button -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="navigationStore.setModal('importConfiguration')">
					<template #icon>
						<CloudUpload :size="20" />
					</template>
					Import Configuration
				</NcActionButton>
			</template>

			<!-- Custom card template -->
			<template #card="{ object }">
				<ConfigurationCard
					:configuration="object"
					@view="handleView(object)"
					@edit="handleEdit(object)"
					@export="handleExport(object)"
					@delete="$refs.indexPage.openDeleteDialog(object)"
					@check-version="checkVersion(object)"
					@preview-update="previewUpdate(object)" />
			</template>

			<!-- Custom column: title with description -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>{{ row.title }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: source type -->
			<template #column-sourceType="{ row }">
				{{ getSourceTypeLabel(row.sourceType) }}
			</template>

			<!-- Custom column: status badges -->
			<template #column-status="{ row }">
				<div class="statusBadgesContainer">
					<template v-if="row.isLocal">
						<CnStatusBadge
							label="Local"
							variant="warning"
							:solid="true">
							<template #icon>
								<CheckCircle :size="16" />
							</template>
						</CnStatusBadge>
						<CnStatusBadge
							v-if="row.app"
							:label="row.app"
							variant="default"
							:solid="true">
							<template #icon>
								<ApplicationCog :size="16" />
							</template>
						</CnStatusBadge>
					</template>
					<CnStatusBadge
						v-else
						label="External"
						variant="success"
						:solid="true">
						<template #icon>
							<Cloud :size="16" />
						</template>
					</CnStatusBadge>
					<CnStatusBadge
						v-if="!row.isLocal && row.syncEnabled"
						:label="getSyncStatusText(row)"
						:variant="getSyncBadgeVariant(row)"
						:solid="true">
						<template #icon>
							<Sync v-if="row.syncStatus === 'success'" :size="16" />
							<AlertCircle v-else-if="row.syncStatus === 'failed'" :size="16" />
							<ClockOutline v-else :size="16" />
						</template>
					</CnStatusBadge>
					<CnStatusBadge
						v-if="hasUpdateAvailable(row)"
						label="Update Available"
						variant="warning"
						:solid="true">
						<template #icon>
							<Update :size="16" />
						</template>
					</CnStatusBadge>
				</div>
			</template>

			<!-- Custom column: local version -->
			<template #column-localVersion="{ row }">
				{{ row.localVersion || '-' }}
			</template>

			<!-- Custom column: remote version -->
			<template #column-remoteVersion="{ row }">
				{{ row.remoteVersion || '-' }}
			</template>

			<!-- Custom column: updated -->
			<template #column-updated="{ row }">
				{{ row.updated ? new Date(row.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(row.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import Update from 'vue-material-design-icons/Update.vue'

import ConfigurationCard from '../../components/cards/ConfigurationCard.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ConfigurationsIndex',
	components: {
		NcAppContent,
		NcActionButton,
		CnIndexPage,
		CnStatusBadge,
		ConfigurationCard,
		CloudUpload,
		CheckCircle,
		Cloud,
		AlertCircle,
		ClockOutline,
		ApplicationCog,
		Sync,
		Update,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedConfigurations: [],
			isRefreshing: false,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		configurationSchema() {
			return {
				title: 'Configuration',
				properties: {
					title: {
						type: 'string',
						title: t('openregister', 'Title'),
						order: 1,
					},
					sourceType: {
						type: 'string',
						title: t('openregister', 'Source'),
						order: 2,
					},
					localVersion: {
						type: 'string',
						title: t('openregister', 'Local Version'),
						order: 3,
					},
					remoteVersion: {
						type: 'string',
						title: t('openregister', 'Remote Version'),
						order: 4,
					},
					status: {
						type: 'string',
						title: t('openregister', 'Status'),
						order: 5,
					},
					updated: {
						type: 'string',
						title: t('openregister', 'Updated'),
						format: 'date-time',
						order: 6,
					},
				},
			}
		},
		customActions() {
			return [
				{
					label: 'View',
					icon: Eye,
					handler: (row) => this.handleView(row),
				},
				{
					label: 'Edit',
					icon: Pencil,
					handler: (row) => this.handleEdit(row),
				},
				{
					label: 'Check Version',
					icon: Sync,
					visible: (row) => this.isRemoteConfiguration(row),
					handler: (row) => this.checkVersion(row),
				},
				{
					label: 'Preview Update',
					icon: EyeOutline,
					visible: (row) => this.hasUpdateAvailable(row),
					handler: (row) => this.previewUpdate(row),
				},
				{
					label: 'Export',
					icon: Download,
					handler: (row) => this.handleExport(row),
				},
			]
		},
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), sortable: true },
				{ key: 'sourceType', label: t('openregister', 'Source') },
				{ key: 'localVersion', label: t('openregister', 'Local Version') },
				{ key: 'remoteVersion', label: t('openregister', 'Remote Version') },
				{ key: 'status', label: t('openregister', 'Status') },
				{ key: 'updated', label: t('openregister', 'Updated'), sortable: true },
			]
		},
		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = configurationStore.list.length
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	mounted() {
		// Use soft reload (no loading spinner) since data is hot-loaded at app startup
		configurationStore.refreshList(null, true)
	},
	methods: {
		hasUpdateAvailable(configuration) {
			if (!configuration.localVersion || !configuration.remoteVersion) {
				return false
			}
			return configuration.remoteVersion !== configuration.localVersion
		},
		isRemoteConfiguration(configuration) {
			return configuration.sourceType && configuration.sourceType !== 'local'
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
		getSyncBadgeVariant(configuration) {
			const status = configuration.syncStatus
			if (status === 'success') return 'success'
			if (status === 'failed') return 'error'
			return 'default'
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

				await configurationStore.refreshList()
			} catch (error) {
				console.error('Failed to check version:', error)
				showError('Failed to check version: ' + (error.response?.data?.error || error.message))
			}
		},
		handleView(configuration) {
			configurationStore.setItem(configuration)
			navigationStore.setModal('viewConfiguration')
		},
		handleEdit(configuration) {
			configurationStore.setItem(configuration)
			navigationStore.setModal('editConfiguration')
		},
		handleExport(configuration) {
			configurationStore.setItem(configuration)
			navigationStore.setModal('exportConfiguration')
		},
		async onDeleteConfiguration(id) {
			const configuration = configurationStore.list.find(c => c.id === id)
			if (!configuration) return
			try {
				await configurationStore.deleteOne(configuration)
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: error.message || 'An error occurred while deleting the configuration',
				})
			}
		},
		previewUpdate(configuration) {
			configurationStore.setItem(configuration)
			navigationStore.setModal('previewConfiguration')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await configurationStore.refreshList()
			} finally {
				this.isRefreshing = false
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
.titleContent {
	display: flex;
	flex-direction: column;
}

.statusBadgesContainer {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	align-items: center;
}
</style>
