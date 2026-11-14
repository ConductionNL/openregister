/**
 * The record page's Tasks tab (record-tasks-tab): the library tab with the
 * flow-task source and the record's register, schema and uuid; the open-task
 * badge; and the create body those props produce, which must be the flat keys
 * of design D-2.
 *
 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md#requirement-a-records-page-lists-and-creates-the-flow-tasks-anchored-on-it
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/tabs/AppTab.vue', () => mockModule('AppTab'))
jest.mock('../../components/tabs/AppTabs.vue', () => mockModule('AppTabs'))
jest.mock('../../components/access-links/ObjectAccessLinks.vue', () => mockModule('ObjectAccessLinks'))
jest.mock('../../components/object-relations/ContactsTab.vue', () => mockModule('ContactsTab'))
jest.mock('../../components/object-relations/DeckTab.vue', () => mockModule('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () => mockModule('EmailsTab'))
jest.mock('../../components/object-relations/EventsTab.vue', () => mockModule('EventsTab'))
jest.mock('../../components/object-relations/ReferencedByTab.vue', () => mockModule('ReferencedByTab'))
jest.mock('../../components/object-relations/RelationsTab.vue', () => mockModule('RelationsTab'))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
}))

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @param {object} extra Named exports that are not components
 * @return {object} The module
 */
function mockLibrary(extra = {}) {
	const made = {}
	return new Proxy(
		{ __esModule: true, ...extra },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}

/** The library's own body builder, so the props this page passes are checked against what is sent. */
const { buildFlowTaskBody } = jest.requireActual(
	require('path').resolve(__dirname, '../../../node_modules/@conduction/nextcloud-vue/src/composables/useTaskInboxStore.js'),
)

/**
 * Render the view for an object, without its mount-time loads.
 *
 * @param {object|null} item The object in the store
 * @param {object} state Data to set before rendering
 * @return {object} The wrapper
 */
function render(item, state = {}) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, {
		objectItem: item,
		uses: { results: [] },
		used: { results: [], total: 0 },
		files: { results: [] },
		auditTrails: { results: [] },
	})
	const View = {
		...ObjectDetails,
		data() {
			return { ...ObjectDetails.data.call(this), ...state }
		},
		mounted() {},
		updated() {},
		beforeUnmount() {},
		watch: {},
	}
	return mount(View)
}

const RECORD = { id: 'abc-123', '@self': { id: 'abc-123', register: 4, schema: 11 } }

describe('ObjectDetails Tasks tab', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('renders the library tab with the flow-task source and the record it hangs on', () => {
		const tab = render(RECORD).find('x-AppTab[data-testid="record-tasks-tab"]')
		const tasks = tab.find('x-CnTasksTab')

		expect(tasks.attributes()).toMatchObject({ source: 'flow-tasks', objectid: 'abc-123', register: '4', schema: '11' })
		expect(tab.find('slot-title').text()).toBe('t:Tasks')
	})

	it('is not offered before the record is known', () => {
		expect(render({ id: 'new' }).find('[data-testid="record-tasks-tab"]').exists()).toBe(false)
	})

	it('creates with the flat keys of design D-2, never a requester or a state', () => {
		const tasks = render(RECORD).find('x-CnTasksTab').attributes()
		const anchor = { objectUuid: tasks.objectid, registerId: tasks.register, schemaId: tasks.schema }

		expect(buildFlowTaskBody({ title: 'Bel aanvrager terug', dueAt: '2026-11-20T00:00:00.000Z', anchor, assignee: { kind: 'user', id: 'jan' } }))
			.toEqual({ title: 'Bel aanvrager terug', dueAt: '2026-11-20T00:00:00.000Z', objectUuid: 'abc-123', registerId: '4', schemaId: '11', assignee: 'jan' })
		expect(buildFlowTaskBody({ title: 'Pool', anchor, assignee: { kind: 'group', id: 'backoffice' } }))
			.toEqual({ title: 'Pool', objectUuid: 'abc-123', registerId: '4', schemaId: '11', performerType: 'group', candidateGroups: ['backoffice'] })
	})

	it('shows the open count as the badge, and no badge for none', async () => {
		const wrapper = render(RECORD, { openTaskCount: 3 })
		expect(wrapper.find('[data-testid="record-tasks-count"]').attributes('count')).toBe('3')

		wrapper.vm.onTaskCount(0)
		await flushPromises()
		expect(wrapper.find('[data-testid="record-tasks-count"]').exists()).toBe(false)
	})

	it('counts open tasks on the record with the server, the audience the tab lists', async () => {
		axios.get.mockResolvedValue({ data: { results: [{}], total: 5 } })
		const wrapper = render(RECORD)

		await wrapper.vm.countOpenTasks()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/flow-tasks', {
			params: { objectUuid: 'abc-123', scope: 'all', isTerminal: 'false', limit: 1 },
		})
		expect(wrapper.vm.openTaskCount).toBe(5)
	})

	it('leaves the badge off when the count fails, and asks nothing for an unsaved record', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const wrapper = render(RECORD, { openTaskCount: 2 })
		await wrapper.vm.countOpenTasks()
		expect(wrapper.vm.openTaskCount).toBeNull()

		axios.get.mockClear()
		const unsaved = render({ id: 'new' }, { openTaskCount: 2 })
		await unsaved.vm.countOpenTasks()
		expect(axios.get).not.toHaveBeenCalled()
		expect(unsaved.vm.openTaskCount).toBeNull()
	})
})
