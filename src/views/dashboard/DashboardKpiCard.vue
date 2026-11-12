<template>
	<div class="kpi-card">
		<div class="kpi-icon">
			<slot name="icon" />
		</div>
		<div class="kpi-content">
			<span class="kpi-label">{{ label }}</span>
			<span class="kpi-value">{{ value.toLocaleString() }}</span>
			<span v-if="sub" class="kpi-sub">{{ sub }}</span>
		</div>
		<slot name="chip" />
	</div>
</template>

<script>
/**
 * A dashboard KPI: icon circle (left), then label, big value and optional sub.
 *
 * @spec exclude Presentational card shared by the dashboard's KPI widgets.
 */
export default {
	name: 'DashboardKpiCard',

	props: {
		label: { type: String, required: true },
		value: { type: Number, required: true },
		sub: { type: String, default: '' },
	},
}
</script>

<style scoped>
/* Bordered card treatment matching the shared finance KPI style. */
.kpi-card {
	position: relative;
	display: flex;
	align-items: center;
	gap: 16px;
	box-sizing: border-box;
	height: 100%;
	padding: 16px 20px;
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius-large, 10px);
}

.kpi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 48px;
	height: 48px;
	border-radius: 50%;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
}

.kpi-content {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.kpi-label {
	font-size: 13px;
	line-height: 1.3;
	color: var(--color-text-maxcontrast, #767676);
}

.kpi-value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.2;
	color: var(--color-primary-element, #0082c9);
}

.kpi-sub {
	font-size: 12px;
	line-height: 1.3;
	color: var(--color-text-maxcontrast, #767676);
}
</style>
