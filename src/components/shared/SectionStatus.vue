<template>
	<p
		v-if="message"
		:class="{ section__error: isError, section__success: !isError }">
		{{ message }}
	</p>
</template>

<script>
/**
 * The status line of an account section. Styled by the parent section's
 * section-error rules, which reach this root element.
 *
 * @spec exclude Presentational status message.
 */
export default {
	name: 'SectionStatus',
	props: {
		message: { type: String, default: '' },
		isError: { type: Boolean, default: false },
	},
}
</script>
