<template>
	<ClearTableDialog
		:open="open"
		:clearing="clearing"
		name="Confirm Clear Audit Trails"
		heading="Confirm Clear All Audit Trails"
		description="This operation will permanently delete all audit trail logs from the database. This action cannot be undone."
		:countText="`Current audit trails: ${totalAuditTrails}`"
		@closing="$emit('closing')"
		@confirm="$emit('confirm')" />
</template>

<script>
import ClearTableDialog from './ClearTableDialog.vue'

export default {
	name: 'ClearAuditTrailsDialog',
	components: { ClearTableDialog },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalAuditTrails: { type: Number, default: 0 },
	},

	emits: ['closing', 'confirm'],
}
</script>
