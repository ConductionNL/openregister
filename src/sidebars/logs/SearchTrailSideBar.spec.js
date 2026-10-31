import SearchTrailSideBar from './SearchTrailSideBar.vue'

// The activity chart labels each period the way the chosen granularity reads.

jest.mock('../../store/store.js', () => ({}))
jest.mock('../../eventBus.js', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))

const PERIOD = '2026-10-29T14:00:00'

/**
 * Format a period under a granularity.
 *
 * @param {string} value The granularity.
 * @return {string} The label
 */
function format(value) {
	return SearchTrailSideBar.methods.formatActivityPeriod.call(
		{ selectedActivityPeriod: { value } },
		PERIOD,
	)
}

describe('SearchTrailSideBar.formatActivityPeriod', () => {
	const date = new Date(PERIOD)

	it.each([
		['hourly', date.toLocaleString()],
		['daily', date.toLocaleDateString()],
		['weekly', `Week of ${date.toLocaleDateString()}`],
		[
			'monthly',
			date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
		],
		['yearly', PERIOD],
		['toString', PERIOD],
	])('%s', (granularity, expected) => {
		expect(format(granularity)).toBe(expected)
	})
})
