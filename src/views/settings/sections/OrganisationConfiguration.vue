<template>
	<NcSettingsSection
		name="Organisation Configuration"
		description="Configure default organisation and organisation-related settings">
		<NcNoteCard v-if="saveSuccess" type="success">
			{{ t('openregister', 'Organisation settings saved successfully') }}
		</NcNoteCard>

		<NcNoteCard v-if="saveError" type="error">
			{{ saveError }}
		</NcNoteCard>

		<!-- Default Organisation Setting -->
		<div class="setting-row">
			<div class="setting-label">
				<label for="default-organisation">{{ t('openregister', 'Default Organisation') }}</label>
				<p class="setting-description">
					{{ t('openregister', 'New users without specific organisation membership will be automatically added to this organisation') }}
				</p>
			</div>
			<div class="setting-control">
				<NcSelect
					id="default-organisation"
					v-model="selectedDefaultOrganisation"
					:options="organisationOptions"
					:loading="loadingOrganisations"
					:placeholder="t('openregister', 'Select default organisation')"
					:clearable="false"
					label-outside
					input-label="Default Organisation"
					@input="handleDefaultOrganisationChange">
				<template #option="{ name, users, owner }">
					<div class="organisation-option">
						<OfficeBuilding :size="20" />
						<div class="organisation-info">
							<span class="organisation-name">{{ name }}</span>
							<span class="organisation-meta">
								{{ (users?.length || 0) }} {{ t('openregister', 'members') }} · 
								{{ t('openregister', 'Owner:') }} {{ owner || 'System' }}
							</span>
						</div>
					</div>
				</template>
				</NcSelect>
			</div>
		</div>

		<!-- Auto-create Default Organisation -->
		<div class="setting-row">
			<div class="setting-label">
				<label for="auto-create-default">{{ t('openregister', 'Auto-create Default Organisation') }}</label>
				<p class="setting-description">
					{{ t('openregister', 'Automatically create a default organisation if none exists when the app is initialized') }}
				</p>
			</div>
			<div class="setting-control">
				<NcCheckboxRadioSwitch
					id="auto-create-default"
					:checked="autoCreateDefault"
					type="switch"
					@update:checked="handleAutoCreateDefaultChange">
					{{ t('openregister', 'Enable auto-creation') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Organisation Statistics -->
		<div class="statistics-section">
			<h3>{{ t('openregister', 'Organisation Statistics') }}</h3>
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-value">{{ stats.totalOrganisations }}</div>
					<div class="stat-label">{{ t('openregister', 'Total Organisations') }}</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">{{ stats.activeOrganisations }}</div>
					<div class="stat-label">{{ t('openregister', 'Active Organisations') }}</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">{{ stats.totalMembers }}</div>
					<div class="stat-label">{{ t('openregister', 'Total Members') }}</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">{{ stats.averageMembersPerOrg }}</div>
					<div class="stat-label">{{ t('openregister', 'Avg Members/Org') }}</div>
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div class="actions-section">
			<NcButton
				type="primary"
				:disabled="saving || !hasChanges"
				@click="saveSettings">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('openregister', 'Save Settings') }}
			</NcButton>

			<NcButton
				v-if="hasChanges"
				type="secondary"
				:disabled="saving"
				@click="resetSettings">
				<template #icon>
					<UndoVariant :size="20" />
				</template>
				{{ t('openregister', 'Reset Changes') }}
			</NcButton>

			<NcButton
				type="secondary"
				:disabled="saving"
				@click="refreshData">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('openregister', 'Refresh Data') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import UndoVariant from 'vue-material-design-icons/UndoVariant.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'

import { settingsStore, organisationStore } from '../../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

