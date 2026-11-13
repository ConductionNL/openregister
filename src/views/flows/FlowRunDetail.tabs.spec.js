import { flushPromises, mount } from '@vue/test-utils'
import FlowRunDetail from './FlowRunDetail.vue'
import { nodeLabel } from './flowDetail.js'

/**
 * The run page's tabs and its changed-objects panel: what each tab shows with
 * and without data, keyboard movement between tabs, the optional facts, and
 * the objects a run changed grouped by step.
 *
 * @spec exclude Unit tests of the client-side run page.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(() => Promise.reject(new Error('offline'))) } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	n: (app, one, many, count) => `n:${count}:${many}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		CnStatusBadge: {
			name: 'CnStatusBadge',
			props: ['label', 'variant'],
			render() {
				return h('span', `[${this.variant}] ${this.label}`)
			},
		},
	}
})
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))

const RUN = {
	uuid: 'run-1234-0000-4000-8000-000000000000',
	flowId: 'flow-1',
	status: 'failed',
	created: '2026-11-02T10:00:00Z',
	finished: '2026-11-02T10:00:05Z',
	trigger: 'manual',
	flowVersion: 1,
	error: 'Boom',
	subjectUuid: 'e2236ef7-0000-4000-8000-000000000000',
	log: [],
}

/**
 * Mount the run page, let its (failing) load settle, then give it a run.
 *
 * @param {object} state The data to show
 * @return {Promise<object>} The wrapper
 */
