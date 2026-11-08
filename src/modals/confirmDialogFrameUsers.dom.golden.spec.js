import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
/**
 * Golden record of the delete, remove and confirm dialogs that ask once
 * before an action: their markup while asking, while busy, after a refusal
 * and after success, and what their buttons do (store calls and events).
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RemoveConnectionDialog from '../dialogs/mail/RemoveConnectionDialog.vue'
import DeleteApplication from './application/DeleteApplication.vue'
import DeleteConfiguration from './configuration/DeleteConfiguration.vue'
import DeleteEndpoint from './endpoint/DeleteEndpoint.vue'
import RemoveUserDialog from './organisation/RemoveUserDialog.vue'
import ClearCacheModal from './settings/ClearCacheModal.vue'
import RebaseConfirmationModal from './settings/RebaseConfirmationModal.vue'
import DeleteSchemaObjects from './schema/DeleteSchemaObjects.vue'
import DeleteSchemaProperty from './schema/DeleteSchemaProperty.vue'
import DeleteView from './view/DeleteView.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountMinus.vue', () => mockIcon('AccountMinus'))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => mockIcon('AlertCircle'))
jest.mock('vue-material-design-icons/AlertOutline.vue', () => mockIcon('AlertOutline'))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/Close.vue', () => mockIcon('Close'))
jest.mock('vue-material-design-icons/Delete.vue', () => mockIcon('Delete'))
jest.mock('vue-material-design-icons/DeleteSweep.vue', () => mockIcon('DeleteSweep'))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => mockIcon('LinkVariantOff'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockIcon('Refresh'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockIcon('TrashCanOutline'),
)
jest.mock('../components/SchemaStatsBlock.vue', () => mockIcon('SchemaStatsBlock'))
jest.mock('../store/store.js', () => mockStores())

/** The store fields of the running test, by store name. */
const mockFields = {}
/** The store calls of the running test. */
const mockLog = []

/**
 * Stores whose unknown members are logged async calls; their fields come
 * from `mockFields` at the time they are read.
 *
 * @return {object} The module
 */
function mockStores() {
	const names = [
		'applicationStore',
		'configurationStore',
		'endpointStore',
		'navigationStore',
		'objectStore',
		'registerStore',
		'schemaStore',
		'viewsStore',
	]
	return Object.fromEntries(
		names.map((store) => [
			store,
			new Proxy(
				{},
				{
					get(target, prop) {
						const fields = mockFields[store] || {}
						if (prop in fields) return fields[prop]
						if (typeof prop !== 'string' || prop.startsWith('__')) {
							return undefined
						}
						return (...args) => {
							mockLog.push([`${store}.${prop}`, ...args])
							return (
								mockFields.reply?.(store, prop)
								?? Promise.resolve({ response: { ok: true } })
							)
						}
					},
				},
			),
		]),
	)
}

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
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [
						key,
						typeof value === 'object' && value !== null
							? JSON.stringify(value)
							: value,
					]),
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
 * A store reply refusing one store call.
 *
 * @param {string} store The store
 * @param {string} prop The call
 * @return {Function} The reply
 */
function refused(store, prop) {
	return (_store, _prop) =>
		_store === store && _prop === prop
			? Promise.reject(new Error('refused'))
			: undefined
}

/**
 * A fresh schema: deleting a property changes the one the dialog was given.
 *
 * @return {object} The schema
 */
function schema() {
	return {
		id: 3,
		title: 'Books',
		properties: { isbn: { title: 'ISBN' } },
		required: ['isbn'],
	}
}

/**
 * Each dialog: how it opens, its store fields, the props a parent passes
 * and how its action is refused.
 */
const DIALOGS = {
	endpoint: {
		component: DeleteEndpoint,
		open: { dialog: 'deleteEndpoint' },
		fields: { endpointStore: { endpointItem: { name: 'E' } } },
		failing: refused('endpointStore', 'deleteEndpoint'),
	},
	application: {
		component: DeleteApplication,
		open: { dialog: 'deleteApplication' },
		fields: { applicationStore: { applicationItem: { name: 'A' } } },
		failing: refused('applicationStore', 'deleteApplication'),
	},
	configuration: {
		component: DeleteConfiguration,
		open: { dialog: 'deleteConfiguration' },
		fields: { configurationStore: { configurationItem: { title: 'C' } } },
		failing: refused('configurationStore', 'deleteConfiguration'),
	},
	view: {
		component: DeleteView,
		props: { view: { id: 7, name: 'V' } },
		fields: {},
		failing: refused('viewsStore', 'deleteView'),
	},
	removeUser: {
		component: RemoveUserDialog,
		props: { show: true, userId: 'alice' },
		busyProps: { removing: true },
		fields: {},
	},
	removeConnection: {
		component: RemoveConnectionDialog,
		props: { show: true, name: 'Dune' },
		busyProps: { removing: true },
		fields: {},
	},
	rebaseModal: {
		component: RebaseConfirmationModal,
		props: { show: true },
		busyProps: { rebasing: true },
		fields: {},
	},
	clearCacheModal: {
		component: ClearCacheModal,
		props: { show: true, cacheType: 'facet' },
		busyProps: { clearing: true },
		fields: {},
	},
	schemaProperty: {
		component: DeleteSchemaProperty,
		open: { modal: 'deleteSchemaProperty' },
		get fields() {
			return {
			schemaStore: { schemaItem: schema(), schemaPropertyKey: 'isbn' },
			registerStore: { registerList: [] },
			}
		},
		failing: refused('schemaStore', 'saveSchema'),
	},
	schemaObjects: {
		component: DeleteSchemaObjects,
		open: { dialog: 'deleteSchemaObjects' },
		get fields() {
			return {
			schemaStore: { schemaItem: schema() },
			registerStore: { registerList: [{ id: 1, schemas: [{ id: 3 }] }] },
			reply: (store, prop) =>
				store === 'schemaStore' && prop === 'getSchemaStats'
					? Promise.resolve({ objects: { total: 4, deleted: 1 } })
					: undefined,
			}
		},
	},
}

