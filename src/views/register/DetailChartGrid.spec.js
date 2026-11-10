import { shallowMount } from '@vue/test-utils'
import DetailChartGrid from './DetailChartGrid.vue'

jest.mock('vue3-apexcharts', () => ({
	name: 'VueApexCharts',
	props: ['type', 'height', 'options', 'series', 'labels'],
	template: '<div />',
}))

const OPTIONS = {
	auditOptions: { id: 'audit' },
	distributionOptions: { id: 'pie' },
	sizeOptions: { id: 'size' },
}

const mountGrid = (chartData) => shallowMount(DetailChartGrid, {
	props: {
		...OPTIONS,
		chartData,
		distributionKey: 'objectsBySchema',
		distributionTitle: 'Objects by Schema',
	},
})

const chartProps = (wrapper) => wrapper
	.findAllComponents({ name: 'VueApexCharts' })
	.map((chart) => chart.props())

describe('DetailChartGrid', () => {
	it('feeds each chart its options and series', () => {
		const wrapper = mountGrid({
			auditTrailActions: { series: [{ name: 'create', data: [1] }] },
			objectsBySchema: { series: [3, 4], labels: ['A', 'B'] },
			objectsBySize: { series: [6, 7] },
		})

		expect(wrapper.findAll('h3').map((h) => h.text())).toEqual([
			'Audit Trail Actions',
			'Objects by Schema',
			'Objects by Size Distribution',
		])
		expect(chartProps(wrapper)).toEqual([
			{ type: 'line', height: '350', options: OPTIONS.auditOptions, series: [{ name: 'create', data: [1] }], labels: undefined },
			{ type: 'pie', height: '350', options: OPTIONS.distributionOptions, series: [3, 4], labels: ['A', 'B'] },
			{ type: 'bar', height: '350', options: OPTIONS.sizeOptions, series: [{ name: 'Objects', data: [6, 7] }], labels: undefined },
		])
	})

	it.each([[{}], [null]])('falls back to empty series for %p', (chartData) => {
		const [audit, pie, size] = chartProps(mountGrid(chartData))

		expect(audit.series).toEqual([])
		expect(pie.series).toEqual([])
		expect(pie.labels).toEqual([])
		expect(size.series).toEqual([{ name: 'Objects', data: [] }])
	})
})
