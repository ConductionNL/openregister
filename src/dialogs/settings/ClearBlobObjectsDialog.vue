<template>
	<ClearTableDialog
		:open="open"
		:clearing="clearing"
		name="Confirm Clear Blob Objects"
		heading="Confirm Clear All Blob Storage Objects"
		description="This operation will permanently delete all objects stored in blob storage mode from the database. Magic Mapper objects will NOT be affected."
		:countText="`Current blob storage objects: ${totalBlobObjects}`"
		warning="⚠️ This action cannot be undone!"
		@closing="$emit('closing')"
		@confirm="$emit('confirm')" />
</template>

<script>
import ClearTableDialog from './ClearTableDialog.vue'

export default {
	name: 'ClearBlobObjectsDialog',
	components: { ClearTableDialog },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalBlobObjects: { type: Number, default: 0 },
	},

	emits: ['closing', 'confirm'],
}
</script>
