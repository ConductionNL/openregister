/**
 * Golden record of the whole markup of the mass delete and mass validate
 * dialogs: before the action, while it runs, after success and after an error.
 *
 * @spec exclude Golden record of client-side dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import MassDeleteObject from './MassDeleteObject.vue'
import MassValidateObjects from './MassValidateObjects.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (all, key) => vars?.[key] ?? all),
	translatePlural: (app, one, many, count, vars) =>
		(count === 1 ? one : many).replace(
			/\{(\w+)\}/g,
			(all, key) => vars?.[key] ?? all,
		),
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(() => Promise.resolve({ data: {} })) },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcDialog: mockLibrary().NcDialog,
	NcEmptyContent: mockLibrary().NcEmptyContent,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
	NcCheckboxRadioSwitch: mockLibrary().NcCheckboxRadioSwitch,
}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockIcon('ArrowLeft'))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => mockIcon('ArrowRight'))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => mockIcon('CheckCircle'))
jest.mock('vue-material-design-icons/Close.vue', () => mockIcon('Close'))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () =>
	mockIcon('DatabaseExport'),
)
jest.mock('vue-material-design-icons/Delete.vue', () => mockIcon('Delete'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockIcon('TrashCanOutline'),
)
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () =>
	mockIcon('DatabaseOutline'),
)
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () =>
	mockIcon('FileTreeOutline'),
)
jest.mock('../../store/store.js', () => ({
	navigationStore: {
		setDialog: jest.fn(),
		setModal: jest.fn(),
		dialog: null,
		modal: null,
	},
	objectStore: {
		selectedObjects: [],
		searchCollection: [],
		setSelectedObjects: jest.fn(),
	},
	registerStore: { registerList: [], registerItem: { id: 1 } },
	schemaStore: { schemaList: [], schemaItem: { id: 1 } },
}))

const { objectStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.madeStubs = globalThis.madeStubs || {}
	const made = globalThis.madeStubs
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
 * An icon module.
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
 * @param {string} name The component
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
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

const OBJECTS = [
	{ id: 'a', '@self': { id: 'a', name: 'Named' } },
	{ id: 'b', title: 'Titled' },
	{ id: 'c' },
]

/**
 * Mounts a dialog on the given selection.
 *
 * @param {object} component The dialog
 * @param {Array} selection The selected objects
 * @return {Promise<object>} The wrapper
 */
async function open(component, selection) {
	objectStore.selectedObjects = selection
	const wrapper = mount(component, {
		global: {
			mocks: { $router: { push: jest.fn() } },
			config: { warnHandler: () => {} },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The dialog markup, without scope ids and comments.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+/g, ' ')
		.replace(/> /g, '>')
		.replace(/> ?</g, '>\n<')
}

const STATES = {
	review: {},
	running: { loading: true },
	succeeded: { success: true, validatedCount: 3 },
	partlyFailed: { success: true, error: 'Two failed', validatedCount: 1 },
	failed: { success: false, error: true },
}

describe.each([
	['MassDeleteObject', MassDeleteObject],
	['MassValidateObjects', MassValidateObjects],
])('%s markup', (name, component) => {
	it.each(Object.keys(STATES))('renders the %s state', async (state) => {
		const wrapper = await open(component, [...OBJECTS])
		Object.assign(wrapper.vm.$.proxy, STATES[state])
		await flushPromises()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('renders an empty selection', async () => {
		expect(markup(await open(component, []))).toMatchSnapshot()
	})
})
