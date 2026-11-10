/**
 * ApexCharts options shared by the register and schema detail pages, which
 * draw the same audit-trail, distribution and object-size charts.
 */

/**
 * Options for the audit-trail actions line chart.
 *
 * @param {Array<string>} labels The dates along the x-axis
 * @param {string} themeMode The ApexCharts theme mode
 * @return {object} The chart options
 */
export function auditTrailChartOptions(labels, themeMode) {
	return {
		chart: {
			type: 'line',
			toolbar: { show: true },
			zoom: { enabled: true },
		},
		xaxis: {
			categories: labels,
			title: { text: 'Date' },
		},
		yaxis: { title: { text: 'Number of Actions' } },
		colors: ['#41B883', '#E46651', '#00D8FF'],
		stroke: { curve: 'smooth', width: 2 },
		legend: { position: 'top' },
		theme: { mode: themeMode },
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
		responsive: [
			{
				breakpoint: 480,
				options: {
					chart: { width: 200 },
					legend: { position: 'bottom' },
				},
			},
		],
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
		plotOptions: {
			bar: {
				horizontal: false,
				columnWidth: '55%',
				endingShape: 'rounded',
			},
		},
		xaxis: {
			categories: labels,
			title: { text: 'Size Range' },
		},
		yaxis: { title: { text: 'Number of Objects' } },
		fill: { opacity: 1 },
	}
}
