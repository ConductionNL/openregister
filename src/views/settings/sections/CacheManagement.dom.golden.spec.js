/**
 * Golden record of the cache management section: its overview cards, service
 * cards, performance table and warmup controls in each state.
 *
 * @spec exclude Golden record of client-side settings section markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import CacheManagement from './CacheManagement.vue'

jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'),
)
jest.mock('../../../dialogs/settings/ClearCacheDialog.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('ClearCacheDialog'),
)
jest.mock('vue-material-design-icons/Delete.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Delete'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Refresh'),
)
jest.mock('../../../store/settings.js', () => {
	const useSettingsStore = () => globalThis.mockSettingsStore
	useSettingsStore.$id = 'settings'
	return { useSettingsStore }
})

const { normalise } = require('../../__mocks__/goldenDom.js')

/**
 * Cache statistics with these service figures.
 *
 * @param {object} services The services
 * @param {object} figures The overview and performance overrides
 * @return {object} The statistics
 */
function stats(services, figures = {}) {
	return {
		unavailable: false,
		errorMessage: '',
		lastUpdated: null,
		overview: {
			overallHitRate: 82.345,
			totalCacheSize: 3145728,
			totalCacheEntries: 123,
		},
		performance: {
			averageHitTime: 3,
			averageMissTime: 700,
			optimalHitRate: 80,
			performanceGain: 12.6,
		},
		distributed: { available: true, backend: 'redis' },
		services,
		...figures,
	}
}

const SERVICES = {
	object: { entries: 150, hits: 90, requests: 100, memoryUsage: 2048 },
	schema: { entries: 0, hits: 10, requests: 40, memoryUsage: 0 },
	facet: { hits: 0, requests: 0 },
}

/**
 * Mounts the section on a store.
 *
 * @param {object} state The store fields to override
 * @return {object} The wrapper
 */
function section(state) {
	globalThis.mockSettingsStore = reactive({
		cacheStats: stats(SERVICES),
		loadingCache: false,
		clearingCache: false,
		loading: false,
		showClearCacheConfirmation: false,
		clearCacheType: 'all',
		warmupInterval: 'hourly',
		warmupLastRun: null,
		savingWarmupInterval: false,
		warmingUpCache: false,
		loadWarmupInterval: jest.fn(),
		loadCacheStats: jest.fn(),
		showClearCacheDialog: jest.fn(),
		hideClearCacheDialog: jest.fn(),
		performClearCache: jest.fn(),
		saveWarmupInterval: jest.fn(),
		...state,
	})
	return mount(CacheManagement, {
		global: {
			config: { warnHandler: () => {} },
			mocks: { t: (app, text) => `t:${text}` },
		},
	})
}

describe('cache management', () => {
	it.each([
		['with statistics', {}],
		[
			'with low figures',
			{
				cacheStats: stats(SERVICES, {
					overview: {
						overallHitRate: 40,
						totalCacheSize: 0,
						totalCacheEntries: 0,
					},
					performance: {
						averageHitTime: 9,
						averageMissTime: 100,
						optimalHitRate: 80,
						performanceGain: 1,
					},
					distributed: { available: false, backend: 'none' },
				}),
			},
		],
		[
			'while busy',
			{
				loading: true,
				clearingCache: true,
				warmingUpCache: true,
				showClearCacheConfirmation: true,
			},
		],
		[
			'when unavailable',
			{
				cacheStats: {
					...stats(SERVICES),
					unavailable: true,
					errorMessage: 'No stats',
				},
			},
		],
	])('renders %s', (name, state) => {
		const html = normalise(section(state))
		expect(html).toContain('SettingsSection'.toLowerCase())
		expect(html).toMatchSnapshot()
	})
})
