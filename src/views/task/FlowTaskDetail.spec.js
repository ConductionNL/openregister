import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
/**
 * FlowTaskDetail: the task read and its not-found states, the state, priority,
 * due and assignee wording, the subject link, and the claim and complete verbs.
 *
 * @spec exclude Unit tests of the flow task detail page.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import FlowTaskDetail from './FlowTaskDetail.vue'

jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())

const TASK = {
	uuid: 'task-1',
	title: 'Review the request',
	description: 'Check the attachments',
	state: 'active',
	priority: 'high',
	dueAt: '2026-11-20T00:00:00Z',
	daysUntilDue: 3,
	assignee: 'alice',
	requester: 'bob',
	appId: 'dossiq',
	outcome: 'approved',
	subject: { title: 'Case 12', register: 1, schema: 2, uuid: 'obj-1' },
	outcomes: [{ id: 'approve', label: 'Approve' }, 'reject'],
}

/**
 * Mount the page for one task uuid.
 *
 * @param {string} uuid The route uuid.
 * @return {object} The wrapper.
 */
function mountPage(uuid = 'task-1') {
	return mount(FlowTaskDetail, {
		global: {
			mocks: {
				$route: { params: { uuid } },
				t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text),
			},
			stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
		},
	})
}

/**
 * Mount the page over one task and wait for the read.
 *
 * @param {object} task The task the read returns.
 * @return {Promise<object>} The wrapper.
 */
async function pageWith(task) {
	axios.get.mockResolvedValue({ data: task })
	const wrapper = mountPage()
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	getCurrentUser.mockReturnValue({ uid: 'alice' })
})

describe('FlowTaskDetail read', () => {
	it('reads the task and renders its facts', async () => {
		const wrapper = await pageWith(TASK)

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/task-1')
		expect(wrapper.find('[data-testid="task-title"]').text()).toBe('Review the request')
		expect(wrapper.text()).toContain('Check the attachments')
		const cells = wrapper.findAll('td').map((td) => td.text())
		expect(cells).toEqual(['In progress', 'High', 'Due in 3 days', 'alice', 'bob', 'dossiq', 'approved', 'Case 12'])
		expect(JSON.parse(wrapper.find('[data-testid="task-subject-link"]').attributes('data-to'))).toEqual({
			name: 'objectDetail',
			params: { register: '1', schema: '2', id: 'obj-1' },
		})
	})

	it('renders the not-found state on a 404 without a toast', async () => {
		axios.get.mockRejectedValue({ response: { status: 404 } })
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('[data-stub="empty"]').text()).toContain('No such task')
		expect(showError).not.toHaveBeenCalled()
	})

	it('toasts and renders not-found when the read fails otherwise', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		axios.get.mockRejectedValue({ response: { status: 500 } })
		const wrapper = mountPage()
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not load the task')
		expect(wrapper.find('[data-stub="empty"]').exists()).toBe(true)
		error.mockRestore()
	})

	it('reloads when the route moves to another task', async () => {
		axios.get.mockResolvedValue({ data: TASK })
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.$options.watch['$route.params.uuid'].call(wrapper.vm)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})

