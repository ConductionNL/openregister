<script setup>
import { objectStore, navigationStore, schemaStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcModal label-id="View Object Audit Trail modal"
		@close="closeDialog">
		<div class="modal__content">
			<div class="audit-item">
				<h3>Audit Trail ID: {{ auditTrail.id }}</h3>

				<div class="audit-item-details">
					<p><strong>Action:</strong> {{ auditTrail.action }}</p>
					<p><strong>User:</strong> {{ auditTrail.userName }} ({{ auditTrail.user }})</p>
					<p><strong>Session:</strong> {{ auditTrail.session }}</p>
					<p><strong>IP Address:</strong> {{ auditTrail.ipAddress }}</p>
					<p><strong>Created:</strong> {{ new Date(auditTrail.created).toLocaleString() }}</p>
				</div>

				<div v-if="auditTrail.changed" class="audit-trail-changes">
					<h4>Changes:</h4>
					<div class="audit-trail-table-container">
						<table class="audit-trail-table">
							<thead>
								<tr>
									<th>Field</th>
									<th>Old Value</th>
									<th>New Value</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(change, key) in auditTrail.changed" :key="key">
									<td><strong>{{ key }}</strong></td>
									<td>{{ formatValue(change.old) }}</td>
									<td>{{ formatValue(change.new) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="audit-trail-linked-items">
					<h4>Linked Items:</h4>
					<div class="audit-trail-linked-items-container">
						<div>
							<p><b>Schema:</b> {{ schemaLoading ? 'Loading...' : schemaItem?.title }}</p>
							<NcButton type="tertiary"
								aria-label="Go to linked Schema"
								@click="goToSchema">
								<template #icon>
									<NcLoadingIcon v-if="schemaLoading" />
									<OpenInApp v-else :size="20" />
								</template>
							</NcButton>
						</div>

						<div>
							<p><b>Register:</b> {{ registerLoading ? 'Loading...' : registerItem?.title }}</p>
							<NcButton type="tertiary"
								aria-label="Go to linked Register"
								@click="goToRegister">
								<template #icon>
									<NcLoadingIcon v-if="registerLoading" />
									<OpenInApp v-else :size="20" />
								</template>
							</NcButton>
						</div>
					</div>
				</div>
			</div>

			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import {
	NcModal,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'

export default {
	name: 'ViewObjectAuditTrail',
	components: {
		NcModal,
		NcButton,
		Cancel,
	},
	data() {
		return {
			schemaItem: null,
			schemaLoading: false,
			registerItem: null,
			registerLoading: false,
			auditTrail: {}, // Initialize with an empty object
		}
	},
	mounted() {
		// Assuming objectStore.auditTrailItem is a single audit trail object
		this.auditTrail = objectStore.auditTrailItem || {}
		this.fetchSchema()
		this.fetchRegister()
	},
	methods: {
		closeDialog() {
			navigationStore.setModal(null)
			objectStore.setAuditTrailItem(null)
		},
		formatValue(value) {
			if (value === null || value === undefined) {
				return 'N/A' // Handle null or undefined
			} else if (typeof value === 'object') {
				return JSON.stringify(value, null, 2) // Format JSON objects
			} else if (typeof value === 'boolean') {
				return value ? 'True' : 'False' // Format booleans
			}
			return value // Return the value as is for other types
		},
		fetchSchema() {
			this.schemaLoading = true
			schemaStore.getSchema(this.auditTrail.schema)
				.then((schema) => {
					this.schemaItem = schema
					this.schemaLoading = false
				})
		},
		fetchRegister() {
			this.registerLoading = true
			registerStore.getRegister(this.auditTrail.register)
				.then((register) => {
					this.registerItem = register
					this.registerLoading = false
				})
		},
		goToSchema() {
			navigationStore.setModal(null)
			objectStore.setAuditTrailItem(null)
			navigationStore.setSelected('schemas')
			schemaStore.setSchemaItem(this.schemaItem)
		},
		goToRegister() {
			navigationStore.setModal(null)
			objectStore.setAuditTrailItem(null)
			navigationStore.setSelected('registers')
			registerStore.setRegisterItem(this.registerItem)
		},
	},
}
</script>

<style scoped>
.modal__content {
	margin: 0.8rem;
}

.audit-item {
	border-bottom: 1px solid #ccc;
	padding: 0 0 10px 0;
	margin: 0 0 10px 0;
}
.audit-item > *:not(:last-child) {
	margin-bottom: 1rem;
}

.navigation-buttons {
	margin-top: 10px;
	display: flex;
	gap: 10px;
	justify-content: center;
}

/* Changes Table */
.audit-trail-table-container {
	max-height: 350px;
	overflow-y: auto;
}

.audit-trail-table thead {
	position: sticky;
	top: 0;
	background-color: var(--color-main-background);
}

.audit-trail-table th {
	font-weight: bold;
	font-size: 1rem;
}

.audit-trail-table th,
.audit-trail-table td {
	padding: 0.5rem;
}

/* Linked Items */
.audit-trail-linked-items-container > div {
	display: flex;
	justify-content: center;
	gap: 1rem;
}

.audit-trail-linked-items-container p {
	line-height: 2;
    text-align: right;
}
</style>
