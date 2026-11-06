<template>
	<SettingsSection
		name="Retention"
		description="Configure data and log retention policies"
		:loading="loading"
		:loadingMessage="t('openregister', 'Loading retention settings...')">
		<!-- Actions slot -->
		<template #actions>
			<SectionSaveActions
				:loading="loading"
				:saving="saving"
				:rebasing="rebasing"
				@rebase="showRebaseDialog"
				@save="saveSettings" />
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Configure retention policies for objects and audit logs. Object
				retention controls when inactive objects are archived and permanently
				deleted. Log retention manages how long audit trails for different
				CRUD operations are kept for compliance and debugging.
				<strong>Note:</strong> Setting retention to 0 means data is kept
				forever (not advisable for production).
			</p>
			<p class="toggle-status" :class="retentionStatusClass">
				<span :class="retentionStatusTextClass">{{
					retentionStatusMessage
				}}</span>
			</p>
			<p class="impact-description warning-box">
				<strong>⚠️ Important:</strong> Changes to retention policies only
				apply to objects that are "touched" (created, updated, or accessed)
				after the retention policy was changed. Existing objects will retain
				their previous retention schedules until they are modified.
			</p>
		</div>

		<!-- Enable/Disable Trail Features -->
		<div class="option-section">
			<h4>Trail Features</h4>
			<p class="option-description">
				Control which types of audit trails are enabled. Disabling trails
				will stop recording new entries but won't affect existing data.
			</p>

			<div class="trail-switches">
				<div class="trail-switch-row">
					<NcCheckboxRadioSwitch
						v-model="auditTrailsEnabled"
						:disabled="loading || saving"
						type="switch">
						Audit Trails enabled
					</NcCheckboxRadioSwitch>
					<p class="trail-description">
						Record all CRUD operations (create, read, update, delete) for
						objects and system actions
					</p>
				</div>

				<div class="trail-switch-row">
					<NcCheckboxRadioSwitch
						v-model="searchTrailsEnabled"
						:disabled="loading || saving"
						type="switch">
						Search Trails enabled
					</NcCheckboxRadioSwitch>
					<p class="trail-description">
						Record search queries and analytics for performance
						monitoring and usage insights
					</p>
				</div>

				<div v-if="searchTrailsEnabled" class="trail-switch-row">
					<NcSelect
						v-model="selectedRecordingMode"
						:options="recordingModeOptions"
						:clearable="false"
						:disabled="loading || saving"
						:inputLabel="t('openregister', 'Search recording mode')"
						label="label"
						class="recording-mode-select" />
					<p class="trail-description">
						Which searches are recorded:
						<strong>All searches</strong> logs every list and query,
						<strong>Text searches only</strong> logs free-text searches
						(default), and <strong>Disabled</strong> records nothing.
					</p>
				</div>
			</div>
		</div>

		<!-- Consolidated Retention Settings -->
		<div class="option-section">
			<h4>Data & Log Retention Policies</h4>
			<p class="option-description">
				Configure retention periods for objects and audit logs (in
				milliseconds). Object retention controls lifecycle management, while
				log retention manages audit trail storage by action type.
			</p>

			<div class="retention-table">
				<div v-for="row in retentionRows" :key="row.id" class="retention-row">
					<div class="retention-label">
						<label :for="row.id"><strong>{{ row.label }}</strong></label>
						<p class="retention-description">
							{{ row.description }}
						</p>
					</div>
					<div class="retention-input">
						<div class="retention-input-wrapper">
							<input
								:id="row.id"
								v-model.number="retentionOptions[row.option]"
								type="number"
								:disabled="loading || saving"
								:placeholder="row.placeholder"
								class="retention-input-field" />
							<span class="retention-unit">ms</span>
						</div>
					</div>
					<div class="retention-display">
						{{ formatRetentionPeriod(retentionOptions[row.option]) }}
					</div>
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcSelect,
} from '@nextcloud/vue'
/**
 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
 */
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SectionSaveActions from './SectionSaveActions.vue'
import { settingsSectionState } from './settingsSectionState.js'

const RETENTION_ROWS = [
	{
		id: 'retention-object-archive',
		option: 'objectArchiveRetention',
		label: 'Soft Delete After Inactivity',
		description: 'Time since last CRUD action before object is soft-deleted',
		placeholder: '31536000000',
	},
	{
		id: 'retention-object-delete',
		option: 'objectDeleteRetention',
		label: 'Permanent Delete After Soft Delete',
		description: 'Time from soft-delete to permanent deletion',
		placeholder: '63072000000',
	},
	{
		id: 'retention-search-trail',
		option: 'searchTrailRetention',
		label: 'Search Trail Retention',
		description: 'Retention period for search query audit trails and analytics',
		placeholder: '2592000000',
	},
	{
		id: 'retention-create-log',
		option: 'createLogRetention',
		label: 'Create Action Logs',
		description: 'Retention period for object creation audit logs',
		placeholder: '2592000000',
	},
	{
		id: 'retention-read-log',
		option: 'readLogRetention',
		label: 'Read Action Logs',
		description: 'Retention period for object access/view audit logs',
		placeholder: '86400000',
	},
	{
		id: 'retention-update-log',
		option: 'updateLogRetention',
		label: 'Update Action Logs',
		description: 'Retention period for object modification audit logs',
		placeholder: '604800000',
	},
	{
		id: 'retention-delete-log',
		option: 'deleteLogRetention',
		label: 'Delete Action Logs',
		description: 'Retention period for object deletion audit logs',
		placeholder: '2592000000',
	},
	{
		id: 'retention-webhook-log',
		option: 'webhookLogRetention',
		label: 'Webhook Logs',
		description: 'Retention period for webhook delivery logs and retry attempts',
		placeholder: '2592000000',
	},
]