/**
 * OrganisationConfiguration
 * @module Components
 * @package OpenRegister
 * 
 * Settings section for organisation-related configuration
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'OrganisationConfiguration',
	components: {
		NcSettingsSection,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		UndoVariant,
		Refresh,
		OfficeBuilding,
	},
	data() {
		return {
			// Current values
			selectedDefaultOrganisation: null,
			autoCreateDefault: true,
			
			// Original values for change detection
			originalDefaultOrganisation: null,
			originalAutoCreateDefault: true,
			
			// Organisations list
			organisations: [],
			loadingOrganisations: false,
			
			// Statistics
			stats: {
				totalOrganisations: 0,
				activeOrganisations: 0,
				totalMembers: 0,
				averageMembersPerOrg: 0,
			},
			
			// UI state
			saving: false,
			saveSuccess: false,
			saveError: null,
			saveSuccessTimeout: null,
		}
	},
	computed: {
		/**
		 * Format organisations for NcSelect
		 * 
		 * @return {Array} Formatted organisation options
		 */
		organisationOptions() {
			return this.organisations.map(org => ({
				...org,
				id: org.uuid,
				label: org.name,
			}))
		},

		/**
		 * Check if there are unsaved changes
		 * 
		 * @return {boolean} True if there are changes
		 */
		hasChanges() {
			return this.selectedDefaultOrganisation?.uuid !== this.originalDefaultOrganisation?.uuid
				|| this.autoCreateDefault !== this.originalAutoCreateDefault
		},
	},
	async mounted() {
		await this.loadData()
	},
	beforeUnmount() {
		clearTimeout(this.saveSuccessTimeout)
	},
	methods: {
		/**
		 * Load all data
		 * 
		 * @return {Promise<void>}
		 */
		async loadData() {
			await Promise.all([
				this.loadOrganisations(),
				this.loadSettings(),
				this.loadStatistics(),
			])
		},

		/**
		 * Load organisations list
		 * 
		 * @return {Promise<void>}
		 */
		async loadOrganisations() {
			this.loadingOrganisations = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/organisations')
				)
				
				if (response.data?.results) {
					this.organisations = response.data.results
				}
			} catch (error) {
				console.error('Error loading organisations:', error)
				this.saveError = this.t('openregister', 'Failed to load organisations')
			} finally {
				this.loadingOrganisations = false
			}
		},

		/**
		 * Load current settings
		 * 
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/settings/organisation')
				)
				
				const settings = response.data?.organisation || {}
				
				// Load default organisation UUID
				const defaultOrgUuid = settings.default_organisation
				if (defaultOrgUuid && this.organisations.length > 0) {
					const defaultOrg = this.organisations.find(org => org.uuid === defaultOrgUuid)
					if (defaultOrg) {
						this.selectedDefaultOrganisation = {
							...defaultOrg,
							id: defaultOrg.uuid,
							label: defaultOrg.name,
						}
						this.originalDefaultOrganisation = { ...this.selectedDefaultOrganisation }
					}
				}
				
				// Load auto-create setting
				this.autoCreateDefault = settings.auto_create_default_organisation !== false
				this.originalAutoCreateDefault = this.autoCreateDefault
				
			} catch (error) {
				console.error('Error loading settings:', error)
			}
		},

		/**
		 * Load organisation statistics
		 * 
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/organisations/statistics')
				)
				
				if (response.data) {
					this.stats = {
						totalOrganisations: response.data.total || 0,
						activeOrganisations: response.data.active || 0,
						totalMembers: response.data.totalMembers || 0,
						averageMembersPerOrg: response.data.avgMembers || 0,
					}
				}
			} catch (error) {
				console.error('Error loading statistics:', error)
				// Don't show error to user, stats are non-critical
			}
		},

		/**
		 * Handle default organisation change
		 * 
		 * @param {object} organisation - Selected organisation
		 * @return {void}
		 */
		handleDefaultOrganisationChange(organisation) {
			this.selectedDefaultOrganisation = organisation
			this.saveSuccess = false
			this.saveError = null
		},

		/**
		 * Handle auto-create default change
		 * 
		 * @param {boolean} value - New value
		 * @return {void}
		 */
		handleAutoCreateDefaultChange(value) {
			this.autoCreateDefault = value
			this.saveSuccess = false
			this.saveError = null
		},

		/**
		 * Save settings
		 * 
		 * @return {Promise<void>}
		 */
		async saveSettings() {
			this.saving = true
			this.saveSuccess = false
			this.saveError = null

			try {
				await axios.put(
					generateUrl('/apps/openregister/api/settings/organisation'),
					{
						default_organisation: this.selectedDefaultOrganisation?.uuid || null,
						auto_create_default_organisation: this.autoCreateDefault,
					}
				)

				this.saveSuccess = true
				this.originalDefaultOrganisation = { ...this.selectedDefaultOrganisation }
				this.originalAutoCreateDefault = this.autoCreateDefault

				// Auto-hide success message after 3 seconds
				clearTimeout(this.saveSuccessTimeout)
				this.saveSuccessTimeout = setTimeout(() => {
					this.saveSuccess = false
				}, 3000)

			} catch (error) {
				console.error('Error saving settings:', error)
				this.saveError = error.response?.data?.message 
					|| this.t('openregister', 'Failed to save settings')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Reset changes to original values
		 * 
		 * @return {void}
		 */
		resetSettings() {
			this.selectedDefaultOrganisation = { ...this.originalDefaultOrganisation }
			this.autoCreateDefault = this.originalAutoCreateDefault
			this.saveSuccess = false
			this.saveError = null
		},

		/**
		 * Refresh all data
		 * 
		 * @return {Promise<void>}
		 */
		async refreshData() {
			this.saveSuccess = false
			this.saveError = null
			await this.loadData()
		},
	},
}
</script>

<style scoped>
.setting-row {
	display: flex;
	gap: 24px;
	padding: 16px 0;
	border-bottom: 1px solid var(--color-border);
}

.setting-row:last-child {
	border-bottom: none;
}

.setting-label {
	flex: 1;
	min-width: 200px;
}

.setting-label label {
	font-weight: 600;
	color: var(--color-main-text);
	display: block;
	margin-bottom: 4px;
}

.setting-description {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.setting-control {
	flex: 1;
	display: flex;
	align-items: flex-start;
	min-width: 300px;
}

.organisation-option {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
}

.organisation-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
}

.organisation-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.organisation-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.statistics-section {
	margin-top: 32px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.statistics-section h3 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
}

.stat-card {
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	text-align: center;
}

.stat-value {
	font-size: 32px;
	font-weight: 600;
	color: var(--color-primary-element);
	margin-bottom: 8px;
}

.stat-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.actions-section {
	display: flex;
	gap: 12px;
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}
</style>

