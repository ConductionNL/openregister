<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcModal label-id="View Object Audit Trail modal"
		@close="closeDialog">
		<div class="modal__content">
			<div class="audit-item">
				<h3>Audit Trail ID: {{ auditTrail.id }}</h3>

				<p><strong>Action:</strong> {{ auditTrail.action }}</p>
				<p><strong>User:</strong> {{ auditTrail.userName }} ({{ auditTrail.user }})</p>
				<p><strong>Session:</strong> {{ auditTrail.session }}</p>
				<p><strong>IP Address:</strong> {{ auditTrail.ipAddress }}</p>
				<p><strong>Created:</strong> {{ new Date(auditTrail.created).toLocaleString() }}</p>

				<div v-if="auditTrail.changed">
					<h4>Changes:</h4>
					<ul>
						<li v-for="(change, key) in auditTrail.changed" :key="key">
							<strong>{{ key }}:</strong>
							<span>Old: {{ change.old }}</span>
							<span>New: {{ change.new }}</span>
						</li>
					</ul>
				</div>

				<div class="navigation-buttons">
					<NcButton @click="navigateTo('schema', auditTrail.schema)">
						Go to Schema {{ auditTrail.schema }}
					</NcButton>
					<NcButton @click="navigateTo('register', auditTrail.register)">
						Go to Register {{ auditTrail.register }}
					</NcButton>
					<NcButton @click="navigateTo('object', auditTrail.object)">
						Go to Object {{ auditTrail.object }}
					</NcButton>
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
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'ViewObjectAuditTrail',
	components: {
		NcModal,
		NcButton,
		Cancel,
	},
	data() {
		return {
			auditTrail: {}, // Initialize with an empty object
		}
	},
	mounted() {
		// Assuming objectStore.auditTrailItem is a single audit trail object
		this.auditTrail = objectStore.auditTrailItem || {}
	},
	methods: {
		closeDialog() {
			navigationStore.setModal(null)
			objectStore.setAuditTrailItem(null)
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
	padding: 10px 0;
}

.navigation-buttons {
	margin-top: 10px;
}
</style>
