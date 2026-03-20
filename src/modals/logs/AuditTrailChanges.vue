<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'auditTrailChanges'"
		:name="t('openregister', 'Audit Trail Changes')"
		size="large"
		:can-close="true"
		@close="closeDialog"
		@update:open="closeDialog">
		<div v-if="objectStore.auditTrailItem" class="audit-trail-changes">
			<!-- Header Information -->
			<div class="details-section">
				<h3>{{ t('openregister', 'Changes for Audit Trail #{id}', { id: objectStore.auditTrailItem.id }) }}</h3>
				<CnDetailGrid :items="headerInfoItems">
					<template #item-0>
						<CnStatusBadge
							:label="objectStore.auditTrailItem.action ? objectStore.auditTrailItem.action.toUpperCase() : 'NO ACTION'"
							:color-map="actionColorMap"
							solid>
							<template #icon>
								<Plus v-if="objectStore.auditTrailItem.action === 'create'" :size="16" />
								<Pencil v-else-if="objectStore.auditTrailItem.action === 'update'" :size="16" />
								<Delete v-else-if="objectStore.auditTrailItem.action === 'delete'" :size="16" />
								<Eye v-else-if="objectStore.auditTrailItem.action === 'read'" :size="16" />
							</template>
						</CnStatusBadge>
					</template>
					<template #item-1>
						<NcDateTime :relative-time="false" :timestamp="new Date(objectStore.auditTrailItem.created)" :ignore-seconds="false" />
					</template>
				</CnDetailGrid>
			</div>

			<!-- Changes Table -->
			<div v-if="hasChanges" class="details-section">
				<div v-if="isTableChanges">
					<CnDataTable
						:columns="tableColumns"
						:rows="tableRows"
						:selectable="false"
						:empty-text="t('openregister', 'No changes recorded')">
						<template #column-oldValue="{ value }">
							<pre v-if="isObject(value)" class="value-pre">{{ formatValue(value) }}</pre>
							<span v-else>{{ formatValue(value) }}</span>
						</template>

						<template #column-newValue="{ value }">
							<pre v-if="isObject(value)" class="value-pre">{{ formatValue(value) }}</pre>
							<span v-else>{{ formatValue(value) }}</span>
						</template>

						<template #column-changeType="{ row }">
							<CnStatusBadge
								:label="row.changeTypeLabel"
								:variant="changeTypeVariantMap[row.changeType]"
								size="small"
								solid />
						</template>
					</CnDataTable>
				</div>

				<!-- Raw changes view for non-standard formats -->
				<div v-else>
					<h4>{{ t('openregister', 'Raw Changes Data') }}</h4>
					<CnJsonViewer
						:value="formatChanges(objectStore.auditTrailItem.changed)"
						:read-only="true"
						height="300px" />
				</div>
			</div>

			<!-- No changes message -->
			<div v-else class="no-changes">
				<NcEmptyContent
					:name="t('openregister', 'No changes recorded')"
					:description="t('openregister', 'This audit trail entry does not contain any change information.')">
					<template #icon>
						<InformationOutline />
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<template #actions>
			<NcButton @click="copyChanges">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('openregister', 'Copy Changes') }}
			</NcButton>
			<NcButton @click="viewFullDetails">
				<template #icon>
					<Eye :size="20" />
				</template>
				{{ t('openregister', 'View Full Details') }}
			</NcButton>
			<NcButton @click="closeDialog">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('openregister', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDateTime,
	NcDialog,
	NcEmptyContent,
} from '@nextcloud/vue'
import { CnStatusBadge, CnJsonViewer, CnDetailGrid, CnDataTable } from '@conduction/nextcloud-vue'

