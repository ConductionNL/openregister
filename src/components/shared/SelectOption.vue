<template>
	<div class="option-content">
		<span class="option-title">{{ title }}</span>
		<span v-if="description" class="option-description">{{ description }}</span>
		<slot />
	</div>
</template>

<script>
/**
 * One option of a select: its title, the description below it when there
 * is one, and whatever the slot adds.
 *
 * @spec exclude Presentational select option shared by the edit modals.
 */
export default {
	name: 'SelectOption',

	props: {
		title: { type: String, required: true },
		description: { type: String, default: '' },
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/components.scss' as *;
@include option-content-rules;
</style>
