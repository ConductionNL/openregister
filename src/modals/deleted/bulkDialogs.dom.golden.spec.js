/**
 * Golden record of the rendered DOM of the bulk purge and restore dialogs in
 * each of their states: reviewing a selection, nothing selected, busy,
 * finished and failed.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import PurgeMultiple from './PurgeMultiple.vue'
import RestoreMultiple from './RestoreMultiple.vue'
import { deletedStore, navigationStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	deletedStore: { clearSelectedForBulkAction: () => {} },
	navigationStore: { setDialog: () => {} },
}))
jest.mock('../../eventBus.js', () => ({ emit: () => {} }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one, many, count, vars) =>
		`${count === 1 ? one : many} ${JSON.stringify(vars)}`,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcDialog: mockStub('NcDialog'),
	NcButton: mockStub('NcButton'),
	NcEmptyContent: mockStub('NcEmptyContent'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcNoteCard: mockStub('NcNoteCard'),
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: mockStub('Cancel'),
}))
jest.mock('vue-material-design-icons/Close.vue', () => ({
	__esModule: true,
	default: mockStub('Close'),
}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: mockStub('TrashCanOutline'),
}))
jest.mock('vue-material-design-icons/Restore.vue', () => ({
	__esModule: true,
	default: mockStub('Restore'),
}))

/**
 * A stand-in for a library component: its tag, attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const listeners = Object.keys(attrs).filter((key) =>
					/^on[A-Z]/.test(key),
				)
				const plain = Object.fromEntries(
					Object.entries(attrs).filter(
						([key]) => !listeners.includes(key),
					),
				)
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill()),
					),
				)
			}
		},
	}
}

/**
 * The component with its setup bindings replaced by stand-ins. Under jest
 * the compiled bindings of an SFC that imports from both 'vue' and
 * '@nextcloud/vue' resolve the library components to undefined, so they
 * are supplied here.
 *
 * @param {object} component The dialog
 * @return {object} The dialog with stand-in bindings
 */
function withStubs(component) {
	const l10n = jest.requireMock('@nextcloud/l10n')
	const stores = jest.requireMock('../../store/store.js')
	const names = [
		'NcDialog',
		'NcButton',
		'NcEmptyContent',
		'NcLoadingIcon',
		'NcNoteCard',
		'Cancel',
		'Close',
		'TrashCanOutline',
		'Restore',
	]
	const bindings = {
		...Object.fromEntries(names.map((name) => [name, mockStub(name)])),
		...sharedDialog(),
		eventBus: { emit: () => {} },
		t: l10n.translate,
		n: l10n.translatePlural,
		deletedStore: stores.deletedStore,
		navigationStore: stores.navigationStore,
	}
	return { ...component, setup: () => bindings }
}

/**
 * The shared dialog body, as a binding, when the codebase has one.
 *
 * @return {object} The binding, or nothing
 */
function sharedDialog() {
	try {
		return {
			BulkSelectionDialog: require('./BulkSelectionDialog.vue').default,
			reviewStateOf: require('./bulkSelection.js').reviewStateOf,
		}
	} catch {
		return {}
	}
}

const OBJECTS = [
	{ id: 'a', title: 'First' },
	{ id: 'b', fileName: 'two.pdf' },
	{ id: 'c', object: { name: 'Nested' } },
	{ id: 'd' },
]

const STATES = {
	review: {},
	nothingSelected: { selectedObjects: [] },
	busy: { loading: true },
	finished: { success: true, successMessage: 'All done' },
	failed: { success: false, error: 'It broke' },
}

const DIALOGS = {
	purge: [PurgeMultiple, 'permanentlyDeleteMultiple'],
	restore: [RestoreMultiple, 'restoreMultiple'],
}

/**
 * The markup without the scoped-style ids, which depend on the file name.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html.replace(/ data-v-[a-f0-9]+(="")?/g, '')
}

describe('bulk dialogs DOM golden', () => {
	for (const [name, [component, dialog]] of Object.entries(DIALOGS)) {
		for (const [state, data] of Object.entries(STATES)) {
			it(`${name}: ${state}`, async () => {
				navigationStore.dialog = dialog
				deletedStore.selectedForBulkAction = OBJECTS
				const wrapper = mount(withStubs(component))
				await wrapper.setData({ selectedObjects: [...OBJECTS], ...data })
				expect(normalised(wrapper.html())).toMatchSnapshot()
				wrapper.unmount()
			})
		}

		it(`${name}: closed`, () => {
			navigationStore.dialog = 'other'
			deletedStore.selectedForBulkAction = OBJECTS
			const wrapper = mount(withStubs(component))
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})
	}
})