/**
 * Mounts a dialog with its store fields and opens it.
 *
 * @param {object} entry The table entry
 * @param {object} fields The store fields
 * @param {object} props Extra props
 * @return {Promise<object>} The wrapper
 */
async function open(entry, fields, props = {}) {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	mockLog.length = 0
	Object.keys(mockFields).forEach((key) => delete mockFields[key])
	const navigation = reactive({
		dialog: null,
		modal: null,
		setDialog(value) {
			mockLog.push(['navigationStore.setDialog', value])
			navigation.dialog = value
		},
		setModal(value) {
			mockLog.push(['navigationStore.setModal', value])
			navigation.modal = value
		},
	})
	Object.assign(mockFields, fields, { navigationStore: navigation })
	// A <script setup> block's bindings do not reach the stubs under
	// vue3-jest, so the test supplies them, as the app's build does.
	const bindings = {
		...entry.component.components,
		...jest.requireMock('../store/store.js'),
		n: translatePlural,
		t: translate,
	}
	const component = entry.component.setup
		? { ...entry.component, setup: () => bindings }
		: entry.component
	const wrapper = mount(component, {
		props: { ...entry.props, ...props },
		global: {
			config: { warnHandler: () => {}, errorHandler: (e) => { throw e } },
			mocks: { t: translate, n: translatePlural },
		},
	})
	Object.assign(navigation, entry.open || {})
	await flushPromises()
	return wrapper
}

/**
 * The markup without scoped-style ids, comment nodes and blank lines.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} The markup
 */
function dom(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

/**
 * The buttons of the dialog footer, in order.
 *
 * @param {object} wrapper The mounted dialog
 * @return {Array} The NcButton wrappers
 */
function buttons(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' })
}

/**
 * Clicks a footer button and lets the action finish.
 *
 * @param {object} wrapper The mounted dialog
 * @param {number} index Which button
 * @return {Promise<void>}
 */
async function click(wrapper, index) {
	buttons(wrapper)[index].vm.$emit('click')
	await flushPromises()
}

/**
 * What the run recorded: store calls and emitted events.
 *
 * @param {object} wrapper The mounted dialog
 * @return {object} The record
 */
function record(wrapper) {
	const emitted = Object.fromEntries(
		Object.entries(wrapper.emitted()).filter(
			([name]) => !['click'].includes(name),
		),
	)
	return { calls: [...mockLog], emitted }
}

beforeEach(() => {
	jest.useFakeTimers({
		doNotFake: ['nextTick', 'setImmediate', 'queueMicrotask'],
	})
	global.fetch = jest.fn().mockResolvedValue({
		ok: true,
		json: async () => ({ deleted_count: 4 }),
	})
	jest.spyOn(console, 'error').mockImplementation(() => {})
	global.OCP = { Toast: { success: jest.fn(), error: jest.fn() } }
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe.each(Object.keys(DIALOGS))('dialog %s', (name) => {
	const entry = DIALOGS[name]

	it('asks', async () => {
		const wrapper = await open(entry, entry.fields)
		expect(dom(wrapper)).toMatchSnapshot()
	})

	it('cancels', async () => {
		const wrapper = await open(entry, entry.fields)
		mockLog.length = 0
		await click(wrapper, 0)
		expect(record(wrapper)).toMatchSnapshot()
	})

	it('dismisses through the dialog itself', async () => {
		const wrapper = await open(entry, entry.fields)
		mockLog.length = 0
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		dialog.vm.$emit('closing')
		dialog.vm.$emit('update:open', false)
		await flushPromises()
		expect(record(wrapper)).toMatchSnapshot()
	})

	it('confirms', async () => {
		const wrapper = await open(entry, entry.fields)
		mockLog.length = 0
		await click(wrapper, buttons(wrapper).length - 1)
		expect(dom(wrapper)).toMatchSnapshot()
		const before = mockLog.length
		jest.advanceTimersByTime(2100)
		await flushPromises()
		expect({
			...record(wrapper),
			afterTimer: mockLog.slice(before),
		}).toMatchSnapshot()
	})

	it('reports a refusal', async () => {
		if (!entry.failing) return
		const wrapper = await open(entry, { ...entry.fields, reply: entry.failing })
		await click(wrapper, buttons(wrapper).length - 1)
		expect(dom(wrapper)).toMatchSnapshot()
	})

	it('shows the busy state', async () => {
		const wrapper = await open(entry, entry.fields, entry.busyProps)
		if (!entry.busyProps) {
			wrapper.vm.$.proxy.loading = true
			await flushPromises()
		}
		expect(dom(wrapper)).toMatchSnapshot()
	})
})

describe('dialog schemaObjects with a failing request', () => {
	it('reports the refusal', async () => {
		global.fetch.mockResolvedValue({ ok: false, statusText: 'Forbidden' })
		const entry = DIALOGS.schemaObjects
		const wrapper = await open(entry, entry.fields)
		await click(wrapper, 1)
		expect(dom(wrapper)).toMatchSnapshot()
	})
})

describe('dialog schemaProperty blocked by objects that use it', () => {
	it('disables the confirm button', async () => {
		const entry = DIALOGS.schemaProperty
		const wrapper = await open(entry, entry.fields)
		wrapper.vm.$.proxy.objects = [{ isbn: '1' }]
		await flushPromises()
		expect(dom(wrapper)).toMatchSnapshot()
	})
})
