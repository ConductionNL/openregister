<template>
	<!-- Placeholder div -->
	<div>
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
		<PurgeMultiple />
	</div>
</template>

<script>
import DeleteAuditTrail from '../modals/logs/DeleteAuditTrail.vue'
import AuditTrailDetails from '../modals/logs/AuditTrailDetails.vue'
import AuditTrailChanges from '../modals/logs/AuditTrailChanges.vue'
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import PurgeMultiple from '../modals/deleted/PurgeMultiple.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
		PurgeMultiple,
	},
}
</script>