import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'AuditTrailChanges',
	components: {
		NcDateTime,
		NcDialog,
		NcButton,
		NcEmptyContent,
		CnStatusBadge,
		CnJsonViewer,
		CnDetailGrid,
		CnDataTable,
		// Icons
		Close,
		ContentCopy,
		Eye,
		Plus,
		Pencil,
		Delete,
		InformationOutline,
	},
	data() {
		return {
			actionColorMap: { create: 'success', update: 'warning', delete: 'error', read: 'info' },
			changeTypeVariantMap: { added: 'success', removed: 'error', modified: 'warning', unchanged: 'default' },
		}
	},
	computed: {
		hasChanges() {
			const changed = objectStore.auditTrailItem?.changed
			if (!changed) return false

			if (Array.isArray(changed)) {
				return changed.length > 0
			}

			if (typeof changed === 'object') {
				return Object.keys(changed).length > 0
			}

			return !!changed
		},

		changes() {
			const changed = objectStore.auditTrailItem?.changed
			if (!changed) return {}

			if (typeof changed === 'object' && !Array.isArray(changed)) {
				const hasStandardFormat = Object.values(changed).every(value =>
					typeof value === 'object'
					&& value !== null
					&& (Object.prototype.hasOwnProperty.call(value, 'old') || Object.prototype.hasOwnProperty.call(value, 'new')),
				)

				if (hasStandardFormat) {
					return changed
				}
			}

			return {}
		},

		isTableChanges() {
			return Object.keys(this.changes).length > 0
		},

		headerInfoItems() {
			const item = objectStore.auditTrailItem
			if (!item) return []

			return [
				{ label: this.t('openregister', 'Action') },
				{ label: this.t('openregister', 'Timestamp') },
				{ label: this.t('openregister', 'User'), value: item.userName || item.user || this.t('openregister', 'Unknown User') },
			]
		},

		tableColumns() {
			return [
				{ key: 'field', label: this.t('openregister', 'Field') },
				{ key: 'oldValue', label: this.t('openregister', 'Old Value') },
				{ key: 'newValue', label: this.t('openregister', 'New Value') },
				{ key: 'changeType', label: this.t('openregister', 'Change Type'), width: '120px' },
			]
		},

		tableRows() {
			return Object.entries(this.changes).map(([field, change]) => ({
				id: field,
				field,
				oldValue: change.old,
				newValue: change.new,
				changeType: this.getChangeType(change),
				changeTypeLabel: this.getChangeTypeLabel(change),
			}))
		},
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
		},

		formatChanges(changes) {
			if (!changes) return ''

			try {
				if (typeof changes === 'string') {
					try {
						const parsed = JSON.parse(changes)
						return JSON.stringify(parsed, null, 2)
					} catch {
						return changes
					}
				}

				return JSON.stringify(changes, null, 2)
			} catch (error) {
				return String(changes)
			}
		},

		formatValue(value) {
			if (value === null) return 'null'
			if (value === undefined) return 'undefined'
			if (value === '') return '(empty)'

			if (typeof value === 'object') {
				try {
					return JSON.stringify(value, null, 2)
				} catch {
					return String(value)
				}
			}

			return String(value)
		},

		isObject(value) {
			return value !== null && typeof value === 'object'
		},

		getChangeType(change) {
			if (!Object.prototype.hasOwnProperty.call(change, 'old') && Object.prototype.hasOwnProperty.call(change, 'new')) {
				return 'added'
			}
			if (Object.prototype.hasOwnProperty.call(change, 'old') && !Object.prototype.hasOwnProperty.call(change, 'new')) {
				return 'removed'
			}
			if (change.old !== change.new) {
				return 'modified'
			}
			return 'unchanged'
		},

		getChangeTypeLabel(change) {
			const type = this.getChangeType(change)
			switch (type) {
			case 'added':
				return this.t('openregister', 'Added')
			case 'removed':
				return this.t('openregister', 'Removed')
			case 'modified':
				return this.t('openregister', 'Modified')
			default:
				return this.t('openregister', 'Unchanged')
			}
		},

		async copyChanges() {
			try {
				const changes = this.formatChanges(objectStore.auditTrailItem.changed)
				await navigator.clipboard.writeText(changes)
				OC.Notification.showSuccess(this.t('openregister', 'Changes copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(this.t('openregister', 'Failed to copy changes'))
			}
		},

		viewFullDetails() {
			navigationStore.setDialog('auditTrailDetails')
		},
	},
}
</script>

<style scoped>
.audit-trail-changes {
	padding: 16px 0;
}

.details-section {
	margin-bottom: 24px;
}

.details-section h3 {
	margin: 0 0 16px 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.value-pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.8rem;
	white-space: pre-wrap;
	max-height: 100px;
	overflow-y: auto;
	background: var(--color-background-darker);
	padding: 8px;
	border-radius: 4px;
}

.no-changes {
	padding: 40px 20px;
}
</style>
