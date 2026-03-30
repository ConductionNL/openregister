<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Audit Trails')"
			:description="t('openregister', 'View and analyze system audit trails with advanced filtering capabilities')"
			:show-title="true"
			:show-add="false"
			:show-view-toggle="false"
			:show-form-dialog="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			view-mode="table"
			:objects="objectStore.globalAuditTrails.results"
			:columns="tableColumns"
			:pagination="paginationData"
			:selectable="true"
			:selected-ids="selectedAuditTrails"
			:actions="customActions"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			row-key="id"
			:empty-text="t('openregister', 'No audit trail entries found')"
			:name-formatter="formatAuditTrailName"
			@delete="onDelete"
			@mass-delete="onMassDelete"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="selectedAuditTrails = $event">
			<!-- Custom action items in actions bar -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="exportAuditTrails">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('openregister', 'Export') }}
				</NcActionButton>
			</template>

			<!-- Action column: CnStatusBadge -->
			<template #column-action="{ row }">
				<CnStatusBadge
					:label="row.action ? row.action.toUpperCase() : 'NO ACTION'"
					:color-map="actionColorMap"
					solid>
					<template #icon>
						<Plus v-if="row.action === 'create'" :size="16" />
						<Pencil v-else-if="row.action === 'update'" :size="16" />
						<Delete v-else-if="row.action === 'delete'" :size="16" />
						<Eye v-else-if="row.action === 'read'" :size="16" />
					</template>
				</CnStatusBadge>
			</template>

			<!-- Timestamp column -->
			<template #column-created="{ row }">
				<NcDateTime :timestamp="new Date(row.created)" :ignore-seconds="false" />
			</template>

			<!-- Object ID column -->
			<template #column-object="{ row }">
				{{ row.object || '-' }}
			</template>

			<!-- Register ID column -->
			<template #column-register="{ row }">
				{{ row.register || '-' }}
			</template>

			<!-- User column -->
			<template #column-user="{ row }">
				{{ row.userName || row.user || '-' }}
			</template>

			<!-- Schema ID column -->
			<template #column-schema="{ row }">
				{{ row.schema || '-' }}
			</template>

			<!-- Size column -->
			<template #column-size="{ row }">
				{{ formatBytes(row.size) }}
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'

import Eye from 'vue-material-design-icons/Eye.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

import formatBytes from '../../services/formatBytes.js'

