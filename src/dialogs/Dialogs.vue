<template>
	<!-- Placeholder div -->
	<div>
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
		<PermanentlyDeleteObject />
		<PermanentlyDeleteMultiple />
	</div>
</template>

<script>
import DeleteAuditTrail from '../modals/logs/DeleteAuditTrail.vue'
import AuditTrailDetails from '../modals/logs/AuditTrailDetails.vue'
import AuditTrailChanges from '../modals/logs/AuditTrailChanges.vue'
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import PermanentlyDeleteObject from '../modals/deleted/PermanentlyDeleteObject.vue'
import PermanentlyDeleteMultiple from '../modals/deleted/PermanentlyDeleteMultiple.vue'

export default {
	name: 'Dialogs',
	components: {
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
		PermanentlyDeleteObject,
		PermanentlyDeleteMultiple,
	},
}
</script>
