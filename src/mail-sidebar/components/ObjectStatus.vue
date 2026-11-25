<template>
	<span v-if="status || handler" class="or-object-status">
		<span v-if="status" class="or-object-status__dot" aria-hidden="true" />
		<span v-if="status">{{ status }}</span>
		<span v-if="status && handler" aria-hidden="true">·</span>
		<span v-if="handler">{{ handler }}</span>
	</span>
</template>

<script>
/**
 * An object's status and handler on one muted line, after a ring.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
export default {
	name: 'ObjectStatus',
	props: {
		status: { type: String, default: '' },
		handler: { type: String, default: '' },
	},
}
</script>
