<template>
	<SettingsSection
		name="Multitenancy"
		description="Configure multi-organization support and tenant isolation"
		:loading="loading"
		loading-message="Loading multitenancy settings...">
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
				Multitenancy enables multiple organizations to use the same Open Register instance while keeping their data completely separate.
				Each tenant (organization) has isolated access to their own registers, schemas, and objects, ensuring data privacy and security.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span :class="multitenancyOptions.enabled ? 'status-enabled' : 'status-disabled'">
					{{ multitenancyOptions.enabled ? 'Multitenancy enabled' : 'Multitenancy disabled' }}
				</span>
			</p>
			<p class="impact-description">
				<strong>{{ multitenancyOptions.enabled ? 'Disabling' : 'Enabling' }} Multitenancy will:</strong><br>
				<span v-if="!multitenancyOptions.enabled">
					• Enable multiple organizations to share the same system instance<br>
					• Provide complete data isolation between different tenants<br>
					• Allow centralized management while maintaining security boundaries<br>
					• Reduce infrastructure costs by sharing resources across organizations
				</span>
				<span v-else>
					• Merge all tenant data into a single shared environment<br>
					• Remove data isolation between organizations<br>
					• Simplify the system to single-tenant mode<br>
					• May expose sensitive data to unauthorized users
				</span>
			</p>
		</div>

		<!-- Enable Multitenancy Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				:checked.sync="multitenancyOptions.enabled"
				:disabled="saving"
				type="switch">
				{{ multitenancyOptions.enabled ? 'Multitenancy enabled' : 'Multitenancy disabled' }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Published Objects Bypass -->
		<div v-if="multitenancyOptions.enabled" class="option-section">
			<NcCheckboxRadioSwitch
				:checked.sync="multitenancyOptions.publishedObjectsBypassMultiTenancy"
				:disabled="saving"
				type="switch">
				Published objects bypass multi-tenancy
			</NcCheckboxRadioSwitch>
			<p class="option-description">
				When enabled, published objects will be visible to users from all organizations, bypassing multi-tenancy restrictions.
				This allows for public sharing of published content across organizational boundaries.
			</p>
		</div>

		<!-- Admin Override -->
		<div v-if="multitenancyOptions.enabled" class="option-section">
			<NcCheckboxRadioSwitch
				:checked.sync="multitenancyOptions.adminOverride"
				:disabled="saving"
				type="switch">
				{{ multitenancyOptions.adminOverride ? 'Admin override enabled' : 'Admin override disabled' }}
			</NcCheckboxRadioSwitch>
			<p class="option-description">
				Allow administrators to bypass all multi-tenancy restrictions
			</p>
		</div>

		<!-- Default Tenants -->
		<div v-if="multitenancyOptions.enabled">
			<h4>Default Tenants</h4>
			<p class="option-description">
				Configure default tenant assignments for users and objects
			</p>

			<div class="groups-table">
				<div class="groups-row">
					<div class="group-label">
						<strong>Default User Tenant</strong>
						<p class="user-type-description">
							The tenant assigned to users who are not part of any specific organization
						</p>
					</div>
					<div class="group-select">
						<NcSelect
							v-model="multitenancyOptions.defaultUserTenant"
							:options="tenantOptions"
							input-label="Default User Tenant"
							:disabled="loading || saving" />
					</div>
				</div>

				<div class="groups-row">
					<div class="group-label">
						<strong>Default Object Tenant</strong>
						<p class="user-type-description">
							The tenant assigned to objects when no specific organization is specified
						</p>
					</div>
					<div class="group-select">
						<NcSelect
							v-model="multitenancyOptions.defaultObjectTenant"
							:options="tenantOptions"
							input-label="Default Object Tenant"
							:disabled="loading || saving" />
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
import { NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'

export default {
	name: 'MultitenancyConfiguration',

	components: {
		SettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		Refresh,
		Save,
	},

	computed: {
		...mapStores(useSettingsStore),

		multitenancyOptions: {
			get() {
				return this.settingsStore.multitenancyOptions
			},
			set(value) {
				this.settingsStore.multitenancyOptions = value
			},
		},

		tenantOptions() {
			return this.settingsStore.tenantOptions
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
	},

	methods: {
		showRebaseDialog() {
			this.settingsStore.showRebaseDialog()
		},

		async saveSettings() {
			await this.settingsStore.updateMultitenancySettings(this.multitenancyOptions)
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

.impact-description {
	margin: 0;
	color: var(--color-text-light);
	line-height: 1.5;
}

.status-enabled {
	color: var(--color-success);
	font-weight: 500;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
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

.groups-table {
	display: flex;
	flex-direction: column;
	gap: 20px;
	margin-top: 16px;
}

.groups-row {
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: 20px;
	align-items: start;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.group-label strong {
	color: var(--color-text-light);
	font-weight: 500;
	display: block;
	margin-bottom: 4px;
}

.user-type-description {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
	line-height: 1.3;
}

.group-select {
	display: flex;
	align-items: center;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	.groups-row {
		grid-template-columns: 1fr;
		gap: 12px;
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
