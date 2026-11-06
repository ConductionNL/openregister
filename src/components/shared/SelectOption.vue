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

<style scoped>
.option-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.option-title {
	font-weight: 500;
}

.option-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	max-width: 100%;
	white-space: normal;
	overflow-wrap: break-word;
}
</style>
