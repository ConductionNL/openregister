<template>
	<!-- Placeholder div -->
	<div>
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
		<DeleteApplication v-if="navigationStore.dialog === 'deleteApplication'" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore } from '../store/store.js'
</script>

<script>
import DeleteApplication from '../modals/application/DeleteApplication.vue'
import AuditTrailChanges from '../modals/logs/AuditTrailChanges.vue'
import AuditTrailDetails from '../modals/logs/AuditTrailDetails.vue'
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import DeleteAuditTrail from '../modals/logs/DeleteAuditTrail.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
		DeleteApplication,
	},
}
</script>
