<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<SettingsSection
		:name="t('openregister', 'Multitenancy')"
		:description="
			t(
				'openregister',
				'Configure multi-organization support and tenant isolation',
			)
		"
		:loading="loading"
		:loadingMessage="t('openregister', 'Loading multitenancy settings...')">
		<template #actions>
			<SectionSaveActions v-bind="saveActions" />
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Multitenancy enables multiple organizations to use the same Open
				Register instance while keeping their data completely separate. Each
				tenant (organization) has isolated access to their own registers,
				schemas, and objects, ensuring data privacy and security.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span
					:class="
						multitenancyOptions.enabled
							? 'status-enabled'
							: 'status-disabled'
					">
					{{
						multitenancyOptions.enabled
							? 'Multitenancy enabled'
							: 'Multitenancy disabled'
					}}
				</span>
			</p>
			<p class="impact-description">
				<strong
					>{{
						multitenancyOptions.enabled ? 'Disabling' : 'Enabling'
					}}
					Multitenancy will:</strong
				><br />
				<span v-if="!multitenancyOptions.enabled">
					• Enable multiple organizations to share the same system
					instance<br />
					• Provide complete data isolation between different tenants<br />
					• Allow centralized management while maintaining security
					boundaries<br />
					• Reduce infrastructure costs by sharing resources across
					organizations
				</span>
				<span v-else>
					• Merge all tenant data into a single shared environment<br />
					• Remove data isolation between organizations<br />
					• Simplify the system to single-tenant mode<br />
					• May expose sensitive data to unauthorized users
				</span>
			</p>
		</div>

		<!-- Enable Multitenancy Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				v-model="multitenancyOptions.enabled"
				:disabled="saving"
				type="switch">
				{{
					multitenancyOptions.enabled
						? 'Multitenancy enabled'
						: 'Multitenancy disabled'
				}}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Admin Override -->
		<div v-if="multitenancyOptions.enabled" class="option-section">
			<NcCheckboxRadioSwitch
				v-model="multitenancyOptions.adminOverride"
				:disabled="saving"
				type="switch">
				{{
					multitenancyOptions.adminOverride
						? 'Admin override enabled'
						: 'Admin override disabled'
				}}
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
							The tenant assigned to users who are not part of any
							specific organization
						</p>
					</div>
					<div class="group-select">
						<NcSelect
							v-model="multitenancyOptions.defaultUserTenant"
							:options="tenantOptions"
							:inputLabel="t('openregister', 'Default User Tenant')"
							:disabled="loading || saving" />
					</div>
				</div>

				<div class="groups-row">
					<div class="group-label">
						<strong>Default Object Tenant</strong>
						<p class="user-type-description">
							The tenant assigned to objects when no specific
							organization is specified
						</p>
					</div>
					<div class="group-select">
						<NcSelect
							v-model="multitenancyOptions.defaultObjectTenant"
							:options="tenantOptions"
							:inputLabel="t('openregister', 'Default Object Tenant')"
							:disabled="loading || saving" />
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
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SectionSaveActions from './SectionSaveActions.vue'
import { settingsSectionState } from './settingsSectionState.js'

export default {
	name: 'MultitenancyConfiguration',

	components: {
		SectionSaveActions,
		SettingsSection,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	mixins: [settingsSectionState],

	computed: {
		multitenancyOptions: {
			/**
			 * Read multitenancy options from the settings store.
			 *
			 * @spec exclude UI plumbing — store passthrough getter; tenant config owned by tenant-lifecycle.
			 * @return {object}
			 */
			get() {
				return this.settingsStore.multitenancyOptions
			},

			/**
			 * Write multitenancy options to the settings store.
			 *
			 * @spec exclude UI plumbing — store passthrough setter; tenant config owned by tenant-lifecycle.
			 * @param {object} value - new options
			 * @return {void}
			 */
			set(value) {
				this.settingsStore.multitenancyOptions = value
			},
		},

		/**
		 * Tenant options from the settings store.
		 *
		 * @spec exclude UI plumbing — store passthrough computed.
		 * @return {Array}
		 */
		tenantOptions() {
			return this.settingsStore.tenantOptions
		},
	},

	methods: {
		/**
		 * Persist multitenancy settings.
		 *
		 * @spec exclude UI plumbing — store delegation; tenant config contract owned by tenant-lifecycle.
		 * @return {Promise<void>}
		 */
		async saveSettings() {
			await this.settingsStore.updateMultitenancySettings(
				this.multitenancyOptions,
			)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

/* SettingsSection handles all action button positioning and spacing */

@include section-description-full-2-rules;

.option-section {
	margin: 24px 0;
}

@include option-description-rules;
</style>
