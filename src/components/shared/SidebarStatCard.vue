<template>
	<div class="statCard">
		<div class="statNumber">
			{{ value }}
		</div>
		<div class="statLabel">
			{{ label }}
		</div>
	</div>
</template>

<script>
/**
 * One statistic in a sidebar's statistics tab: a large number over its label.
 * The sidebar styles the card itself (`.statCard`); this component styles
 * the number and the label.
 *
 * @spec exclude Presentational statistic card; the sidebars own the numbers.
 */
export default {
	name: 'SidebarStatCard',

	props: {
		value: {
			type: [String, Number],
			default: '',
		},

		label: {
			type: String,
			required: true,
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/sidebars.scss' as *;

@include stat-number-rules;
</style>