async function mounted(state) {
	const wrapper = mount(FlowRunDetail, {
		props: { uuid: RUN.uuid },
		global: {
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
			mocks: { $router: { push: jest.fn() } },
			config: { globalProperties: { t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}` } },
		},
	})
	await flushPromises()
	await wrapper.setData({ loading: false, run: RUN, flow: { id: 'flow-1', name: 'Enquiry' }, ...state })
	return wrapper
}

/**
 * The visible text of the open tab panel, whitespace collapsed.
 *
 * @param {object} wrapper The wrapper
 * @return {string} The text
 */
function panel(wrapper) {
	return wrapper.find('[role="tabpanel"]').text().replace(/\s+/g, ' ').trim()
}

/**
 * Open a tab by clicking it.
 *
 * @param {object} wrapper The wrapper
 * @param {string} id The tab id
 * @return {Promise<void>}
 */
async function open(wrapper, id) {
	await wrapper.find(`[data-testid="run-tab-${id}"]`).trigger('click')
}

describe('FlowRunDetail tabs', () => {
	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('lists the run subject and each role once, by register and schema when known', async () => {
		const wrapper = await mounted({
			run: {
				...RUN,
				subjectRegister: 'zaken',
				subjectSchema: 'zaak',
				subjects: {
					applicant: { uuid: 'aaaa-1', register: 'klanten', schema: 'persoon' },
					again: { uuid: RUN.subjectUuid },
					loose: { uuid: 'bbbb-2' },
					empty: {},
				},
			},
		})

		await open(wrapper, 'objects')

		expect(wrapper.findAll('[role="tabpanel"] li').map((li) => li.findAll('span').map((span) => span.text()))).toEqual([
			['zaken › zaak', RUN.subjectUuid],
			['klanten › persoon', 'aaaa-1'],
			['loose', 'bbbb-2'],
		])
	})

	it('says when a run is about no object or raised no tasks', async () => {
		const wrapper = await mounted({ run: { ...RUN, subjectUuid: null } })

		await open(wrapper, 'objects')
		expect(panel(wrapper)).toBe('t:This run is not about a particular object.')

		await open(wrapper, 'tasks')
		expect(panel(wrapper)).toBe('t:This run raised no tasks.')
	})

	it('links each raised task, with its state and assignee', async () => {
		const wrapper = await mounted({
			tasks: [
				{ uuid: 'task-1', title: 'Beoordeel aanvraag', state: 'open', assignee: 'anna' },
				{ uuid: 'task-2', state: 'done' },
			],
		})

		await open(wrapper, 'tasks')

		const links = wrapper.findAll('[role="tabpanel"] a')
		expect(links.map((a) => a.text())).toEqual(['Beoordeel aanvraag', 'task-2'])
		expect(JSON.parse(links[0].attributes('data-to'))).toEqual({ name: 'flow-task-detail', params: { uuid: 'task-1' } })
		expect(wrapper.findAll('[role="tabpanel"] li').map((li) => li.findAll('span').map((span) => span.text()))).toEqual([
			['open', 'anna'],
			['done'],
		])
	})

	it('shows the context and the raw log as formatted JSON', async () => {
		const wrapper = await mounted({ run: { ...RUN, context: { step: 2 }, log: [{ node: 'n1' }] } })

		await open(wrapper, 'context')
		expect(wrapper.find('[role="tabpanel"] pre').text()).toBe('{\n  "step": 2\n}')

		await open(wrapper, 'log')
		expect(wrapper.find('[role="tabpanel"] pre').text()).toBe('[\n  {\n    "node": "n1"\n  }\n]')
		expect(wrapper.find('[role="tabpanel"]').attributes('aria-labelledby')).toBe('run-tab-log')
	})

	it('moves between tabs with the arrow keys and wraps at both ends', async () => {
		const wrapper = await mounted({})
		const selected = () => wrapper.findAll('[role="tab"]').filter((tab) => tab.attributes('aria-selected') === 'true').map((tab) => tab.attributes('data-testid'))

		expect(selected()).toEqual(['run-tab-steps'])
		await wrapper.find('[data-testid="run-tab-steps"]').trigger('keydown', { key: 'ArrowLeft' })
		expect(selected()).toEqual(['run-tab-log'])
		await wrapper.find('[data-testid="run-tab-log"]').trigger('keydown', { key: 'ArrowRight' })
		await wrapper.find('[data-testid="run-tab-steps"]').trigger('keydown', { key: 'ArrowRight' })
		expect(selected()).toEqual(['run-tab-objects'])
		expect(wrapper.find('[data-testid="run-tab-objects"]').attributes('tabindex')).toBe('0')
	})

	it('shows the wake-up time and correlation key only when the run has them', async () => {
		const without = await mounted({})
		expect(without.text()).not.toContain('t:Correlation key')
		expect(without.text()).not.toContain('t:Wakes up at')

		const withBoth = await mounted({ run: { ...RUN, resumeAt: '2026-11-03T08:00:00Z', correlationKey: 'zaak-77' } })
		expect(withBoth.text()).toContain('t:Wakes up at')
		expect(withBoth.text()).toContain('t:Correlation key')
		expect(withBoth.findAll('dd.mono').map((dd) => dd.text())).toContain('zaak-77')
	})

	it('groups the changed objects by step, or says nothing changed', async () => {
		const none = await mounted({})
		expect(none.find('[aria-labelledby="run-changed-heading"] .runCard__body').text()).toBe('t:This run changed no objects.')

		const changed = [
			{ node: 'n1', objects: [{ auditUuid: 'a-1', action: 'create', register: 'zaken', schema: 'zaak', objectUuid: 'obj-1' }] },
			{ node: 'n2', objects: [{ objectUuid: 'obj-2', action: 'update', register: 'zaken', schema: 'besluit' }] },
		]
		const wrapper = await mounted({ changed })

		const groups = wrapper.findAll('.runChanged')
		expect(groups.map((g) => g.find('h3').text())).toEqual([nodeLabel({ id: 'n1' }, {}), nodeLabel({ id: 'n2' }, {})])
		const spans = (group) => group.find('li').findAll('span').map((span) => span.text().replace(/\s+/g, ' '))
		expect(spans(groups[0])).toEqual(['create', 'zaken › zaak', 'obj-1'])
		expect(spans(groups[1])).toEqual(['update', 'zaken › besluit', 'obj-2'])
	})
})
