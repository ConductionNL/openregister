<template>
	<div class="rbac-table-wrapper">
		<table class="rbac-table">
			<thead>
				<tr>
					<th>Group</th>
					<th>Create</th>
					<th>Read</th>
					<th>Update</th>
					<th>Delete</th>
				</tr>
			</thead>
			<tbody>
				<!-- Public group at top -->
				<tr class="public-row">
					<td class="group-name">
						<span class="group-badge public">public</span>
						<small>Unauthenticated users</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('public', 'create')"
							@update:checked="updatePermission('public', 'create', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('public', 'read')"
							@update:checked="updatePermission('public', 'read', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('public', 'update')"
							@update:checked="updatePermission('public', 'update', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('public', 'delete')"
							@update:checked="updatePermission('public', 'delete', $event)" />
					</td>
				</tr>

				<!-- User group (authenticated users) -->
				<tr class="user-row">
					<td class="group-name">
						<span class="group-badge user">user</span>
						<small>Authenticated users</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('user', 'create')"
							@update:checked="updatePermission('user', 'create', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('user', 'read')"
							@update:checked="updatePermission('user', 'read', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('user', 'update')"
							@update:checked="updatePermission('user', 'update', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission('user', 'delete')"
							@update:checked="updatePermission('user', 'delete', $event)" />
					</td>
				</tr>

				<!-- Regular user groups -->
				<tr v-for="group in sortedGroups" :key="group.id" class="group-row">
					<td class="group-name">
						<span class="group-badge">{{ group.name }}</span>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission(group.id, 'create')"
							@update:checked="updatePermission(group.id, 'create', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission(group.id, 'read')"
							@update:checked="updatePermission(group.id, 'read', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission(group.id, 'update')"
							@update:checked="updatePermission(group.id, 'update', $event)" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="hasPermission(group.id, 'delete')"
							@update:checked="updatePermission(group.id, 'delete', $event)" />
					</td>
				</tr>

				<!-- Admin group at bottom (disabled) -->
				<tr class="admin-row">
					<td class="group-name">
						<span class="group-badge admin">admin</span>
						<small>Always has full access</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="true"
							:disabled="true" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="true"
							:disabled="true" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="true"
							:disabled="true" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="true"
							:disabled="true" />
					</td>
				</tr>
			</tbody>
		</table>

		<div class="rbac-summary">
			<NcNoteCard v-if="!hasAnyPermissions" type="success">
				<p><strong>Open Access:</strong> No specific permissions set - all organisation members can perform all operations.</p>
			</NcNoteCard>
			<NcNoteCard v-else-if="isRestrictive" type="warning">
				<p><strong>Restrictive Access:</strong> Only specified groups can perform these operations.</p>
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'RbacTable',
	components: {
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},
	props: {
		/**
		 * The entity type (register, schema, object, view, agent)
		 */
		entityType: {
			type: String,
			required: true,
		},
		/**
		 * The authorization object from the organisation
		 */
		authorization: {
			type: Object,
			required: true,
		},
		/**
		 * Available Nextcloud groups
		 */
		availableGroups: {
			type: Array,
			required: true,
		},
		/**
		 * Groups assigned to the organisation (used to filter display)
		 */
		organisationGroups: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * Get sorted groups (only showing groups assigned to the organisation)
		 * 
		 * @return {Array} Sorted array of groups
		 */
		sortedGroups() {
			// If no organisation groups specified, show all available groups
			if (!this.organisationGroups || this.organisationGroups.length === 0) {
				return this.availableGroups
					.filter(group => group.id !== 'admin' && group.id !== 'public' && group.id !== 'user')
					.sort((a, b) => a.name.localeCompare(b.name))
			}

			// Filter to only show groups that are assigned to the organisation
			return this.availableGroups
				.filter(group => {
					// Exclude special groups
					if (group.id === 'admin' || group.id === 'public' || group.id === 'user') {
						return false
					}
					// Only include groups that are in the organisation's groups list
					return this.organisationGroups.includes(group.id)
				})
				.sort((a, b) => a.name.localeCompare(b.name))
		},

		/**
		 * Check if any permissions are set for this entity type
		 *
		 * @return {boolean} True if permissions are set
		 */
		hasAnyPermissions() {
			const entityAuth = this.authorization[this.entityType] || {}
			return Object.keys(entityAuth).some(action =>
				Array.isArray(entityAuth[action]) && entityAuth[action].length > 0,
			)
		},

		/**
		 * Check if access is restrictive (has specific permissions)
		 *
		 * @return {boolean} True if restrictive
		 */
		isRestrictive() {
			return this.hasAnyPermissions
		},
	},
	methods: {
		/**
		 * Check if a group has a specific permission
		 *
		 * @param {string} groupId - The group ID
		 * @param {string} action - The action (create, read, update, delete)
		 * @return {boolean} True if group has permission
		 */
		hasPermission(groupId, action) {
			const entityAuth = this.authorization[this.entityType] || {}
			if (!entityAuth[action] || !Array.isArray(entityAuth[action])) {
				return false
			}
			return entityAuth[action].includes(groupId)
		},

		/**
		 * Update a permission for a group
		 *
		 * @param {string} groupId - The group ID
		 * @param {string} action - The action (create, read, update, delete)
		 * @param {boolean} hasPermission - Whether to grant or revoke permission
		 * @return {void}
		 */
		updatePermission(groupId, action, hasPermission) {
			this.$emit('update', {
				entityType: this.entityType,
				groupId,
				action,
				hasPermission,
			})
		},
	},
}
</script>

<style scoped>
.rbac-table-wrapper {
	margin-top: 16px;
}

.rbac-table {
	width: 100%;
	border-collapse: collapse;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.rbac-table th {
	background: var(--color-background-dark);
	color: var(--color-text-dark);
	font-weight: 600;
	padding: 12px 16px;
	text-align: left;
	border-bottom: 2px solid var(--color-border-dark);
}

.rbac-table th:first-child {
	width: 40%;
}

.rbac-table th:not(:first-child) {
	width: 15%;
	text-align: center;
}

.rbac-table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.rbac-table td:not(.group-name) {
	text-align: center;
}

.rbac-table tr:hover {
	background: var(--color-background-hover);
}

.public-row {
	background: var(--color-primary-light) !important;
}

.user-row {
	background: var(--color-warning-light) !important;
}

.admin-row {
	background: var(--color-success-light) !important;
}

.admin-row:hover {
	background: var(--color-success-light) !important;
}

.group-name {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.group-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.group-badge.public {
	background: var(--color-info);
	color: white;
}

.group-badge.user {
	background: var(--color-warning);
	color: white;
}

.group-badge.admin {
	background: var(--color-success);
	color: white;
}

.group-name small {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.rbac-summary {
	margin-top: 16px;
}
</style>