export default {
	name: 'RetentionConfiguration',

	components: {
		SectionSaveActions,
		NcSelect,
		SettingsSection,
		NcCheckboxRadioSwitch,
	},

	mixins: [settingsSectionState],

	data() {
		return { retentionRows: RETENTION_ROWS }
	},

	computed: {
		retentionOptions: {
			/**
			 * Read retention options from the store.
			 *
			 * @spec exclude UI plumbing — computed getter proxies the store
			 * @return {object}
			 */
			get() {
				return this.settingsStore.retentionOptions
			},

			/**
			 * Write retention options to the store.
			 *
			 * @param {object} value The new retention options.
			 * @spec exclude UI plumbing — computed setter proxies the store
			 * @return {void}
			 */
			set(value) {
				this.settingsStore.retentionOptions = value
			},
		},

		auditTrailsEnabled: {
			/**
			 * Read the audit-trails-enabled flag from the store.
			 *
			 * @spec exclude UI plumbing — computed getter proxies the store
			 * @return {boolean}
			 */
			get() {
				return this.settingsStore.retentionOptions.auditTrailsEnabled ?? true
			},

			/**
			 * Write the audit-trails-enabled flag to the store.
			 *
			 * @param {boolean} value The new value.
			 * @spec exclude UI plumbing — computed setter proxies the store
			 * @return {void}
			 */
			set(value) {
				this.settingsStore.retentionOptions.auditTrailsEnabled = value
			},
		},

		searchTrailsEnabled: {
			/**
			 * Read the search-trails-enabled flag from the store.
			 *
			 * @spec exclude UI plumbing — computed getter proxies the store
			 * @return {boolean}
			 */
			get() {
				return (
					this.settingsStore.retentionOptions.searchTrailsEnabled ?? true
				)
			},

			/**
			 * Write the search-trails-enabled flag to the store.
			 *
			 * @param {boolean} value The new value.
			 * @spec exclude UI plumbing — computed setter proxies the store
			 * @return {void}
			 */
			set(value) {
				this.settingsStore.retentionOptions.searchTrailsEnabled = value
			},
		},

		searchTrailRecordingMode: {
			/**
			 * Read the search-trail recording mode from the store.
			 *
			 * @spec exclude UI plumbing — computed getter proxies the store
			 * @return {string} One of 'all', '_search', 'none'.
			 */
			get() {
				return (
					this.settingsStore.retentionOptions.searchTrailRecordingMode
					?? '_search'
				)
			},

			/**
			 * Write the search-trail recording mode to the store.
			 *
			 * @param {string} value The new mode.
			 * @spec exclude UI plumbing — computed setter proxies the store
			 * @return {void}
			 */
			set(value) {
				this.settingsStore.retentionOptions.searchTrailRecordingMode = value
			},
		},

		/**
		 * Options for the search-trail recording-mode select.
		 *
		 * @spec exclude UI plumbing — static option list for display
		 * @return {Array<object>}
		 */
		recordingModeOptions() {
			return [
				{ value: 'all', label: this.t('openregister', 'All searches') },
				{
					value: '_search',
					label: this.t('openregister', 'Text searches only'),
				},
				{ value: 'none', label: this.t('openregister', 'Disabled') },
			]
		},

		/**
		 * The currently selected recording-mode option object (for NcSelect).
		 *
		 * @spec exclude UI plumbing — maps the stored value to its option object
		 * @return {object}
		 */
		selectedRecordingMode: {
			get() {
				return (
					this.recordingModeOptions.find(
						(o) => o.value === this.searchTrailRecordingMode,
					) || this.recordingModeOptions[1]
				)
			},

			set(option) {
				this.searchTrailRecordingMode = option ? option.value : '_search'
			},
		},

		/**
		 * Retention status CSS class from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived status-styling helper
		 * @return {string}
		 */
		retentionStatusClass() {
			return this.settingsStore.retentionStatusClass
		},

		/**
		 * Retention status text CSS class from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived status-styling helper
		 * @return {string}
		 */
		retentionStatusTextClass() {
			return this.settingsStore.retentionStatusTextClass
		},

		/**
		 * Retention status message from the store, for display.
		 *
		 * @spec exclude UI plumbing — derived status message
		 * @return {string}
		 */
		retentionStatusMessage() {
			return this.settingsStore.retentionStatusMessage
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/retention-management/spec.md#requirement-retention-settings-must-be-configurable-via-api
		 */
		async saveSettings() {
			await this.settingsStore.updateRetentionSettings(this.retentionOptions)
		},

		/**
		 * Format retention period from milliseconds to human readable format
		 *
		 * @param {number} ms Milliseconds
		 * @spec exclude UI plumbing — pure presentation helper
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

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

/* SettingsSection handles all action button positioning and spacing */

@include section-description-full-2;

@include main-description-2;

@include toggle-status;

.warning-box {
	background: rgba(var(--color-warning), 0.1);
	border-left: 3px solid var(--color-warning);
	padding: 12px;
	margin: 12px 0 0 0;
	border-radius: 0 var(--border-radius) var(--border-radius) 0;
}

@include impact-description;

.option-section {
	margin: 24px 0;
}

@include option-description;

@include option-section-h4;

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

@include loading-icon;

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
