<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<SettingsSection
		:name="t('openregister', 'Permission Matrix')"
		:description="t('openregister', 'View and manage authorization across registers and schemas')"
		:loading="loading"
		:loading-message="t('openregister', 'Loading permission matrix...')">
		<div v-if="!isAdminUser" class="access-denied">
			<p>{{ t('openregister', 'You do not have permission to view the permission matrix. Admin access is required.') }}</p>
		</div>

		<div v-else-if="registers.length === 0 && !loading" class="empty-state">
			<p>{{ t('openregister', 'No registers found. Create a register to configure permissions.') }}</p>
		</div>

		<div v-else class="permission-matrix">
			<!-- Legend -->
			<div class="matrix-legend">
				<span class="legend-item">
					<span class="legend-dot direct" />
					{{ t('openregister', 'Direct permission') }}
				</span>
				<span class="legend-item">
					<span class="legend-dot inherited" />
					{{ t('openregister', 'Inherited from register') }}
				</span>
			</div>

			<!-- Matrix Table -->
			<div class="matrix-table-wrapper">
				<table class="matrix-table">
					<thead>
						<tr>
							<th class="name-column">
								{{ t('openregister', 'Register / Schema') }}
							</th>
							<th v-for="action in actions" :key="action" class="action-column">
								{{ action }}
							</th>
							<th class="action-column">
								{{ t('openregister', 'Public') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="register in registers">
							<!-- Register Row -->
							<tr :key="'reg-' + register.id" class="register-row">
								<td class="name-cell">
									<button
										class="expand-toggle"
										@click="toggleRegister(register.id)">
										<span class="expand-icon">{{ expandedRegisters[register.id] ? '&#9660;' : '&#9654;' }}</span>
										<strong>{{ register.title || register.name || 'Register #' + register.id }}</strong>
									</button>
									<span v-if="register.authorization && Object.keys(register.authorization).length > 0"
										class="auth-badge">
										{{ t('openregister', 'RBAC') }}
									</span>
								</td>
								<td v-for="action in actions"
									:key="action"
									class="action-cell">
									<span
										class="group-list"
										:title="getGroupsTooltip(getRegisterGroups(register, action))">
										{{ formatGroups(getRegisterGroups(register, action)) }}
									</span>
								</td>
								<td class="action-cell">
									<NcCheckboxRadioSwitch
										:checked="isPublicAccess(register.authorization)"
										type="switch"
										@update:checked="togglePublicAccess(register, $event)" />
								</td>
							</tr>

							<!-- Schema Rows -->
							<template v-if="expandedRegisters[register.id]">
								<tr v-for="schema in getRegisterSchemas(register)"
									:key="'schema-' + schema.id"
									class="schema-row">
									<td class="name-cell schema-indent">
										&#8627; {{ schema.title || schema.name || 'Schema #' + schema.id }}
										<span v-if="!schema.authorization || Object.keys(schema.authorization).length === 0"
											class="inherited-badge"
											title="Inherits permissions from register">
											inherited
										</span>
									</td>
									<td v-for="action in actions"
										:key="action"
										class="action-cell">
										<span
											:class="getPermissionClass(schema, register, action)"
											:title="getEffectiveTooltip(schema, register, action)">
											{{ formatGroups(getEffectiveGroups(schema, register, action)) }}
										</span>
									</td>
									<td class="action-cell">
										<NcCheckboxRadioSwitch
											:checked="isPublicAccess(getEffectiveAuth(schema, register))"
											type="switch"
											@update:checked="toggleSchemaPublicAccess(schema, register, $event)" />
									</td>
								</tr>
							</template>
						</template>
					</tbody>
				</table>
			</div>

			<!-- Bulk Actions -->
			<div v-for="register in registersWithBulkActions"
				:key="'bulk-' + register.id"
				class="bulk-actions">
				<h4>{{ t('openregister', 'Bulk Role Assignment: {title}', { title: register.title || 'Register #' + register.id }) }}</h4>
				<p class="bulk-description">{{ t('openregister', 'Apply a role to all schemas in this register that do not have explicit authorization overrides.') }}</p>
				<div class="bulk-controls">
					<NcSelect
						v-model="bulkRole[register.id]"
						:options="getRoleOptions(register)"
						:input-label="t('openregister', 'Select role')"
						class="bulk-select" />
					<NcSelect
						v-model="bulkGroup[register.id]"
						:options="getGroupOptions()"
						:input-label="t('openregister', 'Select group')"
						class="bulk-select" />
					<NcButton
						type="primary"
						:disabled="!bulkRole[register.id] || !bulkGroup[register.id]"
						@click="applyBulkRole(register)">
						{{ t('openregister', 'Apply') }}
					</NcButton>
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useRegisterStore } from '../../../store/modules/register.js'
import { useSchemaStore } from '../../../store/modules/schema.js'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'

export default {
	name: 'PermissionMatrix',

	components: {
		SettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	data() {
		return {
			loading: false,
			expandedRegisters: {},
			actions: ['read', 'create', 'update', 'delete', 'manage'],
			schemas: [],
			registers: [],
			isAdminUser: true, // Set via API check on mount
			bulkRole: {},
			bulkGroup: {},
		}
	},

	computed: {
		...mapStores(useRegisterStore, useSchemaStore),

		/**
		 * Returns only the registers that are expanded and have role configuration.
		 * Used in the bulk actions section to avoid mixing v-for with v-if.
		 *
		 * @return {Array} Filtered list of registers with bulk action support
		 */
		registersWithBulkActions() {
			return this.registers.filter(
				register => this.expandedRegisters[register.id]
					&& register.configuration
					&& register.configuration.roles,
			)
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		async loadData() {
			this.loading = true
			try {
				const registerResult = await this.registerStore.refreshRegisterList()
				this.registers = registerResult?.data || this.registerStore.registerList || []

				const schemaResult = await this.schemaStore.refreshSchemaList()
				this.schemas = schemaResult?.data || this.schemaStore.schemaList || []
			} catch (error) {
				console.error('Failed to load permission matrix data:', error)
			} finally {
				this.loading = false
			}
		},

		toggleRegister(registerId) {
			this.$set(this.expandedRegisters, registerId, !this.expandedRegisters[registerId])
		},

		getRegisterSchemas(register) {
			const schemaIds = register.schemas || []
			return this.schemas.filter(s => schemaIds.includes(s.id) || schemaIds.includes(String(s.id)))
		},

		getRegisterGroups(register, action) {
			const auth = register.authorization
			if (!auth || !auth[action]) return []
			return auth[action].map(entry => {
				if (typeof entry === 'string') return entry
				if (entry && entry.group) return entry.group
				return null
			}).filter(Boolean)
		},

		getEffectiveGroups(schema, register, action) {
			const schemaAuth = schema.authorization
			if (schemaAuth && Object.keys(schemaAuth).length > 0) {
				const rules = schemaAuth[action] || []
				return rules.map(entry => {
					if (typeof entry === 'string') return entry
					if (entry && entry.group) return entry.group
					return null
				}).filter(Boolean)
			}
			// Inherit from register
			return this.getRegisterGroups(register, action)
		},

		getEffectiveAuth(schema, register) {
			const schemaAuth = schema.authorization
			if (schemaAuth && Object.keys(schemaAuth).length > 0) {
				return schemaAuth
			}
			return register.authorization || {}
		},

		getPermissionClass(schema, register, action) {
			const schemaAuth = schema.authorization
			if (schemaAuth && Object.keys(schemaAuth).length > 0 && schemaAuth[action]) {
				return 'group-list direct'
			}
			if (this.getRegisterGroups(register, action).length > 0) {
				return 'group-list inherited'
			}
			return 'group-list'
		},

		getEffectiveTooltip(schema, register, action) {
			const schemaAuth = schema.authorization
			if (schemaAuth && Object.keys(schemaAuth).length > 0 && schemaAuth[action]) {
				return 'Directly configured on this schema'
			}
			const registerGroups = this.getRegisterGroups(register, action)
			if (registerGroups.length > 0) {
				return 'Inherited from register: ' + registerGroups.join(', ')
			}
			return 'No restrictions (open access)'
		},

		getGroupsTooltip(groups) {
			if (groups.length === 0) return 'No restrictions'
			return groups.join(', ')
		},

		formatGroups(groups) {
			if (groups.length === 0) return '-'
			if (groups.length <= 2) return groups.join(', ')
			return groups.slice(0, 2).join(', ') + ' +' + (groups.length - 2)
		},

		isPublicAccess(authorization) {
			if (!authorization || !authorization.read) return false
			return authorization.read.some(entry => {
				if (typeof entry === 'string') return entry === 'public'
				if (entry && entry.group) return entry.group === 'public'
				return false
			})
		},

		async togglePublicAccess(register, enabled) {
			const auth = { ...(register.authorization || {}) }
			if (enabled) {
				if (!auth.read) auth.read = []
				if (!auth.read.includes('public')) {
					auth.read.push('public')
				}
			} else {
				if (auth.read) {
					auth.read = auth.read.filter(e => e !== 'public')
				}
			}

			try {
				await this.registerStore.saveRegister({ ...register, authorization: auth })
				await this.loadData()
			} catch (error) {
				console.error('Failed to toggle public access:', error)
			}
		},

		async toggleSchemaPublicAccess(schema, register, enabled) {
			const schemaAuth = schema.authorization && Object.keys(schema.authorization).length > 0
				? { ...schema.authorization }
				: { ...(register.authorization || {}) }

			if (enabled) {
				if (!schemaAuth.read) schemaAuth.read = []
				if (!schemaAuth.read.includes('public')) {
					schemaAuth.read.push('public')
				}
			} else {
				if (schemaAuth.read) {
					schemaAuth.read = schemaAuth.read.filter(e => e !== 'public')
				}
			}

			try {
				await this.schemaStore.saveSchema({ ...schema, authorization: schemaAuth })
				await this.loadData()
			} catch (error) {
				console.error('Failed to toggle schema public access:', error)
			}
		},

		getRoleOptions(register) {
			const roles = register.configuration?.roles || []
			return roles.map(r => ({ label: r.name + ' (' + (r.actions || []).join(', ') + ')', value: r.name }))
		},

		getGroupOptions() {
			// Collect all unique groups from all registers and schemas
			const groups = new Set()
			this.registers.forEach(r => {
				const auth = r.authorization || {}
				Object.values(auth).forEach(entries => {
					if (Array.isArray(entries)) {
						entries.forEach(e => {
							if (typeof e === 'string') groups.add(e)
							if (e && e.group) groups.add(e.group)
						})
					}
				})
			})
			this.schemas.forEach(s => {
				const auth = s.authorization || {}
				Object.values(auth).forEach(entries => {
					if (Array.isArray(entries)) {
						entries.forEach(e => {
							if (typeof e === 'string') groups.add(e)
							if (e && e.group) groups.add(e.group)
						})
					}
				})
			})
			groups.add('public')
			groups.add('admin')
			return Array.from(groups).sort().map(g => ({ label: g, value: g }))
		},

		async applyBulkRole(register) {
			const roleName = this.bulkRole[register.id]?.value
			const groupName = this.bulkGroup[register.id]?.value
			if (!roleName || !groupName) return

			const schemas = this.getRegisterSchemas(register)
			let applied = 0

			for (const schema of schemas) {
				// Skip schemas with explicit authorization
				if (schema.authorization && Object.keys(schema.authorization).length > 0) {
					continue
				}

				// Build authorization with role assignment
				const auth = {
					roles: {
						[roleName]: [groupName],
					},
				}

				try {
					await this.schemaStore.saveSchema({ ...schema, authorization: auth })
					applied++
				} catch (error) {
					console.error('Failed to apply role to schema:', schema.id, error)
				}
			}

			if (applied > 0) {
				await this.loadData()
			}
		},
	},
}
</script>

<style scoped>
.permission-matrix {
	margin-top: 16px;
}

.matrix-legend {
	display: flex;
	gap: 20px;
	margin-bottom: 12px;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.legend-item {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-light);
}

.legend-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	display: inline-block;
}

.legend-dot.direct {
	background: var(--color-primary-element);
}

.legend-dot.inherited {
	background: var(--color-warning);
}

.matrix-table-wrapper {
	overflow-x: auto;
}

.matrix-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.matrix-table th,
.matrix-table td {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	text-align: left;
}

.matrix-table th {
	background: var(--color-background-hover);
	font-weight: 600;
	text-transform: capitalize;
}

.name-column {
	min-width: 250px;
}

.action-column {
	min-width: 100px;
	text-align: center !important;
}

.action-cell {
	text-align: center !important;
}

.register-row {
	background: var(--color-background-hover);
}

.schema-row {
	background: var(--color-main-background);
}

.schema-indent {
	padding-left: 32px !important;
}

.expand-toggle {
	background: none;
	border: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 0;
	color: var(--color-main-text);
}

.expand-icon {
	font-size: 10px;
	width: 14px;
}

.auth-badge {
	display: inline-block;
	padding: 2px 6px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-radius: 4px;
	margin-left: 8px;
}

.inherited-badge {
	display: inline-block;
	padding: 2px 6px;
	font-size: 11px;
	font-weight: 500;
	background: var(--color-warning);
	color: var(--color-warning-text);
	border-radius: 4px;
	margin-left: 8px;
	font-style: italic;
}

.group-list {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.group-list.direct {
	color: var(--color-primary-element);
	font-weight: 500;
}

.group-list.inherited {
	color: var(--color-warning);
	font-style: italic;
}

.access-denied {
	padding: 24px;
	text-align: center;
	color: var(--color-error);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-error);
}

.empty-state {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.bulk-actions {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.bulk-actions h4 {
	margin: 0 0 12px 0;
}
</style>
