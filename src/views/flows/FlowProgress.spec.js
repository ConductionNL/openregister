import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import FlowProgress from './FlowProgress.vue'
import { inboxRoute, longDuration, runStatusRows, stepDuration } from './flowProgress.js'

/**
 * The workflow progress page: its load, a rendered step row whose counts link
 * to the filtered task inbox, and the helpers behind the cells.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => `t:${text}`,
	translate: (app, text) => `t:${text}`,
	n: (app, one, many) => many,
	translatePlural: (app, one, many) => many,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		CnDataTable: jest.requireActual('../../../tests/unit/support/tableGolden.js').CnDataTableStub,
		CnStatusBadge: {
			name: 'CnStatusBadge',
			props: ['label', 'variant'],
			render() {
				return h('span', { class: 'badge' }, `[${this.variant}] ${this.label}`)
			},
		},
	}
})

/**
 * What the browser writes for a value in a unit, as the page asks for it.
 *
 * @param {string} name An Intl unit
 * @param {number} value The value
 * @return {string} The text
 */
function unit(name, value) {
	return new Intl.NumberFormat('en-GB', { style: 'unit', unit: name, unitDisplay: 'short', maximumFractionDigits: 1 }).format(value)
}

const REPORT = {
	flow: 'flow-1',
	window: { from: '2026-08-16T12:00:00+00:00', to: '2026-11-14T12:00:00+00:00' },
	runs: { total: 3, byStatus: { suspended: 2, completed: 1 } },
	duration: { averageSeconds: 7200, measured: 1, durationUnknown: 0 },
	steps: [
		{
			nodeId: 'legal-review',
			name: 'Legal review',
			kind: 'human',
			open: 2,
			overdue: 1,
			done: 0,
			averageTaskSeconds: null,
			executions: 0,
			failed: 0,
			averageDurationMs: null,
			tasksHref: '/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&overdue=true&isTerminal=false',
			openTasksHref: '/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&isTerminal=false',
			doneTasksHref: '/api/flow-tasks?scope=all&flow=flow-1&node=legal-review&isTerminal=true',
		},
	],
}

/**
 * Mount the page with the given answers by URL; a missing URL fails.
 *
 * @param {object} answers Response data by URL
 * @return {Promise<object>} The wrapper
 */
async function mounted(answers) {
	axios.get.mockImplementation((url) => (url in answers ? Promise.resolve({ data: answers[url] }) : Promise.reject(new Error('404'))))
	const wrapper = mount(FlowProgress, {
		props: { id: 'flow-1' },
		global: {
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
		},
	})
	await flushPromises()
	return wrapper
}

describe('FlowProgress', () => {
	beforeEach(() => axios.get.mockReset())

	it('renders a step row whose counts link to the filtered task inbox', async () => {
		const wrapper = await mounted({
			'/gen/apps/openregister/api/flows/flow-1': { name: 'Permit' },
			'/gen/apps/openregister/api/flows/flow-1/progress': REPORT,
		})

		expect(wrapper.find('[data-testid="flow-progress-title"]').text()).toBe('t:Workflow progress')
		expect(wrapper.text()).toContain('Permit')
		expect(wrapper.text()).toContain('Legal review')

		const overdue = wrapper.find('[data-testid="flow-progress-overdue-legal-review"]')
		expect(overdue.text()).toBe('1')
		expect(JSON.parse(overdue.attributes('data-to'))).toEqual({
			path: '/flow-tasks',
			query: { scope: 'all', flow: 'flow-1', node: 'legal-review', overdue: 'true', isTerminal: 'false' },
		})
		expect(JSON.parse(wrapper.find('[data-testid="flow-progress-open-legal-review"]').attributes('data-to')).query.overdue).toBeUndefined()

		const badges = wrapper.findAll('.badge').map((badge) => badge.text())
		expect(badges).toHaveLength(2)
		expect(badges[0]).toContain('t:Completed: 1')
		expect(badges[1]).toContain('t:Waiting: 2')
		expect(wrapper.find('[data-testid="flow-progress-duration"]').text()).toContain(unit('hour', 2))
	})

	it('shows the error note when the progress cannot be read', async () => {
		const wrapper = await mounted({ '/gen/apps/openregister/api/flows/flow-1': { name: 'Permit' } })

		expect(wrapper.find('[data-testid="flow-progress-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="flow-progress-title"]').exists()).toBe(false)
	})

	it('reads the flow and its progress, in that order of URLs', async () => {
		await mounted({
			'/gen/apps/openregister/api/flows/flow-1': { name: 'Permit' },
			'/gen/apps/openregister/api/flows/flow-1/progress': { ...REPORT, steps: [] },
		})

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/gen/apps/openregister/api/flows/flow-1',
			'/gen/apps/openregister/api/flows/flow-1/progress',
		])
	})
})

describe('flowProgress helpers', () => {
	it('writes a duration in the largest unit that fits', () => {
		expect(longDuration(null)).toBe('')
		expect(longDuration(45)).toBe(unit('second', 45))
		expect(longDuration(90)).toBe(unit('minute', 1.5))
		expect(longDuration(7200)).toBe(unit('hour', 2))
		expect(longDuration(129600)).toBe(unit('day', 1.5))
	})

	it('times a person in hours and days and a machine in milliseconds', () => {
		expect(stepDuration({ kind: 'human', averageTaskSeconds: 3600, averageDurationMs: 5 })).toBe(unit('hour', 1))
		expect(stepDuration({ kind: 'automatic', averageTaskSeconds: 3600, averageDurationMs: 250 })).toBe('250 ms')
		expect(stepDuration({ kind: 'automatic', averageDurationMs: null })).toBe('')
	})

	it('orders and labels the runs by status', () => {
		expect(runStatusRows({ suspended: 2, completed: '1' }).map((row) => [row.status, row.label, row.count])).toEqual([
			['completed', 't:Completed', 1],
			['suspended', 't:Waiting', 2],
		])
		expect(runStatusRows(undefined)).toEqual([])
	})

	it('turns an API task link into the inbox route, keeping its filters', () => {
		expect(inboxRoute('/api/flow-tasks?scope=all&flow=f%201&node=n')).toEqual({ path: '/flow-tasks', query: { scope: 'all', flow: 'f 1', node: 'n' } })
		expect(inboxRoute('')).toEqual({ path: '/flow-tasks', query: {} })
	})
})
