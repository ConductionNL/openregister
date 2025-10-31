<template>
	<SettingsSection
		name="Retention"
		description="Configure data and log retention policies"
		:loading="loading"
		loading-message="Loading retention settings...">
		<!-- Actions slot -->
		<template #actions>
			<NcButton
				type="error"
				:disabled="loading || saving || rebasing"
				@click="showRebaseDialog">
				<template #icon>
					<NcLoadingIcon v-if="rebasing" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				Rebase
			</NcButton>
			<NcButton
				type="primary"
				:disabled="loading || saving || rebasing"
				@click="saveSettings">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Save v-else :size="20" />
				</template>
				Save
			</NcButton>
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Configure retention policies for objects and audit logs. Object retention controls when inactive objects are archived and permanently deleted.
				Log retention manages how long audit trails for different CRUD operations are kept for compliance and debugging.
				<strong>Note:</strong> Setting retention to 0 means data is kept forever (not advisable for production).
			</p>
			<p class="toggle-status" :class="retentionStatusClass">
				<span :class="retentionStatusTextClass">{{ retentionStatusMessage }}</span>
			</p>
			<p class="impact-description warning-box">
				<strong>⚠️ Important:</strong> Changes to retention policies only apply to objects that are "touched" (created, updated, or accessed) after the retention policy was changed.
				Existing objects will retain their previous retention schedules until they are modified.
			</p>
		</div>

		<!-- Enable/Disable Trail Features -->
		<div class="option-section">
			<h4>Trail Features</h4>
			<p class="option-description">
				Control which types of audit trails are enabled. Disabling trails will stop recording new entries but won't affect existing data.
			</p>

			<div class="trail-switches">
				<div class="trail-switch-row">
					<NcCheckboxRadioSwitch
						:checked.sync="auditTrailsEnabled"
						:disabled="loading || saving"
						type="switch">
						Audit Trails enabled
					</NcCheckboxRadioSwitch>
					<p class="trail-description">
						Record all CRUD operations (create, read, update, delete) for objects and system actions
					</p>
				</div>

				<div class="trail-switch-row">
					<NcCheckboxRadioSwitch
						:checked.sync="searchTrailsEnabled"
						:disabled="loading || saving"
						type="switch">
						Search Trails enabled
					</NcCheckboxRadioSwitch>
					<p class="trail-description">
						Record search queries and analytics for performance monitoring and usage insights
					</p>
				</div>
			</div>
		</div>

		<!-- Consolidated Retention Settings -->
		<div class="option-section">
			<h4>Data & Log Retention Policies</h4>
			<p class="option-description">
				Configure retention periods for objects and audit logs (in milliseconds). Object retention controls lifecycle management, while log retention manages audit trail storage by action type.
			</p>

			<div class="retention-table">
				<div class="retention-row">
					<div class="retention-label">
						<strong>Soft Delete After Inactivity</strong>
						<p class="retention-description">
							Time since last CRUD action before object is soft-deleted
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.objectArchiveRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="31536000000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.objectArchiveRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Permanent Delete After Soft Delete</strong>
						<p class="retention-description">
							Time from soft-delete to permanent deletion
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.objectDeleteRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="63072000000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.objectDeleteRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Search Trail Retention</strong>
						<p class="retention-description">
							Retention period for search query audit trails and analytics
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.searchTrailRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="2592000000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.searchTrailRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Create Action Logs</strong>
						<p class="retention-description">
							Retention period for object creation audit logs
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.createLogRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="2592000000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.createLogRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Read Action Logs</strong>
						<p class="retention-description">
							Retention period for object access/view audit logs
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.readLogRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="86400000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.readLogRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Update Action Logs</strong>
						<p class="retention-description">
							Retention period for object modification audit logs
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.updateLogRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="604800000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.updateLogRetention) }}
					</div>
				</div>

				<div class="retention-row">
					<div class="retention-label">
						<strong>Delete Action Logs</strong>
						<p class="retention-description">
							Retention period for object deletion audit logs
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								v-model.number="retentionOptions.deleteLogRetention"
								type="number"
								:disabled="loading || saving"
								placeholder="2592000000"
								class="retention-input-field">
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions.deleteLogRetention) }}
					</div>
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import { NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'

export default {
	name: 'RetentionConfiguration',

	components: {
		SettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Refresh,
		Save,
	},

	computed: {
		...mapStores(useSettingsStore),

		retentionOptions: {
			get() {
				return this.settingsStore.retentionOptions
			},
			set(value) {
				this.settingsStore.retentionOptions = value
			},
		},

		auditTrailsEnabled: {
			get() {
				return this.settingsStore.retentionOptions.auditTrailsEnabled ?? true
			},
			set(value) {
				this.settingsStore.retentionOptions.auditTrailsEnabled = value
			},
		},

		searchTrailsEnabled: {
			get() {
				return this.settingsStore.retentionOptions.searchTrailsEnabled ?? true
			},
			set(value) {
				this.settingsStore.retentionOptions.searchTrailsEnabled = value
			},
		},

		loading() {
			return this.settingsStore.loading
		},

		saving() {
			return this.settingsStore.saving
		},

		rebasing() {
			return this.settingsStore.rebasing
		},

		retentionStatusClass() {
			return this.settingsStore.retentionStatusClass
		},

		retentionStatusTextClass() {
			return this.settingsStore.retentionStatusTextClass
		},

		retentionStatusMessage() {
			return this.settingsStore.retentionStatusMessage
		},
	},

	methods: {
		showRebaseDialog() {
			this.settingsStore.showRebaseDialog()
		},

		async saveSettings() {
			await this.settingsStore.updateRetentionSettings(this.retentionOptions)
		},
		/**
		 * Format retention period from milliseconds to human readable format
		 *
		 * @param {number} ms Milliseconds
		 * @return {string} Formatted period
		 */
		formatRetentionPeriod(ms) {
			if (!ms || ms === 0) return 'Forever'

			const seconds = ms / 1000
			const minutes = seconds / 60
			const hours = minutes / 60
			const days = hours / 24
			const weeks = days / 7
			const months = days / 30.44 // Average month length
			const years = days / 365.25 // Account for leap years

			if (years >= 1) {
				return `${years.toFixed(1)} year${years !== 1 ? 's' : ''}`
			} else if (months >= 1) {
				return `${months.toFixed(1)} month${months !== 1 ? 's' : ''}`
			} else if (weeks >= 1) {
				return `${weeks.toFixed(1)} week${weeks !== 1 ? 's' : ''}`
			} else if (days >= 1) {
				return `${days.toFixed(1)} day${days !== 1 ? 's' : ''}`
			} else if (hours >= 1) {
				return `${hours.toFixed(1)} hour${hours !== 1 ? 's' : ''}`
			} else if (minutes >= 1) {
				return `${minutes.toFixed(1)} minute${minutes !== 1 ? 's' : ''}`
			} else {
				return `${seconds.toFixed(1)} second${seconds !== 1 ? 's' : ''}`
			}
		},
	},
}
</script>

<style scoped>
/* SettingsSection handles all action button positioning and spacing */

.section-description-full {
	margin-bottom: 24px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.main-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 12px 0;
}

.toggle-status {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.warning-box {
	background: rgba(var(--color-warning), 0.1);
	border-left: 3px solid var(--color-warning);
	padding: 12px;
	margin: 12px 0 0 0;
	border-radius: 0 var(--border-radius) var(--border-radius) 0;
}

.impact-description {
	margin: 0;
	color: var(--color-text-light);
	line-height: 1.5;
}

.option-section {
	margin: 24px 0;
}

.option-description {
	color: var(--color-text-maxcontrast);
	margin: 8px 0 16px 0;
	line-height: 1.4;
}

.option-section h4 {
	color: var(--color-text-light);
	margin: 24px 0 16px 0;
	font-size: 16px;
}

.trail-switches {
	display: flex;
	flex-direction: column;
	gap: 20px;
	margin-top: 16px;
}

.trail-switch-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.trail-description {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
	line-height: 1.3;
}

.retention-table {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 16px;
}

.retention-row {
	display: grid;
	grid-template-columns: 2fr 200px 150px;
	gap: 20px;
	align-items: start;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.retention-label strong {
	color: var(--color-text-light);
	font-weight: 500;
	display: block;
	margin-bottom: 4px;
}

.retention-description {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
	line-height: 1.3;
}

.retention-input {
	display: flex;
	align-items: center;
}

.retention-input-wrapper {
	position: relative;
	display: flex;
	align-items: center;
	width: 100%;
}

.retention-input-field {
	width: 100%;
	padding: 8px 32px 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
	font-size: 14px;
	font-family: monospace;
}

.retention-input-field:focus {
	border-color: var(--color-primary);
	outline: none;
}

.retention-input-field:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.retention-unit {
	position: absolute;
	right: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-family: monospace;
	pointer-events: none;
}

.retention-display {
	color: var(--color-text-light);
	font-weight: 500;
	font-size: 14px;
	text-align: right;
	padding: 8px 0;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	.retention-row {
		grid-template-columns: 1fr;
		gap: 12px;
	}

	.retention-display {
		text-align: left;
		background: var(--color-background-hover);
		padding: 8px 12px;
		border-radius: var(--border-radius);
		border: 1px solid var(--color-border-dark);
	}

	.section-header-inline {
		position: static;
		flex-direction: column;
		gap: 12px;
		align-items: stretch;
		margin-bottom: 20px;
	}

	.button-group {
		justify-content: center;
	}
}
</style>
