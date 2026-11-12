import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
/**
 * Golden record of the confirm dialogs: their markup while asking, while
 * blocked, after a refusal and after success, what the buttons do and the
 * close that follows a success.
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ClearAuditTrails from './logs/ClearAuditTrails.vue'
import DeleteAuditTrail from './logs/DeleteAuditTrail.vue'
import DeleteObject from './object/DeleteObject.vue'
import DeleteOrganisation from './organisation/DeleteOrganisation.vue'
import DeleteRegister from './register/DeleteRegister.vue'
import DeleteSchema from './schema/DeleteSchema.vue'
import DeleteSource from './source/DeleteSource.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'me' }) }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () =>
	mockIcon('DeleteOutline'),
)
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockIcon('TrashCanOutline'),
)
jest.mock('../store/store.js', () => ({}))

const stores = jest.requireMock('../store/store.js')

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
 * Stores whose unknown members are logged async calls.
 *
 * @param {Array} log The log of store calls
 * @param {object} fields The store fields by store name
 * @return {void}
 */
function resetStores(log, fields) {
	const names = [
		'auditTrailStore',
		'navigationStore',
		'objectStore',
		'organisationStore',
		'registerStore',
		'schemaStore',
		'sourceStore',
	]
	names.forEach((store) => {
		stores[store] = new Proxy(fields[store] || {}, {
			get(target, prop) {
				if (prop in target) return target[prop]
				if (typeof prop !== 'string') return undefined
				return (...args) => {
					log.push([`${store}.${prop}`, ...args])
					return fields.reply?.(prop) ?? Promise.resolve({ response: { ok: true } })
				}
			},
		})
	})
}

const OBJECT = {
	'@self': { register: 1, schema: { title: 'Book' }, id: 'u1', name: 'Dune' },
}
const refused = () => Promise.reject(new Error('refused'))

const DIALOGS = {
	object: {
		component: DeleteObject,
		dialog: 'deleteObject',
		fields: {
			objectStore: {
				objectItem: OBJECT,
				objectTypes: [],
				errors: {},
				createObjectTypeSlug: () => 'slug',
			},
		},
		failing: () => ({ reply: (prop) => prop === 'deleteObject' && refused() }),
	},
	register: {
		component: DeleteRegister,
		dialog: 'deleteRegister',
		fields: { registerStore: { registerItem: { title: 'R', schemas: [] } } },
		blocked: { registerStore: { registerItem: { title: 'R', schemas: [1, 2] } } },
		failing: () => ({ reply: (prop) => prop === 'deleteRegister' && refused() }),
	},
	organisation: {
		component: DeleteOrganisation,
		dialog: 'deleteOrganisation',
		fields: {
			organisationStore: {
				organisationItem: { name: 'O', owner: 'me', users: [1], uuid: 'a' },
				userStats: { active: { uuid: 'b' } },
			},
		},
		blocked: {
			organisationStore: {
				organisationItem: {
					name: 'O',
					owner: 'other',
					users: [1, 2],
					uuid: 'a',
					isDefault: true,
				},
				userStats: { active: { uuid: 'a' } },
			},
		},
		failing: () => ({
			reply: (prop) => prop === 'deleteOrganisation' && refused(),
		}),
	},
	source: {
		component: DeleteSource,
		dialog: 'deleteSource',
		fields: { sourceStore: { sourceItem: { title: 'S' } } },
		failing: () => ({ reply: (prop) => prop === 'deleteSource' && refused() }),
	},
	schema: {
		component: DeleteSchema,
		dialog: 'deleteSchema',
		fields: {
			schemaStore: { schemaItem: { id: 3, title: 'S' } },
			registerStore: { registerList: [] },
		},
		failing: () => ({ reply: (prop) => prop === 'deleteSchema' && refused() }),
	},
	auditTrail: {
		component: DeleteAuditTrail,
		dialog: 'deleteAuditTrail',
		fields: {
			auditTrailStore: {
				auditTrailItem: {
					id: 5,
					action: 'create',
					object: 9,
					created: '2026-01-01T00:00:00Z',
				},
			},
		},
		fetchReply: { success: true },
		failing: () => ({}),
		failingFetch: { success: false, error: 'refused' },
	},
	clear: {
		component: ClearAuditTrails,
		dialog: 'clearAuditTrails',
		fields: {
			auditTrailStore: {
				auditTrailList: [{ id: 1 }],
				auditTrailFilters: { action: 'create' },
				auditTrailPagination: { total: 3 },
				filters: { action: 'create' },
				pagination: { total: 3 },
			},
		},
		fetchReply: { success: true, deleted: 3 },
		failing: () => ({}),
		failingFetch: { success: false, error: 'refused' },
	},
}

