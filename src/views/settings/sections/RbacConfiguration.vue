<template>
	<SettingsSection
		name="Role Based Access Control (RBAC)"
		description="Configure access permissions and user groups"
		:loading="loading"
		:loadingMessage="t('openregister', 'Loading RBAC settings...')">
		<template #actions>
			<SectionSaveActions v-bind="saveActions" />
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Role Based Access Control (RBAC) allows you to control who can access
				and modify different parts of your Open Register. When enabled, users
				are assigned to specific Nextcloud groups that determine their
				permissions for registers, schemas, and objects. Note: This system
				uses Nextcloud's built-in group functionality rather than separate
				roles.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span
					:class="
						rbacOptions.enabled ? 'status-enabled' : 'status-disabled'
					">
					{{
						rbacOptions.enabled
							? 'Role Based Access Control enabled'
							: 'Role Based Access Control disabled'
					}}
				</span>
			</p>
			<p class="impact-description">
				<strong
					>{{ rbacOptions.enabled ? 'Disabling' : 'Enabling' }} RBAC
					will:</strong
				><br />
				<span v-if="!rbacOptions.enabled">
					• Provide fine-grained access control over registers and
					schemas<br />
					• Allow you to assign users to specific Nextcloud groups (Viewer,
					Editor, Admin)<br />
					• Enable secure multi-user environments with proper permission
					boundaries<br />
					• Require group assignment for new users accessing the system
				</span>
				<span v-else>
					• Remove all group-based restrictions and permissions<br />
					• Grant all users full access to all registers and schemas<br />
					• Simplify user management but reduce security controls<br />
					• Allow unrestricted access to sensitive data and configurations
				</span>
			</p>
		</div>

		<!-- Enable RBAC Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				v-model="rbacOptions.enabled"
				:disabled="saving"
				type="switch">
				{{
					rbacOptions.enabled
						? 'Role Based Access Control enabled'
						: 'Role Based Access Control disabled'
				}}
			</NcCheckboxRadioSwitch>

			<!-- Admin Override -->
			<div v-if="rbacOptions.enabled">
				<NcCheckboxRadioSwitch
					v-model="rbacOptions.adminOverride"
					:disabled="saving"
					type="switch">
					{{
						rbacOptions.adminOverride
							? 'Admin override enabled'
							: 'Admin override disabled'
					}}
				</NcCheckboxRadioSwitch>
				<p class="option-description">
					Allow administrators to bypass all RBAC restrictions
				</p>

				<h4>Default User Groups</h4>
				<p class="option-description">
					Configure which Nextcloud groups different types of users are
					assigned to by default
				</p>

				<div class="groups-table">
					<div class="groups-row">
						<div class="group-label">
							<strong>Anonymous Users</strong>
							<p class="user-type-description">
								Unidentified, non-logged-in users who access public
								content without authentication
							</p>
						</div>
						<div class="group-select">
							<NcSelect
								v-model="rbacOptions.anonymousGroup"
								:options="groupOptions"
								:inputLabel="t('openregister', 'Anonymous Group')"
								:disabled="loading || saving" />
						</div>
					</div>

					<div class="groups-row">
						<div class="group-label">
							<strong>Default New Users</strong>
							<p class="user-type-description">
								Authenticated users who have logged in but haven't
								been assigned to specific groups yet
							</p>
						</div>
						<div class="group-select">
							<NcSelect
								v-model="rbacOptions.defaultNewUserGroup"
								:options="groupOptions"
								:inputLabel="t('openregister', 'New User Group')"
								:disabled="loading || saving" />
						</div>
					</div>

					<div class="groups-row">
						<div class="group-label">
							<strong>Default Object Owner</strong>
							<p class="user-type-description">
								Default user assigned as owner when creating new
								objects without explicit ownership
							</p>
						</div>
						<div class="group-select">
							<NcSelect
								v-model="rbacOptions.defaultObjectOwner"
								:options="userOptions"
								:inputLabel="t('openregister', 'Default Owner')"
								:disabled="loading || saving" />
						</div>
					</div>
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcCheckboxRadioSwitch,
	NcSelect,
} from '@nextcloud/vue'
/**
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
 * @spec openspec/changes/rights-screen-stated/specs/rbac-scopes/spec.md#REQ-RSS-001
 */
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SectionSaveActions from './SectionSaveActions.vue'
import { settingsSectionState } from './settingsSectionState.js'

export default {
	name: 'RbacConfiguration',

	components: {
		SectionSaveActions,
		SettingsSection,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	mixins: [settingsSectionState],

	computed: {
		rbacOptions: {
			/**
			 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
			 */
			get() {
				return this.settingsStore.rbacOptions
			},

			/**
			 * @param {object} value
			 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
			 */
			set(value) {
				this.settingsStore.rbacOptions = value
			},
		},

		/**
		 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
		 */
		groupOptions() {
			return this.settingsStore.groupOptions
		},

		/**
		 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
		 */
		userOptions() {
			return this.settingsStore.userOptions
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/rbac-scopes/spec.md#requirement-oas-scope-generation-from-rbac-configuration
		 */
		async saveSettings() {
			await this.settingsStore.updateRbacSettings(this.rbacOptions)
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