describe('FlowTaskDetail wording', () => {
	it.each([
		['available', 'Available'],
		['enabled', 'Ready to claim'],
		['completed', 'Completed'],
		['terminated', 'Terminated'],
		['disabled', 'Disabled'],
		['paused', 'paused'],
	])('labels state %s', async (state, label) => {
		const wrapper = await pageWith({ uuid: 'x', state })
		expect(wrapper.find('[data-testid="task-state"]').text()).toBe(label)
	})

	it.each([
		['low', 'Low'],
		['normal', 'Normal'],
		['urgent', 'Urgent'],
		['odd', 'odd'],
		[undefined, ''],
	])('labels priority %s', async (priority, label) => {
		const wrapper = await pageWith({ uuid: 'x', priority })
		expect(wrapper.vm.priorityLabel).toBe(label)
	})

	it.each([
		[{ overdue: true, daysOverdue: 1 }, 'Overdue by 1 day'],
		[{ overdue: true, daysOverdue: 4 }, 'Overdue by 4 days'],
		[{ overdue: true }, 'Overdue'],
		[{ daysUntilDue: 0 }, 'Due today'],
		[{ daysUntilDue: 1 }, 'Due tomorrow'],
		[{ daysUntilDue: undefined, dueAt: 'not a date' }, 'not a date'],
		[{ daysUntilDue: 'x', dueAt: '2026-01-02T00:00:00Z' }, new Date('2026-01-02T00:00:00Z').toLocaleDateString()],
	])('words the due date of %p', async (fields, label) => {
		const wrapper = await pageWith({ uuid: 'x', dueAt: '2026-01-01', ...fields })
		expect(wrapper.vm.dueLabel).toBe(label)
	})

	it('words an unassigned task and a subject without a route', async () => {
		const wrapper = await pageWith({ uuid: 'x', subject: { uuid: 'obj-9' } })

		expect(wrapper.vm.assigneeLabel).toBe('Unassigned, open for claiming')
		expect(wrapper.vm.subjectTitle).toBe('obj-9')
		expect(wrapper.vm.subjectRoute).toBeNull()
		expect(wrapper.find('[data-testid="task-subject-link"]').exists()).toBe(false)
		expect(wrapper.vm.formatDate('')).toBe('')
	})
})

describe('FlowTaskDetail verbs', () => {
	it('offers claim on a pooled task and reloads after claiming', async () => {
		const wrapper = await pageWith({ uuid: 'task-2', assignee: null })
		axios.post.mockResolvedValue({})

		expect(wrapper.find('[data-testid="task-complete"]').exists()).toBe(false)
		await wrapper.find('[data-testid="task-claim"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/task-2/claim', {})
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('offers one complete button per declared outcome to the assignee', async () => {
		const wrapper = await pageWith(TASK)
		axios.post.mockResolvedValue({})
		const buttons = wrapper.findAll('[data-testid="task-complete"]')

		expect(wrapper.find('[data-testid="task-claim"]').exists()).toBe(false)
		expect(buttons.map((b) => b.text())).toEqual(['Complete: Approve', 'Complete: reject'])
		await buttons[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/task-1/complete', { outcome: 'approve' })
	})

	it('falls back to the default outcome', async () => {
		const wrapper = await pageWith({ uuid: 'x', assignee: 'alice' })
		expect(wrapper.findAll('[data-testid="task-complete"]').map((b) => b.text())).toEqual(['Complete'])
	})

	it('offers nothing to a watcher or on a terminal task', async () => {
		getCurrentUser.mockReturnValue(null)
		const watcher = await pageWith(TASK)
		expect(watcher.find('.taskActions').exists()).toBe(false)
		expect(watcher.vm.completeOutcomes).toEqual([])

		getCurrentUser.mockReturnValue({ uid: 'alice' })
		const terminal = await pageWith({ ...TASK, isTerminal: true })
		expect(terminal.find('.taskActions').exists()).toBe(false)
	})

	it.each([
		['the server wording', { response: { data: { error: 'Already claimed' } } }, 'Already claimed'],
		['the generic wording', new Error('network'), 'The task refused that action'],
	])('shows %s when a verb is refused', async (label, error, message) => {
		const wrapper = await pageWith({ uuid: 'x', assignee: null })
		axios.post.mockRejectedValue(error)

		await wrapper.vm.claim()

		expect(showError).toHaveBeenCalledWith(message)
		expect(wrapper.vm.acting).toBe(false)
	})

	it('posts nothing without a task uuid', async () => {
		const wrapper = await pageWith({ title: 'No id' })
		await wrapper.vm.complete('done')

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('derives outcome ids from every entry shape', async () => {
		const wrapper = await pageWith({ uuid: 'x' })

		expect(wrapper.vm.outcomeId({ value: 'v' })).toBe('v')
		expect(wrapper.vm.outcomeId({ label: 'l' })).toBe('l')
		expect(wrapper.vm.outcomeId({})).toBe('')
		expect(wrapper.vm.outcomeId(null)).toBe('')
		expect(wrapper.vm.completeLabel({ id: 'done' })).toBe('Complete')
	})
})
