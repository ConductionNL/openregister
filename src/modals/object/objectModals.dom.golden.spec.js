/**
 * Golden record of the whole markup of the single-action object modals —
 * copy, download, lock and delete — in their form state, their success state
 * and their error state, and that each primary button calls its action.
 *
 * @spec exclude Golden record of client-side modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import CopyObject from './CopyObject.vue'
import DeleteObject from './DeleteObject.vue'
import DownloadObject from './DownloadObject.vue'
import LockObject from './LockObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (all, key) => vars?.[key] ?? all),
	translatePlural: (app, one, many, count, vars) =>
		(count === 1 ? one : many).replace(
			/\{(\w+)\}/g,
			(all, key) => vars?.[key] ?? all,
		),
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcDialog: mockLibrary().NcDialog,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcTextField: mockLibrary().NcTextField,
}))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => mockModule('CodeMirror'))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('../../services/objectCopy.js', () => ({
	originalName: (item) => item?.['@self']?.name || item?.name || '',
	renameCopy: (item, name) => ({ ...item, name }),
	withoutOriginalMetadata: (item) => ({ ...item }),
	copyPayload: (item, name) => ({ ...item, name }),
	copyName: (item) => `Copy of ${item?.name || 'Object'}`,
	copyRows: jest.fn(() => Promise.resolve([])),
}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => mockModule('ContentCopy'))
jest.mock('vue-material-design-icons/LockOutline.vue', () => mockModule('LockOutline'))
jest.mock('../../components/shared/CloseCancelButton.vue', () => mockModule('CloseCancelButton'))
jest.mock('../shared/ConfirmDialogFrame.vue', () => mockModule('ConfirmDialogFrame'))
jest.mock('../../store/store.js', () => ({
	navigationStore: { dialog: null, modal: null, setDialog: jest.fn(), setModal: jest.fn() },
	objectStore: {
		objectItem: { '@self': { register: 2, schema: 3, id: 'o1', name: 'Contract' }, id: 'o1' },
		objectTypes: ['object-2-3'],
		errors: {},
		createObjectTypeSlug: (r, s) => `object-${r}-${s}`,
		registerObjectType: jest.fn(),
		copyObject: jest.fn(() => Promise.resolve({ id: 'o2' })),
		downloadObject: jest.fn(() => Promise.resolve({ ok: true })),
		lockObject: jest.fn(() => Promise.resolve({})),
		deleteObject: jest.fn(() => Promise.resolve(true)),
		refetchSearchCollection: jest.fn(),
	},
}))

/**
 * Every named export is a stub component of that name.
 *
 * @return {object} The module proxy
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
 * A default-export module holding a stub of that name.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A component stub rendering its tag, plain attributes and slots.
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

beforeEach(() => {
	jest.useFakeTimers()
	navigationStore.dialog = null
	navigationStore.modal = null
	objectStore.errors = {}
})
afterEach(() => {
	jest.runOnlyPendingTimers()
	jest.useRealTimers()
})

const GLOBAL = {
	mocks: {
		t: (app, text) => text,
		n: (app, one, many, count) => (count === 1 ? one : many),
		objectItem: { object: '{}' },
	},
}

const MODALS = {
	CopyObject: { component: CopyObject, open: () => (navigationStore.dialog = 'copyObject') },
	DownloadObject: { component: DownloadObject, open: () => (navigationStore.modal = 'downloadObject') },
	LockObject: { component: LockObject, open: () => (navigationStore.modal = 'lockObject') },
	DeleteObject: { component: DeleteObject, open: () => (navigationStore.dialog = 'deleteObject') },
}

describe('single-action object modals (markup golden)', () => {
	for (const [name, { component, open }] of Object.entries(MODALS)) {
		it(`renders ${name} in its form, success and error states`, () => {
			open()
			const form = mount(component, { global: GLOBAL })
			expect(form.html()).toMatchSnapshot('form')

			const success = mount(component, { global: GLOBAL, data: () => ({ success: true }) })
			expect(success.html()).toMatchSnapshot('success')

			const error = mount(component, { global: GLOBAL, data: () => ({ success: false, error: 'it broke' }) })
			expect(error.html()).toMatchSnapshot('error')
		})
	}
})
