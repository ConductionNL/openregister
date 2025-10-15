<template>
	<div>
		<!-- Main ConfigSet Management Dialog -->
		<NcDialog
			v-if="show && !navigationStore.dialog"
			:name="t('openregister', 'ConfigSet Management')"
			@closing="$emit('closing')"
			:size="'large'">
			<div class="configset-management-modal">
				<!-- Description -->
				<div class="modal-description">
					<p>{{ t('openregister', 'Manage SOLR ConfigSets (configuration templates) for your collections.') }}</p>
				</div>

				<!-- Loading State -->
				<div v-if="loading" class="loading-state">
					<NcLoadingIcon :size="32" />
					<p>{{ t('openregister', 'Loading ConfigSets...') }}</p>
				</div>

				<!-- Error State -->
				<div v-else-if="error" class="error-state">
					<p class="error-message">❌ {{ errorMessage }}</p>
					<NcButton type="primary" @click="loadConfigSets">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Retry') }}
					</NcButton>
				</div>

				<!-- ConfigSets Table -->
				<div v-else class="configsets-content">
					<!-- Info Box -->
					<div class="info-box">
						<h4>ℹ️ {{ t('openregister', 'About ConfigSets') }}</h4>
						<p>{{ t('openregister', 'ConfigSets define the schema and configuration for your SOLR collections. They contain field definitions, analyzers, and other search settings.') }}</p>
						<p>{{ t('openregister', 'You can create new ConfigSets based on the _default template, or upload custom ones directly to your SOLR server.') }}</p>
					</div>

					<!-- ConfigSets List -->
					<div class="configsets-list">
						<div class="list-header">
							<h3>{{ t('openregister', 'All ConfigSets') }} ({{ configSets.length }})</h3>
							<div class="header-actions">
								<NcButton type="primary" @click="openCreateDialog">
									<template #icon>
										<Plus :size="20" />
									</template>
									{{ t('openregister', 'Create ConfigSet') }}
								</NcButton>
								<NcButton type="secondary" @click="loadConfigSets">
									<template #icon>
										<Refresh :size="20" />
									</template>
									{{ t('openregister', 'Refresh') }}
								</NcButton>
							</div>
						</div>

						<table v-if="configSets.length > 0" class="configsets-table">
							<thead>
								<tr>
									<th>{{ t('openregister', 'ConfigSet Name') }}</th>
									<th>{{ t('openregister', 'Used By Collections') }}</th>
									<th>{{ t('openregister', 'Usage Count') }}</th>
									<th>{{ t('openregister', 'Actions') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="configSet in configSets" :key="configSet.name" class="configset-row">
									<td class="configset-name">
										<strong>⚙️ {{ configSet.name }}</strong>
										<span v-if="configSet.name === '_default'" class="default-badge">
											{{ t('openregister', 'System Default') }}
										</span>
									</td>
									<td class="collections-list-cell">
										<div v-if="configSet.usedBy && configSet.usedBy.length > 0" class="collections-badges">
											<span
												v-for="collection in configSet.usedBy"
												:key="collection"
												class="collection-badge">
												{{ collection }}
											</span>
										</div>
										<span v-else class="no-usage">{{ t('openregister', 'Not in use') }}</span>
									</td>
									<td class="number-cell">
										{{ configSet.usedByCount }}
									</td>
									<td class="actions-cell">
									<NcButton
										v-if="configSet.name !== '_default' && configSet.usedByCount === 0"
										type="error"
										@click="openDeleteDialog(configSet)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openregister', 'Delete') }}
									</NcButton>
										<span v-else-if="configSet.name === '_default'" class="protected-label">
											{{ t('openregister', 'Protected') }}
										</span>
										<span v-else class="in-use-label">
											{{ t('openregister', 'In use') }}
										</span>
									</td>
								</tr>
							</tbody>
						</table>

						<div v-else class="no-configsets">
							<p>{{ t('openregister', 'No ConfigSets found') }}</p>
						</div>
					</div>
				</div>

				<!-- Modal Actions -->
				<div class="modal-actions">
					<NcButton @click="$emit('closing')">
						{{ t('openregister', 'Close') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'ConfigSetManagementModal',
	
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Refresh,
		Plus,
		Delete,
	},
	
	props: {
		show: {
			type: Boolean,
			default: false,
		},
	},
	
	emits: ['closing'],

	data() {
		return {
			navigationStore,
			loading: false,
			error: false,
			errorMessage: '',
			configSets: [],
		}
	},

	mounted() {
		this.loadConfigSets()
		// Listen for updates from Create/Delete dialogs
		this.$root.$on('configset-updated', this.loadConfigSets)
	},

	beforeDestroy() {
		// Clean up event listener
		this.$root.$off('configset-updated', this.loadConfigSets)
	},

	methods: {
		async loadConfigSets() {
			this.loading = true
			this.error = false
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/solr/configsets')
				const response = await axios.get(url)

				if (response.data.success) {
					this.configSets = response.data.configSets
				} else {
					this.error = true
					this.errorMessage = response.data.error || 'Failed to load ConfigSets'
				}
			} catch (error) {
				console.error('Failed to load ConfigSets:', error)
				this.error = true
				this.errorMessage = error.response?.data?.error || error.message || 'Failed to load ConfigSets'
			} finally {
				this.loading = false
			}
		},

		openCreateDialog() {
			console.log('🔵 Opening create dialog, setting navigationStore.dialog to "createConfigSet"')
			navigationStore.setDialog('createConfigSet')
			console.log('🔵 navigationStore.dialog is now:', navigationStore.dialog)
		},

		openDeleteDialog(configSet) {
			console.log('🔴 Opening delete dialog for configSet:', configSet)
			navigationStore.setTransferData(configSet)
			navigationStore.setDialog('deleteConfigSet')
			console.log('🔴 navigationStore.dialog is now:', navigationStore.dialog)
		},
	},
}
</script>

<style scoped>
.configset-management-modal {
	padding: 20px;
	min-height: 400px;
}

.modal-description {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.loading-state,
.error-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	gap: 20px;
}

.error-message {
	color: var(--color-error);
	margin: 0;
}

.configsets-content {
	display: flex;
	flex-direction: column;
	gap: 30px;
}

.configsets-list {
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.list-header h3 {
	margin: 0;
	font-size: 16px;
}

.configsets-table {
	width: 100%;
	border-collapse: collapse;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.configsets-table thead {
	background: var(--color-background-dark);
}

.configsets-table th {
	padding: 12px;
	text-align: left;
	font-weight: 600;
	border-bottom: 2px solid var(--color-border);
}

.configsets-table tbody tr:hover {
	background: var(--color-background-hover);
}

.configsets-table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
}

.configset-name strong {
	font-size: 14px;
}

.collections-list-cell {
	max-width: 400px;
}

.collections-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 5px;
}

.collection-badge {
	font-size: 11px;
	padding: 3px 8px;
	border-radius: var(--border-radius-small);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.no-usage {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: 13px;
}

.number-cell {
	text-align: right;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.no-configsets {
	text-align: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.info-box {
	background: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary-element);
	padding: 15px 20px;
	border-radius: var(--border-radius);
}

.info-box h4 {
	margin: 0 0 10px 0;
	color: var(--color-primary-element-text);
	font-size: 14px;
}

.info-box p {
	margin: 5px 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

/* Info Box */
.info-box {
	background: var(--color-primary-light);
	padding: 20px;
	border-radius: var(--border-radius-large);
	border-left: 4px solid var(--color-primary);
}

.info-box h4 {
	margin-top: 0;
	margin-bottom: 10px;
	color: var(--color-primary-text);
}

.info-box p {
	margin: 8px 0;
	line-height: 1.6;
	color: var(--color-main-text);
}

/* Header Actions */
.header-actions {
	display: flex;
	gap: 10px;
}

/* Actions Cell */
.actions-cell {
	text-align: center;
	min-width: 150px;
}

.protected-label,
.in-use-label {
	color: var(--color-text-lighter);
	font-style: italic;
	font-size: 13px;
}

/* Badges */
.default-badge {
	display: inline-block;
	margin-left: 10px;
	padding: 2px 8px;
	background: var(--color-warning-light);
	color: var(--color-warning-text);
	border-radius: var(--border-radius-small);
	font-size: 11px;
	font-weight: bold;
}

/* Create/Delete Dialogs */
.create-dialog,
.delete-dialog {
	padding: 20px;
}

.create-dialog p,
.delete-dialog p {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
	color: var(--color-main-text);
}

.configset-name-input {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.form-hint {
	margin-top: 5px;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.warning-box {
	background: var(--color-warning-light);
	padding: 15px;
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
	margin: 15px 0;
}

.warning-box p {
	margin: 8px 0;
}

.warning-box strong {
	color: var(--color-warning-text);
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
	padding-top: 15px;
	border-top: 1px solid var(--color-border);
}
</style>
