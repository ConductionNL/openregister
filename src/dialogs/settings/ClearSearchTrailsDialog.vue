<template>
	<ClearTableDialog
		:open="open"
		:clearing="clearing"
		name="Confirm Clear Search Trails"
		heading="Confirm Clear All Search Trails"
		description="This operation will permanently delete all search trail logs from the database. This action cannot be undone."
		:countText="`Current search trails: ${totalSearchTrails}`"
		@closing="$emit('closing')"
		@confirm="$emit('confirm')" />
</template>

<script>
import ClearTableDialog from './ClearTableDialog.vue'

export default {
	name: 'ClearSearchTrailsDialog',
	components: { ClearTableDialog },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		totalSearchTrails: { type: Number, default: 0 },
	},

	emits: ['closing', 'confirm'],
}
</script>