/**
 * The dialog with its setup bindings supplied.
 *
 * @param {object} entry The table entry
 * @param {object} fields The store fields
 * @return {Promise<object>} What the run recorded
 */
async function open(entry, fields) {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const log = []
	const navigation = reactive({
		dialog: null,
		modal: null,
		setDialog(value) {
			log.push(['navigationStore.setDialog', value])
			navigation.dialog = value
		},
		setModal(value) {
			log.push(['navigationStore.setModal', value])
			navigation.modal = value
		},
	})
	resetStores(log, { ...fields, navigationStore: navigation })
	const bindings = {
		...entry.component.components,
		...stores,
		n: translatePlural,
		t: translate,
	}
	const wrapper = mount({ ...entry.component, setup: () => bindings })
	navigation.dialog = entry.dialog
	await flushPromises()
	return { log, navigation, wrapper }
}

/**
 * The markup without scoped-style ids, comment nodes and the space a button
 * text can end with, and without an attribute NcDialog does not read.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} The markup
 */
function dom(wrapper) {
	const html = wrapper
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+<\/slot-default>/g, '</slot-default>')
		.replace(/ canclose="false"/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).toContain('x-NcDialog'.toLowerCase())
	return html
}

/**
 * Clicks a button of the dialog and lets the action finish.
 *
 * @param {object} wrapper The mounted dialog
 * @param {number} index Which button
 * @return {Promise<void>}
 */
async function click(wrapper, index) {
	wrapper.findAllComponents({ name: 'NcButton' })[index].vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	jest.useFakeTimers({
		doNotFake: ['nextTick', 'setImmediate', 'queueMicrotask'],
	})
	global.fetch = jest.fn()
	const toLocaleString = Date.prototype.toLocaleString
	jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(function(locale, options) {
		return toLocaleString.call(this, locale, { ...options, timeZone: 'UTC' })
	})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe.each(Object.keys(DIALOGS))('confirm dialog %s', (name) => {
	const entry = DIALOGS[name]
	const fetchAnswers = (reply) => {
		global.fetch.mockResolvedValue({ ok: true, json: async () => reply })
	}

	it('asks', async () => {
		const { wrapper } = await open(entry, entry.fields)
		expect(dom(wrapper)).toMatchSnapshot()
	})

	it('cancels', async () => {
		const { log, wrapper } = await open(entry, entry.fields)
		log.length = 0
		await click(wrapper, 0)
		expect(log).toMatchSnapshot()
	})

	it('confirms, reports success and closes by itself', async () => {
		fetchAnswers(entry.fetchReply)
		const { log, wrapper } = await open(entry, entry.fields)
		log.length = 0
		await click(wrapper, 1)
		expect(dom(wrapper)).toMatchSnapshot()
		const beforeClose = log.length
		jest.advanceTimersByTime(2100)
		await flushPromises()
		expect({ calls: log, afterTimer: log.slice(beforeClose) }).toMatchSnapshot()
	})

	it('closed by hand after a success, stops its timer and asks again', async () => {
		fetchAnswers(entry.fetchReply)
		const { log, navigation, wrapper } = await open(entry, entry.fields)
		await click(wrapper, 1)
		await click(wrapper, 0)
		const closes = log.length
		jest.advanceTimersByTime(2100)
		await flushPromises()
		expect(log.slice(closes)).toEqual([])
		navigation.dialog = entry.dialog
		await flushPromises()
		expect(dom(wrapper)).toMatchSnapshot()
	})

	it('reports a refusal', async () => {
		fetchAnswers(entry.failingFetch)
		const { wrapper } = await open(entry, {
			...entry.fields,
			...entry.failing(),
		})
		await click(wrapper, 1)
		expect(dom(wrapper)).toMatchSnapshot()
	})

	it('shows the blocked state', async () => {
		if (!entry.blocked) return
		const { wrapper } = await open(entry, entry.blocked)
		expect(dom(wrapper)).toMatchSnapshot()
		expect(wrapper.findAllComponents({ name: 'NcButton' }).length).toMatchSnapshot()
	})

	it('shows the busy state', async () => {
		const { wrapper } = await open(entry, entry.fields)
		wrapper.vm.$.proxy.loading = true
		await flushPromises()
		expect(dom(wrapper)).toMatchSnapshot()
	})
})
