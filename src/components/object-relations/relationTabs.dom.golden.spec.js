/**
 * Golden record of the relation tabs of an object: what each shows while
 * loading, after a failure, when its integration is missing, when empty and
 * with items; what unlinking and the action buttons do; and that a new object
 * loads anew.
 *
 * @spec exclude Golden record of client-side relation tab markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import ContactsTab from './ContactsTab.vue'
import DeckTab from './DeckTab.vue'
import EmailsTab from './EmailsTab.vue'
import EventsTab from './EventsTab.vue'
import ReferencedByTab from './ReferencedByTab.vue'
import RelationsTab from './RelationsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { delete: jest.fn(), get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `t:${text} ${JSON.stringify(vars)}` : `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars) => `${path} ${JSON.stringify(vars)}`,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountOff.vue', () => mockIcon('AccountOff'))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => mockIcon('AccountOutline'))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => mockIcon('AccountPlus'))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => mockIcon('AlertCircleOutline'))
jest.mock('vue-material-design-icons/CalendarOutline.vue', () => mockIcon('CalendarOutline'))
jest.mock('vue-material-design-icons/CalendarPlus.vue', () => mockIcon('CalendarPlus'))
jest.mock('vue-material-design-icons/CalendarRemove.vue', () => mockIcon('CalendarRemove'))
jest.mock('vue-material-design-icons/CloseCircleOutline.vue', () => mockIcon('CloseCircleOutline'))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => mockIcon('CubeOutline'))
jest.mock('vue-material-design-icons/EmailOffOutline.vue', () => mockIcon('EmailOffOutline'))
jest.mock('vue-material-design-icons/EmailOutline.vue', () => mockIcon('EmailOutline'))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => mockIcon('LinkVariant'))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => mockIcon('LinkVariantOff'))
jest.mock('vue-material-design-icons/TableLarge.vue', () => mockIcon('TableLarge'))
jest.mock('vue-material-design-icons/TableLargePlus.vue', () => mockIcon('TableLargePlus'))
jest.mock('vue-material-design-icons/TableRemove.vue', () => mockIcon('TableRemove'))
jest.mock('../../store/modules/object-relations/contacts.js', () => ({
	useContactRelationsStore: () => globalThis.relationStore,
}))
jest.mock('../../store/modules/object-relations/deck.js', () => ({
	useDeckRelationsStore: () => globalThis.relationStore,
}))
jest.mock('../../store/modules/object-relations/events.js', () => ({
	useEventRelationsStore: () => globalThis.relationStore,
}))

const PROPS = { register: 1, schema: 2, objectId: 'o1' }
const KEY = '1:2:o1'

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
	return new Proxy(
		{ __esModule: true },
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
 * A stand-in icon module.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
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

/**
 * A relation store the way the tabs read it.
 *
 * @param {object} state The items and flags
 * @param {Array} log The log of store calls
 * @return {object} The store
 */
function relationStoreOf(state, log) {
	const { reactive } = require('vue')
	const store = reactive({
		byObject: { [KEY]: state.items || [] },
		loading: { [KEY]: !!state.loading },
		contactsUnavailable: !!state.unavailable,
		deckUnavailable: !!state.unavailable,
		calendarUnavailable: !!state.unavailable,
		async fetch(...args) {
			log.push(['fetch', ...args])
			if (state.fetchFails) throw state.fetchFails
		},
		async unlink(...args) {
			log.push(['unlink', ...args])
			if (state.unlinkFails) throw state.unlinkFails
			store.byObject[KEY] = []
		},
	})
	return store
}

const failure = { response: { data: { error: 'refused' } } }
const missing = { response: { status: 501 } }
const STORE_TABS = {
	contacts: {
		component: ContactsTab,
		items: [{ uid: 'c1', displayName: 'Ann', email: 'a@b', role: 'owner' }],
	},
	deck: {
		component: DeckTab,
		items: [{ id: 3, deckRef: 'd3', title: 'Card', boardTitle: 'Board' }],
	},
	events: {
		component: EventsTab,
		items: [
			{ id: 'e1', summary: 'Meet', calendarName: 'Home', startsAt: '2026-01-02' },
		],
	},
}
const HTTP_TABS = {
	emails: {
		component: EmailsTab,
		answer: { data: { results: [{ id: 4, subject: 'Hi', sender: 'a@b' }] } },
		empty: { data: { results: [] } },
	},
	referencedBy: {
		component: ReferencedByTab,
		answer: {
			data: {
				total: 3,
				groups: [
					{
						schema: { id: 2, title: 'Book' },
						total: 3,
						results: [{ id: 'x', title: 'T', status: 'open' }],
					},
				],
			},
		},
		empty: { data: { groups: [], total: 0 } },
	},
	relations: {
		component: RelationsTab,
		answer: {
			data: {
				results: [
					{ type: 'email', id: 1, title: 'M' },
					{ type: 'event', id: 2, title: 'E' },
				],
			},
		},
		empty: { data: { results: [] } },
	},
}

/**
 * The markup without scoped-style ids and comment nodes, proven not empty.
 *
 * @param {object} wrapper The mounted tab
 * @return {string} The markup
 */