export default {
	name: 'AuditTrailIndex',
	components: {
		NcAppContent,
		NcActionButton,
		NcDateTime,
		CnIndexPage,
		CnStatusBadge,
		Eye,
		Plus,
		Pencil,
		Delete,
		Download,
	},
	data() {
		return {
			selectedAuditTrails: [],
			isRefreshing: false,
			copyStates: {},
			actionColorMap: {
				create: 'success',
				update: 'warning',
				delete: 'error',
				read: 'info',
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'action', label: t('openregister', 'Action') },
				{ key: 'created', label: t('openregister', 'Timestamp'), sortable: true },
				{ key: 'object', label: t('openregister', 'Object ID') },
				{ key: 'register', label: t('openregister', 'Register ID') },
				{ key: 'user', label: t('openregister', 'User') },
				{ key: 'schema', label: t('openregister', 'Schema ID') },
				{ key: 'size', label: t('openregister', 'Size') },
			]
		},
		paginationData() {
			const p = objectStore.globalAuditTrails
			return {
				page: p.page || 1,
				pages: p.pages || 1,
				total: p.total || 0,
				limit: p.limit || 50,
			}
		},
		customActions() {
			return [
				{
					label: t('openregister', 'View Details'),
					icon: Eye,
					handler: (row) => this.viewDetails(row),
				},
				{
					label: t('openregister', 'View Changes'),
					icon: CompareHorizontal,
					handler: (row) => this.viewChanges(row),
					disabled: (row) => !this.hasChanges(row),
				},
				{
					label: t('openregister', 'Copy Data'),
					icon: ContentCopy,
					handler: (row) => this.copyData(row),
				},
			]
		},
	},
	mounted() {
		this.loadAuditTrails()

		this.$root.$on('audit-trail-filters-changed', this.handleFiltersChanged)
		this.$root.$on('audit-trail-export', this.handleExport)
		this.$root.$on('audit-trail-refresh', this.handleRefresh)
	},
	beforeDestroy() {
		this.$root.$off('audit-trail-filters-changed')
		this.$root.$off('audit-trail-export')
		this.$root.$off('audit-trail-refresh')
	},
	methods: {
		formatBytes,
		formatAuditTrailName(item) {
			return t('openregister', 'Audit Trail #{id}', { id: item.id })
		},
		async loadAuditTrails() {
			try {
				await objectStore.refreshGlobalAuditTrails()
			} catch (error) {
				console.error('Error loading audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Error loading audit trails'))
			}
		},
		handleFiltersChanged(filters) {
			objectStore.setAuditTrailFilters(filters)
			this.loadAuditTrails()
		},
		handleExport(options) {
			this.exportFilteredAuditTrails(options)
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadAuditTrails()
			} finally {
				this.isRefreshing = false
			}
		},
		viewDetails(auditTrail) {
			objectStore.setAuditTrailItem(auditTrail)
			navigationStore.setDialog('auditTrailDetails')
		},
		viewChanges(auditTrail) {
			objectStore.setAuditTrailItem(auditTrail)
			navigationStore.setDialog('auditTrailChanges')
		},
		async copyData(auditTrail) {
			try {
				const data = JSON.stringify(auditTrail, null, 2)
				await navigator.clipboard.writeText(data)
				OC.Notification.showSuccess(this.t('openregister', 'Audit trail data copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				try {
					const textArea = document.createElement('textarea')
					textArea.value = JSON.stringify(auditTrail, null, 2)
					document.body.appendChild(textArea)
					textArea.select()
					document.execCommand('copy')
					document.body.removeChild(textArea)
					OC.Notification.showSuccess(this.t('openregister', 'Audit trail data copied to clipboard'))
				} catch (fallbackError) {
					console.error('Fallback copy failed:', fallbackError)
					OC.Notification.showError(this.t('openregister', 'Failed to copy data to clipboard'))
				}
			}
		},
		hasChanges(auditTrail) {
			if (!auditTrail || !auditTrail.changed) return false
			if (Array.isArray(auditTrail.changed)) return auditTrail.changed.length > 0
			if (typeof auditTrail.changed === 'object') return Object.keys(auditTrail.changed).length > 0
			return false
		},
		onDelete(id) {
			const auditTrail = objectStore.globalAuditTrails.results.find(a => a.id === id)
			if (!auditTrail) return
			objectStore.setAuditTrailItem(auditTrail)
			navigationStore.setDialog('deleteAuditTrail')
		},
		async onMassDelete(ids) {
			try {
				await objectStore.deleteMultipleGlobalAuditTrails(ids)
				this.$refs.indexPage.setMassDeleteResult({ success: true })
				this.selectedAuditTrails = []
				await this.loadAuditTrails()
			} catch (error) {
				console.error('Error deleting audit trails:', error)
				this.$refs.indexPage.setMassDeleteResult({ error: error.message })
			}
		},
		exportAuditTrails() {
			this.exportFilteredAuditTrails({ format: 'csv', includeChanges: true })
		},
		async exportFilteredAuditTrails(options) {
			try {
				const params = new URLSearchParams()
				params.append('format', options.format || 'csv')
				params.append('includeChanges', options.includeChanges || false)
				params.append('includeMetadata', options.includeMetadata || false)

				if (objectStore.filters) {
					Object.entries(objectStore.filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							params.append(key, value)
						}
					})
				}

				const response = await fetch(`/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`)
				const result = await response.json()

				if (result.success && result.data) {
					const blob = new Blob([result.data.content], { type: result.data.contentType })
					const url = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = url
					a.download = result.data.filename
					document.body.appendChild(a)
					a.click()
					window.URL.revokeObjectURL(url)
					document.body.removeChild(a)
					OC.Notification.showSuccess(this.t('openregister', 'Export completed successfully'))
				} else {
					throw new Error(result.error || 'Export failed')
				}
			} catch (error) {
				console.error('Error exporting audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Export failed: {error}', { error: error.message }))
			}
		},
		async onPageChanged(page) {
			try {
				await objectStore.fetchGlobalAuditTrails({
					_page: page,
					_limit: objectStore.globalAuditTrails.limit,
				})
				this.selectedAuditTrails = []
			} catch (error) {
				console.error('Error loading page:', error)
			}
		},
		async onPageSizeChanged(pageSize) {
			try {
				await objectStore.fetchGlobalAuditTrails({
					_page: 1,
					_limit: pageSize,
				})
				this.selectedAuditTrails = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		getRowClass(row) {
			return row.action ? `action-${row.action}` : ''
		},
	},
}
</script>

<style scoped>
/* Action-specific row styling — only when not selected */
:deep(.action-create:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

:deep(.action-update:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
}

:deep(.action-delete:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-error);
}

:deep(.action-read:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-info, #0082c9);
}
</style>
