/**
 * Golden record of the selected objects block in the bulk object dialogs:
 * the markup with objects, without them, and what removing an object does.
 *
 * @spec exclude Golden record of client-side dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import MassDeleteObject from './MassDeleteObject.vue'
import MassValidateObjects from './MassValidateObjects.vue'
import MigrationObject from './MigrationObject.vue'

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
 * The selected objects block.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} The markup
 */
function block(wrapper) {
	const html = wrapper
		.find('.selected-objects-container')
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+/g, ' ')
		.replace(/> /g, '>')
		.replace(/> ?</g, '>\n<')
	expect(html).not.toBe('')
	return html
}

describe.each([
	['MassDeleteObject', MassDeleteObject],
	['MassValidateObjects', MassValidateObjects],
	['MigrationObject', MigrationObject],
])('selected objects in %s', (name, component) => {
	it('lists the objects', async () => {
		expect(block(await open(component, [...OBJECTS]))).toMatchSnapshot()
	})

	it('removes the object whose button is clicked', async () => {
		const wrapper = await open(component, [...OBJECTS])
		wrapper
			.find('.selected-objects-container')
			.findAllComponents({ name: 'NcButton' })[1]
			.vm.$attrs.onClick()
		await flushPromises()
		expect({
			shown: wrapper.vm.$.proxy.selectedObjects.map((obj) => obj.id),
			stored: objectStore.selectedObjects,
			html: block(wrapper),
		}).toMatchSnapshot()
	})
})