function dom(wrapper) {
	const html = wrapper
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).toContain('-tab')
	return html
}

/**
 * Mounts a tab and lets its first load finish.
 *
 * @param {object} component The tab
 * @return {Promise<object>} The wrapper
 */
async function tab(component) {
	const wrapper = mount(component, { props: PROPS })
	await flushPromises()
	return wrapper
}

/**
 * Clicks every button of the tab, the last one first, and records what it
 * emitted.
 *
 * @param {object} wrapper The mounted tab
 * @return {Promise<object>} The emitted events
 */
async function clickAll(wrapper) {
	const buttons = wrapper.findAllComponents({ name: 'NcButton' }).reverse()
	for (const button of buttons) {
		button.vm.$emit('click')
		await flushPromises()
	}
	return wrapper.emitted()
}

beforeEach(() => {
	const toLocaleString = Date.prototype.toLocaleString
	jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(function(locale, options) {
		return toLocaleString.call(this, locale, { ...options, timeZone: 'UTC' })
	})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe.each(Object.keys(STORE_TABS))('store relation tab %s', (name) => {
	const { component, items } = STORE_TABS[name]
	const states = {
		loading: { loading: true },
		failed: { fetchFails: failure },
		failedPlain: { fetchFails: new Error('boom') },
		missing: { unavailable: true },
		empty: {},
		items: { items },
	}

	it.each(Object.keys(states))('shows the state %s', async (state) => {
		const log = []
		globalThis.relationStore = relationStoreOf(states[state], log)
		const wrapper = await tab(component)
		expect({ html: dom(wrapper), log }).toMatchSnapshot()
	})

	it('unlinks and reports the new count, then the buttons', async () => {
		const log = []
		globalThis.relationStore = relationStoreOf({ items }, log)
		const wrapper = await tab(component)
		const emitted = await clickAll(wrapper)
		expect({ emitted, log, html: dom(wrapper) }).toMatchSnapshot()
	})

	it('reports a refused unlink', async () => {
		const log = []
		globalThis.relationStore = relationStoreOf(
			{ items, unlinkFails: failure },
			log,
		)
		const wrapper = await tab(component)
		const emitted = await clickAll(wrapper)
		expect({ emitted, log, html: dom(wrapper) }).toMatchSnapshot()
	})

	it('drops an earlier failure when it loads again', async () => {
		const log = []
		const state = { items, fetchFails: failure }
		globalThis.relationStore = relationStoreOf(state, log)
		const wrapper = await tab(component)
		delete state.fetchFails
		await wrapper.setProps({ objectId: 'o2' })
		await flushPromises()
		expect(wrapper.vm.$.proxy.error).toBe(false)
		expect(wrapper.vm.$.proxy.errorMessage).toBe('')
	})

	it('loads anew for another object, and not for none', async () => {
		const log = []
		globalThis.relationStore = relationStoreOf({ items }, log)
		const wrapper = await tab(component)
		await wrapper.setProps({ objectId: 'o2' })
		await flushPromises()
		await wrapper.setProps({ objectId: '' })
		await flushPromises()
		expect(log).toMatchSnapshot()
	})
})

describe.each(Object.keys(HTTP_TABS))('http relation tab %s', (name) => {
	const { component, answer, empty } = HTTP_TABS[name]

	beforeEach(() => {
		axios.get.mockReset()
		axios.delete.mockReset()
	})

	it('shows the loading state, then the items', async () => {
		let finish
		axios.get.mockReturnValue(
			new Promise((resolve) => {
				finish = resolve
			}),
		)
		const wrapper = mount(component, { props: PROPS })
		await flushPromises()
		expect(dom(wrapper)).toMatchSnapshot()
		finish(answer)
		await flushPromises()
		expect(dom(wrapper)).toMatchSnapshot()
		expect(axios.get.mock.calls).toMatchSnapshot()
	})

	it('shows the empty state', async () => {
		axios.get.mockResolvedValue(empty)
		expect(dom(await tab(component))).toMatchSnapshot()
	})

	it.each([
		['refused', failure],
		['plain', new Error('boom')],
		['missing', missing],
	])('shows the failure %s', async (kind, reason) => {
		axios.get.mockRejectedValue(reason)
		expect(dom(await tab(component))).toMatchSnapshot()
	})

	it('unlinks, or reports a refused unlink', async () => {
		axios.get.mockResolvedValue(answer)
		axios.delete.mockResolvedValue({})
		const wrapper = await tab(component)
		const emitted = await clickAll(wrapper)
		expect({
			emitted,
			deletes: axios.delete.mock.calls,
			html: dom(wrapper),
		}).toMatchSnapshot()

		axios.get.mockResolvedValue(answer)
		axios.delete.mockRejectedValue(failure)
		const second = await tab(component)
		await clickAll(second)
		expect(dom(second)).toMatchSnapshot()
	})

	it('loads anew for another object, and not for none', async () => {
		axios.get.mockResolvedValue(answer)
		const wrapper = await tab(component)
		await wrapper.setProps({ objectId: 'o2' })
		await flushPromises()
		await wrapper.setProps({ objectId: '' })
		await flushPromises()
		expect(axios.get.mock.calls).toMatchSnapshot()
	})
})
