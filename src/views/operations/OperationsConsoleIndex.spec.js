import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import DependenciesSection from './DependenciesSection.vue'
import OperationsConsoleIndex from './OperationsConsoleIndex.vue'

/**
 * The console's Dependencies section: an open breaker is listed with its next
 * probe time and a reset button, the reset goes to the administrator route and
 * the list is read again, and a per-worker store is said so.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}) }))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
	n: (app, one, many, count) => many.replace('%n', String(count)),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { CnDataTableStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	return { __esModule: true, CnDataTable: CnDataTableStub }
})
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))

const NEXT_PROBE = 1794729600

const OPEN_BREAKER = {
	key: 'source:12',
	dependency: 'source',
	state: 'open',
	calls: 10,
	failures: 7,
	openedAt: NEXT_PROBE - 30,
	nextProbeAt: NEXT_PROBE,
	shared: false,
}

const CLOSED_BREAKER = {
	key: 'llm',
	dependency: 'llm',
	state: 'closed',
	calls: 4,
	failures: 1,
	openedAt: null,
	nextProbeAt: null,
	shared: false,
}

/**
 * Answer every console read; the dependencies route with the given overview.
 *
 * @param {object} overview The dependencies answer
 * @return {void}
 */
function answering(overview) {
	axios.get.mockImplementation((url) => {
		if (url === '/apps/openregister/api/operations/dependencies') {
			return Promise.resolve({ data: overview })
		}
		if (url === '/apps/openregister/api/operations/console') {
			return Promise.resolve({ data: { panes: [], window: { hours: 24 } } })
		}
		return Promise.resolve({ data: {} })
	})
}

/**
 * Mount the console and let every read settle.
 *
 * @return {Promise<object>} The Dependencies section's wrapper
 */
async function dependenciesOnConsole() {
	const wrapper = mount(OperationsConsoleIndex)
	await flushPromises()
	return wrapper.findComponent(DependenciesSection)
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('OperationsConsoleIndex Dependencies section', () => {
	it('lists an open breaker with its failures, next probe time and a reset button', async () => {
		answering({
			dependencies: [CLOSED_BREAKER, OPEN_BREAKER],
			pressure: { enabled: true, underPressure: false },
			shared: true,
		})

		const section = await dependenciesOnConsole()

		expect(section.exists()).toBe(true)
		expect(section.find('h2').text()).toBe('Dependencies')
		const rows = section.findAll('tbody tr')
		expect(rows).toHaveLength(2)

		const open = rows[1].findAll('td').map((cell) => cell.text())
		expect(open[0]).toBe('source:12')
		expect(open[1]).toBe('Refusing calls')
		expect(open[2]).toBe('7 / 10')
		expect(open[3]).toBe(new Date(NEXT_PROBE * 1000).toLocaleString())
		expect(rows[1].find('button').text()).toBe('Reset')

		const closed = rows[0].findAll('td').map((cell) => cell.text())
		expect(closed[1]).toBe('Passing calls')
		expect(closed[3]).toBe('-')
		expect(rows[0].find('button').exists()).toBe(false)
	})

	it('says the breakers are per worker without a shared cache', async () => {
		answering({ dependencies: [OPEN_BREAKER], pressure: { enabled: true, underPressure: false }, shared: false })

		const section = await dependenciesOnConsole()

		expect(section.find('.dependenciesPerWorker').text()).toBe(
			'This instance has no shared cache, so each PHP worker keeps its own breakers. This list shows the worker that answered.',
		)
	})

	it('shows no per-worker notice when the breakers are shared', async () => {
		answering({ dependencies: [OPEN_BREAKER], pressure: { enabled: true, underPressure: false }, shared: true })

		const section = await dependenciesOnConsole()

		expect(section.find('.dependenciesPerWorker').exists()).toBe(false)
	})

	it('resets a breaker through the administrator route and reads the list again', async () => {
		answering({ dependencies: [OPEN_BREAKER], pressure: { enabled: true, underPressure: false }, shared: true })
		axios.post.mockResolvedValue({ data: { ...OPEN_BREAKER, state: 'closed' } })
		const section = await dependenciesOnConsole()
		answering({ dependencies: [], pressure: { enabled: true, underPressure: false }, shared: true })

		await section.find('tbody button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/operations/dependencies/source%3A12/reset')
		expect(section.find('.dependenciesEmpty').text()).toBe('No dependency has failed recently.')
	})

	it('shows the server refusal when a reset fails', async () => {
		answering({ dependencies: [OPEN_BREAKER], pressure: { enabled: true, underPressure: false }, shared: true })
		axios.post.mockRejectedValue({ response: { data: { error: 'No breaker with that key.' } } })
		const section = await dependenciesOnConsole()

		await section.find('tbody button').trigger('click')
		await flushPromises()

		expect(section.text()).toContain('No breaker with that key.')
		expect(section.findAll('tbody tr')).toHaveLength(1)
	})

	it('words the database pressure', async () => {
		answering({ dependencies: [], pressure: { enabled: true, underPressure: true }, shared: true })
		let section = await dependenciesOnConsole()
		expect(section.find('.dependenciesPressure').attributes('type')).toBe('warning')
		expect(section.find('.dependenciesPressure').text()).toBe(
			'The database is under pressure: heavy requests such as exports are refused for now.',
		)

		answering({ dependencies: [], pressure: { enabled: false, underPressure: false }, shared: true })
		section = await dependenciesOnConsole()
		expect(section.find('.dependenciesPressure').text()).toBe('Shedding under database pressure is switched off.')
	})

	it('says so when the dependencies cannot be read', async () => {
		answering({})
		axios.get.mockImplementation((url) => (url === '/apps/openregister/api/operations/dependencies'
			? Promise.reject(new Error('403'))
			: Promise.resolve({ data: {} })))

		const section = await dependenciesOnConsole()

		expect(section.find('[type="error"]').text()).toBe('The dependencies could not be read.')
		expect(section.find('table').exists()).toBe(false)
	})
})
