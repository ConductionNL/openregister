<template>
	<NcSettingsSection name="Role Based Access Control (RBAC)">
		<template #description>
			Configure access permissions and user groups
		</template>

		<div v-if="!loading" class="rbac-options">
			<!-- Save and Rebase Buttons -->
			<div class="section-header-inline">
				<span />
				<div class="button-group">
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
				</div>
			</div>

			<!-- Section Description -->
			<div class="section-description-full">
				<p class="main-description">
					Role Based Access Control (RBAC) allows you to control who can access and modify different parts of your Open Register.
					When enabled, users are assigned to specific Nextcloud groups that determine their permissions for registers, schemas, and objects.
					Note: This system uses Nextcloud's built-in group functionality rather than separate roles.
				</p>
				<p class="toggle-status">
					<strong>Current Status:</strong>
					<span :class="rbacOptions.enabled ? 'status-enabled' : 'status-disabled'">
						{{ rbacOptions.enabled ? 'Role Based Access Control enabled' : 'Role Based Access Control disabled' }}
					</span>
				</p>
				<p class="impact-description">
					<strong>{{ rbacOptions.enabled ? 'Disabling' : 'Enabling' }} RBAC will:</strong><br>
					<span v-if="!rbacOptions.enabled">
						• Provide fine-grained access control over registers and schemas<br>
						• Allow you to assign users to specific Nextcloud groups (Viewer, Editor, Admin)<br>
						• Enable secure multi-user environments with proper permission boundaries<br>
						• Require group assignment for new users accessing the system
					</span>
					<span v-else>
						• Remove all group-based restrictions and permissions<br>
						• Grant all users full access to all registers and schemas<br>
						• Simplify user management but reduce security controls<br>
						• Allow unrestricted access to sensitive data and configurations
					</span>
				</p>
			</div>

			<!-- Enable RBAC Toggle -->
			<div class="option-section">
				<NcCheckboxRadioSwitch
					:checked.sync="rbacOptions.enabled"
					:disabled="saving"
					type="switch">
					{{ rbacOptions.enabled ? 'Role Based Access Control enabled' : 'Role Based Access Control disabled' }}
				</NcCheckboxRadioSwitch>

				<!-- Admin Override -->
				<div v-if="rbacOptions.enabled">
					<NcCheckboxRadioSwitch
						:checked.sync="rbacOptions.adminOverride"
						:disabled="saving"
						type="switch">
						{{ rbacOptions.adminOverride ? 'Admin override enabled' : 'Admin override disabled' }}
					</NcCheckboxRadioSwitch>
					<p class="option-description">
						Allow administrators to bypass all RBAC restrictions
					</p>

					<h4>Default User Groups</h4>
					<p class="option-description">
						Configure which Nextcloud groups different types of users are assigned to by default
					</p>

					<div class="groups-table">
						<div class="groups-row">
							<div class="group-label">
								<strong>Anonymous Users</strong>
								<p class="user-type-description">
									Unidentified, non-logged-in users who access public content without authentication
								</p>
							</div>
							<div class="group-select">
								<NcSelect
									v-model="rbacOptions.anonymousGroup"
									:options="groupOptions"
									input-label="Anonymous Group"
									:disabled="loading || saving" />
							</div>
						</div>

						<div class="groups-row">
							<div class="group-label">
								<strong>Default New Users</strong>
								<p class="user-type-description">
									Authenticated users who have logged in but haven't been assigned to specific groups yet
								</p>
							</div>
							<div class="group-select">
								<NcSelect
									v-model="rbacOptions.defaultNewUserGroup"
									:options="groupOptions"
									input-label="New User Group"
									:disabled="loading || saving" />
							</div>
						</div>

						<div class="groups-row">
							<div class="group-label">
								<strong>Default Object Owner</strong>
								<p class="user-type-description">
									Default user assigned as owner when creating new objects without explicit ownership
								</p>
							</div>
							<div class="group-select">
								<NcSelect
									v-model="rbacOptions.defaultObjectOwner"
									:options="userOptions"
									input-label="Default Owner"
									:disabled="loading || saving" />
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Loading State -->
		<NcLoadingIcon v-else
			class="loading-icon"
			:size="64"
			appearance="dark" />
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'

export default {
	name: 'RbacConfiguration',

	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		Refresh,
		Save,
	},

	computed: {
		...mapStores(useSettingsStore),

		rbacOptions: {
			get() {
				return this.settingsStore.rbacOptions
			},
			set(value) {
				this.settingsStore.rbacOptions = value
			},
		},

		groupOptions() {
			return this.settingsStore.groupOptions
		},

		userOptions() {
			return this.settingsStore.userOptions
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
			await this.settingsStore.updateRbacSettings(this.rbacOptions)
		},
	},
}
</script>

<style scoped>
.rbac-options {
	margin-top: 20px;
}

.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
}

.button-group {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

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
		flex-direction: column;
		gap: 12px;
		align-items: stretch;
	}

	.button-group {
		justify-content: center;
	}
}
</style>
