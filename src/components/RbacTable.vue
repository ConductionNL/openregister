<template>
	<div class="rbac-table-wrapper">
		<table class="rbac-table">
			<thead>
				<tr>
					<th scope="col">Group</th>
					<th :id="`${uid}-col-create`" scope="col">Create</th>
					<th :id="`${uid}-col-read`" scope="col">Read</th>
					<th :id="`${uid}-col-update`" scope="col">Update</th>
					<th :id="`${uid}-col-delete`" scope="col">Delete</th>
				</tr>
			</thead>
			<tbody>
				<!-- Public group at top -->
				<tr class="public-row">
					<td :id="`${uid}-row-public`" class="group-name">
						<span class="group-badge public">public</span>
						<small>Unauthenticated users</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('public', 'create')"
							:aria-labelledby="`${uid}-row-public ${uid}-col-create`"
							@update:modelValue="
								updatePermission('public', 'create', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('public', 'read')"
							:aria-labelledby="`${uid}-row-public ${uid}-col-read`"
							@update:modelValue="
								updatePermission('public', 'read', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('public', 'update')"
							:aria-labelledby="`${uid}-row-public ${uid}-col-update`"
							@update:modelValue="
								updatePermission('public', 'update', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('public', 'delete')"
							:aria-labelledby="`${uid}-row-public ${uid}-col-delete`"
							@update:modelValue="
								updatePermission('public', 'delete', $event)
							" />
					</td>
				</tr>

				<!-- Authenticated users group -->
				<tr class="user-row">
					<td :id="`${uid}-row-authenticated`" class="group-name">
						<span class="group-badge user">authenticated</span>
						<small>Authenticated users</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('authenticated', 'create')"
							:aria-labelledby="`${uid}-row-authenticated ${uid}-col-create`"
							@update:modelValue="
								updatePermission('authenticated', 'create', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('authenticated', 'read')"
							:aria-labelledby="`${uid}-row-authenticated ${uid}-col-read`"
							@update:modelValue="
								updatePermission('authenticated', 'read', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('authenticated', 'update')"
							:aria-labelledby="`${uid}-row-authenticated ${uid}-col-update`"
							@update:modelValue="
								updatePermission('authenticated', 'update', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission('authenticated', 'delete')"
							:aria-labelledby="`${uid}-row-authenticated ${uid}-col-delete`"
							@update:modelValue="
								updatePermission('authenticated', 'delete', $event)
							" />
					</td>
				</tr>

				<!-- Regular user groups -->
				<tr v-for="group in sortedGroups" :key="group.id" class="group-row">
					<td :id="`${uid}-row-${group.id}`" class="group-name">
						<span class="group-badge">{{ group.name }}</span>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission(group.id, 'create')"
							:aria-labelledby="`${uid}-row-${group.id} ${uid}-col-create`"
							@update:modelValue="
								updatePermission(group.id, 'create', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission(group.id, 'read')"
							:aria-labelledby="`${uid}-row-${group.id} ${uid}-col-read`"
							@update:modelValue="
								updatePermission(group.id, 'read', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission(group.id, 'update')"
							:aria-labelledby="`${uid}-row-${group.id} ${uid}-col-update`"
							@update:modelValue="
								updatePermission(group.id, 'update', $event)
							" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="hasPermission(group.id, 'delete')"
							:aria-labelledby="`${uid}-row-${group.id} ${uid}-col-delete`"
							@update:modelValue="
								updatePermission(group.id, 'delete', $event)
							" />
					</td>
				</tr>

				<!-- Admin group at bottom (disabled) -->
				<tr class="admin-row">
					<td :id="`${uid}-row-admin`" class="group-name">
						<span class="group-badge admin">admin</span>
						<small>Always has full access</small>
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="true"
							:disabled="true"
							:aria-labelledby="`${uid}-row-admin ${uid}-col-create`" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="true"
							:disabled="true"
							:aria-labelledby="`${uid}-row-admin ${uid}-col-read`" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="true"
							:disabled="true"
							:aria-labelledby="`${uid}-row-admin ${uid}-col-update`" />
					</td>
					<td>
						<NcCheckboxRadioSwitch
							:model-value="true"
							:disabled="true"
							:aria-labelledby="`${uid}-row-admin ${uid}-col-delete`" />
					</td>
				</tr>
			</tbody>
		</table>

		<div class="rbac-summary">
			<NcNoteCard v-if="!hasAnyPermissions" type="success">
				<p>
					<strong>Open Access:</strong> No specific permissions set - all
					organisation members can perform all operations.
				</p>
			</NcNoteCard>
			<NcNoteCard v-else-if="isRestrictive" type="warning">
				<p>
					<strong>Restrictive Access:</strong> Only specified groups can
					perform these operations.
				</p>
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'

/**
 * Per-instance id seed. RbacTable is rendered up to seven times on a single
 * page (EditOrganisation), so the ids that wire each permission checkbox to its
 * row and column header via aria-labelledby must not collide between instances.
 */
let rbacTableUid = 0

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
	data() {
		return {
			uid: `rbac-${++rbacTableUid}`,
		}
	},
	computed: {
		/**
		 * Get sorted groups (only showing groups assigned to the organisation)
		 *
		 * @return {Array} Sorted array of groups
		 * @spec exclude computed sorted/filtered group list for display, RBAC contract owned by rbac capability
		 */
		sortedGroups() {
			// If no organisation groups specified, show all available groups
			if (!this.organisationGroups || this.organisationGroups.length === 0) {
				return this.availableGroups
					.filter(
						(group) =>
							group.id !== 'admin'
							&& group.id !== 'public'
							&& group.id !== 'authenticated',
					)
					.sort((a, b) => a.name.localeCompare(b.name))
			}

			// Filter to only show groups that are assigned to the organisation
			return this.availableGroups
				.filter((group) => {
					// Exclude special groups
					if (
						group.id === 'admin'
						|| group.id === 'public'
						|| group.id === 'authenticated'
					) {
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
		 * @spec exclude computed read of authorization prop presence, RBAC contract owned by rbac capability
		 */
		hasAnyPermissions() {
			// For applications, authorization is flat (just {create: [], read: [], ...})
			// For organisations, authorization is nested ({register: {create: [], ...}, ...})
			let entityAuth
			if (this.authorization[this.entityType]) {
				// Nested structure (organisations)
				entityAuth = this.authorization[this.entityType]
			} else if (
				this.entityType === 'application'
				&& this.authorization.create !== undefined
			) {
				// Flat structure (applications)
				entityAuth = this.authorization
			} else {
				entityAuth = {}
			}

			return Object.keys(entityAuth).some(
				(action) =>
					Array.isArray(entityAuth[action])
					&& entityAuth[action].length > 0,
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
		 * @spec exclude permission-check read helper, RBAC contract owned by rbac capability
		 */
		hasPermission(groupId, action) {
			// For applications, authorization is flat (just {create: [], read: [], ...})
			// For organisations, authorization is nested ({register: {create: [], ...}, ...})
			let entityAuth
			if (this.authorization[this.entityType]) {
				// Nested structure (organisations)
				entityAuth = this.authorization[this.entityType]
			} else if (
				this.entityType === 'application'
				&& this.authorization.create !== undefined
			) {
				// Flat structure (applications)
				entityAuth = this.authorization
			} else {
				entityAuth = {}
			}

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
		 * @spec exclude emit update event to parent, RBAC contract owned by rbac capability
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
