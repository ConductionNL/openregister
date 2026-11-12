import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import FlowTaskDetail from './FlowTaskDetail.vue'

/**
 * Golden record of the flow task page: the due label for every due state,
 * the task read and what a missing or failing read leaves, and what an action
 * sends, shows when refused, and reloads.
 *
 * @spec exclude Golden record of the client-side flow task page.
 */

jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: () => ({ uid: 'alice' }) }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/ClipboardListOutline.vue', () => ({ __esModule: true, default: {} }))

/**
 * A bare page carrying the page's methods.
 *
 * @param {object} state The data
 * @return {object} The page
 */
function pageWith(state) {
	const page = { ...state }
	for (const [name, method] of Object.entries(FlowTaskDetail.methods)) {
		page[name] = method.bind(page)
	}
	page.t = (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text)
	page.formatDate = (value) => `date:${value}`
	return page
}

/**
 * The page's data, without its methods.
 *
 * @param {object} page The page
 * @return {object} The data
 */
function dataOf(page) {
	return Object.fromEntries(Object.entries(page).filter(([, value]) => typeof value !== 'function'))
}

let logged

beforeEach(() => {
	jest.clearAllMocks()
	logged = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => logged.mockRestore())

describe('FlowTaskDetail.dueLabel', () => {
	it.each([
		['no task', null],
		['overdue by a day', { overdue: true, daysOverdue: 1 }],
		['overdue by days', { overdue: true, daysOverdue: '4' }],
		['overdue without a count', { overdue: true, daysOverdue: 0 }],
		['overdue with a bad count', { overdue: true, daysOverdue: 'x' }],
		['due without a count', { daysUntilDue: null, dueAt: '2026-01-02' }],
		['due today', { daysUntilDue: 0 }],
		['due in the past', { daysUntilDue: -2 }],
		['due tomorrow', { daysUntilDue: '1' }],
		['due in days', { daysUntilDue: 6 }],
		['not overdue but flagged so loosely', { overdue: 'yes', daysUntilDue: 3 }],
	])('labels %s', (label, task) => {
		expect(FlowTaskDetail.computed.dueLabel.call(pageWith({ task }))).toMatchSnapshot()
	})
})

describe('FlowTaskDetail.load', () => {
	it.each([
		['a found task', () => Promise.resolve({ data: { uuid: 't-1', title: 'Check' } })],
		['a missing task', () => Promise.reject({ response: { status: 404 } })],
		['a failing read', () => Promise.reject({ response: { status: 500 } })],
		['a network failure', () => Promise.reject(new Error('offline'))],
	])('reads %s', async (label, answer) => {
		const page = pageWith({ $route: { params: { uuid: 't-1' } }, loading: false, notFound: true, task: { stale: true } })
		const seen = []
		axios.get.mockImplementation((...args) => {
			seen.push([args, page.loading, page.notFound, page.task])
			return answer()
		})
		await page.load()
		expect({ data: dataOf(page), seen, shown: showError.mock.calls, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})

	it('reads an empty uuid when the route has none', async () => {
		const page = pageWith({ $route: { params: {} }, loading: false, notFound: false, task: null })
		axios.get.mockResolvedValue({ data: null })
		await page.load()
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/flow-tasks/')
	})
})

describe('FlowTaskDetail.postVerb', () => {
	it.each([
		['an accepted action', () => Promise.resolve({})],
		['a refusal with a reason', () => Promise.reject({ response: { data: { error: 'Already claimed' } } })],
		['a refusal with an empty reason', () => Promise.reject({ response: { data: { error: '' } } })],
		['a refusal with a non-text reason', () => Promise.reject({ response: { data: { error: { code: 1 } } } })],
		['a bare failure', () => Promise.reject(new Error('offline'))],
	])('handles %s, then reloads', async (label, answer) => {
		const page = pageWith({ task: { uuid: 't-1' }, acting: false })
		const order = []
		page.load = async () => order.push(['load', page.acting])
		axios.post.mockImplementation((...args) => {
			order.push(['post', ...args, page.acting])
			return answer()
		})
		showError.mockImplementation((text) => order.push(['showError', text]))
		await page.postVerb('claim', { note: 'n' })
		expect({ order, acting: page.acting }).toMatchSnapshot()
	})

	it('does nothing without a task', async () => {
		const page = pageWith({ task: null, acting: false })
		page.load = jest.fn()
		await page.postVerb('claim', {})
		expect([axios.post.mock.calls, page.load.mock.calls, page.acting]).toEqual([[], [], false])
	})
})
