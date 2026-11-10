import { flushPromises, mount } from '@vue/test-utils'
import OperationsConsoleIndex from './OperationsConsoleIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the operations console's tables: what each one shows
 * (header labels and cell texts, independent of the markup that draws them),
 * the empty states, and the job buttons.
 *
 * @spec exclude Golden record of the client-side console tables.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(() => Promise.reject(new Error('offline'))), post: jest.fn(() => new Promise(() => {})) },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	n: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { CnDataTableStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	// A cell without a slot as the library's formatValue draws it: text cut at
	// 100 characters plus an ellipsis, an empty value as a dash.
	const formatValue = (value) => {
		if (value === null || value === undefined || value === '') {
			return '—'
		}
		const text = String(value)
		return text.length > 100 ? `${text.substring(0, 100)}...` : text
	}
	return {
		__esModule: true,
		CnDataTable: {
			...CnDataTableStub,
			render() {
				const $slots = { ...this.$slots }
				for (const col of this.columns) {
					$slots[`column-${col.key}`] ||= ({ value }) => formatValue(value)
				}
				return CnDataTableStub.render.call(new Proxy(this, {
					get: (target, key) => (key === '$slots' ? $slots : target[key]),
				}))
			},
		},
	}
})
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))

const FILLED = {
	error: '',
	panes: [{ id: 'notifications', total: 5, attention: 0, delivered: 4, queued: 1, templateGaps: 0, counts: { delivered: 4, failed: 1 } }],
	bulkJobs: [
		{ id: 'j1', action: 'delete', state: 'running', processed: 3, total: 10, startedBy: 'admin', actions: { pause: true } },
		{ id: 'j2', action: 'publish', state: 'paused', processed: 1, total: 2, startedBy: 'bob', report: { fatal: 'Locked' }, actions: { resume: true, retry: true } },
		{ id: 'j3', action: 'export', state: 'done', processed: 2, total: 2, startedBy: 'eve' },
	],
	rulesHoldingAnError: [{ ruleId: 'r1', schemaSlug: 'zaak', lastError: 'Division by zero', lastErrorAt: '2026-11-01' }],
	runs: [
		{ id: 1, name: 'cleanup', started: '2026-11-01 10:00', duration: 12, outcome: 'completed', message: '', actor: 'admin' },
		{ id: 2, name: 'reindex', started: '2026-11-02 11:00', outcome: 'failed', message: 'Timeout' },
	],
}

/**
 * Mount the console, let its (failing) load settle, then give it data.
 *
 * @param {object} state The data to show
 * @return {Promise<object>} The wrapper
 */
async function mounted(state) {
	const wrapper = mount(OperationsConsoleIndex)
	await flushPromises()
	await wrapper.setData(state)
	return wrapper
}

describe('OperationsConsoleIndex tables', () => {
	it('shows the jobs, notification outcomes, erroring rules and runs', async () => {
		const wrapper = await mounted(FILLED)
		expect(tableText(wrapper)).toMatchSnapshot()
		expect(wrapper.findAll('[data-stub="empty"]')).toHaveLength(0)
	})

	it('keeps a long error whole, wraps it and leaves missing values blank', async () => {
		const long = 'A long sentence that runs on. '.repeat(6).trim()
		const wrapper = await mounted({
			...FILLED,
			bulkJobs: [{ id: 'j1', action: 'delete', state: 'running', processed: 1, total: 2, startedBy: null }],
			rulesHoldingAnError: [{ ruleId: 'r1', schemaSlug: null, lastError: long, lastErrorAt: null }],
		})
		const cells = wrapper.findAll('table')[2].findAll('tbody td').map((cell) => cell.text())
		expect(cells).toEqual(['r1', '', long, ''])
		const jobCells = wrapper.findAll('table')[0].findAll('tbody td').map((cell) => cell.text())
		expect(jobCells[3]).toBe('')
		const [jobs, rules, runs] = wrapper.findAllComponents({ name: 'CnDataTable' })
			.filter((table) => table.props('columns').some((col) => ['failure', 'lastError', 'message'].includes(col.key)))
		for (const [table, key] of [[jobs, 'failure'], [rules, 'lastError'], [runs, 'message']]) {
			expect(table.vm.$attrs.cellClass({}, { key })).toBe('failureCell cn-cell--wrap')
		}
	})

	it('shows the empty states without rows', async () => {
		const wrapper = await mounted({ error: '' })
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.findAll('[data-stub="empty"]').map((e) => e.text())).toMatchSnapshot()
	})

	it('runs a job action from its row and disables that row while acting', async () => {
		const wrapper = await mounted(FILLED)
		const retry = wrapper.findAll('button').find((b) => b.text() === 't:Retry')
		await retry.trigger('click')
		expect(wrapper.vm.acting).toBe('j2')
		expect(wrapper.findAll('button').filter((b) => b.attributes('disabled') !== undefined).map((b) => b.text())).toEqual(['t:Resume', 't:Retry'])
	})
})
