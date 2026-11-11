/**
 * ApexCharts options shared by the register and schema detail pages, which
 * draw the same audit-trail, distribution and object-size charts. Every call
 * builds fresh objects, so no chart shares an options object with another.
 */

/**
 * An axis showing the given categories under a title.
 *
 * @param {Array<string>} labels The categories
 * @param {string} title The axis title
 * @return {object} The axis options
 */
function categoryAxis(labels, title) {
	return {
		categories: labels,
		title: { text: title },
	}
}

/**
 * An axis carrying only a title.
 *
 * @param {string} title The axis title
 * @return {object} The axis options
 */
function titledAxis(title) {
	return { title: { text: title } }
}

/**
 * A line chart with its toolbar shown and zooming enabled.
 *
 * @return {object} The chart options
 */
function zoomableLineChart() {
	return {
		type: 'line',
		toolbar: { show: true },
		zoom: { enabled: true },
	}
}

/**
 * Options for the audit-trail actions line chart.
 *
 * @param {Array<string>} labels The dates along the x-axis
 * @param {string} themeMode The ApexCharts theme mode
 * @return {object} The chart options
 */
export function auditTrailChartOptions(labels, themeMode) {
	return {
		chart: zoomableLineChart(),
		xaxis: categoryAxis(labels, 'Date'),
		yaxis: titledAxis('Number of Actions'),
		colors: ['#41B883', '#E46651', '#00D8FF'],
		stroke: { curve: 'smooth', width: 2 },
		legend: { position: 'top' },
		theme: { mode: themeMode },
	}
}

/**
 * The pie chart's narrow-screen variant: 200px wide, legend below.
 *
 * @return {object} The responsive breakpoint options
 */
function narrowScreenPie() {
	return {
		breakpoint: 480,
		options: {
			chart: { width: 200 },
			legend: { position: 'bottom' },
		},
	}
}

/**
 * Options for the objects-by-schema or objects-by-register pie chart.
 *
 * @param {Array<string>} labels The slice labels
 * @return {object} The chart options
 */
export function distributionChartOptions(labels) {
	return {
		chart: { type: 'pie' },
		labels,
		legend: { position: 'bottom' },
		responsive: [narrowScreenPie()],
	}
}

/**
 * Upright bars at 55% column width with rounded ends.
 *
 * @return {object} The plot options
 */
function roundedColumns() {
	return {
		bar: {
			horizontal: false,
			columnWidth: '55%',
			endingShape: 'rounded',
		},
	}
}

/**
 * Options for the objects-by-size bar chart.
 *
 * @param {Array<string>} labels The size ranges along the x-axis
 * @return {object} The chart options
 */
export function sizeChartOptions(labels) {
	return {
		chart: { type: 'bar' },
		plotOptions: roundedColumns(),
		xaxis: categoryAxis(labels, 'Size Range'),
		yaxis: titledAxis('Number of Objects'),
		fill: { opacity: 1 },
	}
}
