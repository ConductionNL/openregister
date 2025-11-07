<template>
	<!-- Placeholder div -->
	<div>
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
		<CreateConfigSetDialog @configset-created="onConfigSetCreated" />
		<DeleteConfigSetDialog @configset-deleted="onConfigSetDeleted" />
		<DeleteApplication v-if="navigationStore.dialog === 'deleteApplication'" />
	</div>
</template>

<script setup>
import { navigationStore } from '../store/store.js'
</script>

<script>
import DeleteAuditTrail from '../modals/logs/DeleteAuditTrail.vue'
import AuditTrailDetails from '../modals/logs/AuditTrailDetails.vue'
import AuditTrailChanges from '../modals/logs/AuditTrailChanges.vue'
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import CreateConfigSetDialog from '../modals/settings/CreateConfigSetDialog.vue'
import DeleteConfigSetDialog from '../modals/settings/DeleteConfigSetDialog.vue'
import DeleteApplication from '../modals/application/DeleteApplication.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
		CreateConfigSetDialog,
		DeleteConfigSetDialog,
		DeleteApplication,
	},
	methods: {
		onConfigSetCreated() {
			// Emit event to reload ConfigSets list if needed
			this.$root.$emit('configset-updated')
		},
		onConfigSetDeleted() {
			// Emit event to reload ConfigSets list if needed
			this.$root.$emit('configset-updated')
		},
	},
}
</script>
