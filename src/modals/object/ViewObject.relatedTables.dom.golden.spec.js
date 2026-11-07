/**
 * Golden record of the Uses, Used by and Contracts tabs of the object modal:
 * what each renders with and without related objects, and that each row's
 * View Object button selects that object and reopens the modal.
 *
 * @spec exclude Golden record of client-side related-object table markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ViewObject from './ViewObject.vue'

jest.mock('../../store/store.js', () => mockStores())
jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(),
	jsonParseLinter: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({ showWarning: jest.fn() }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
// The modal mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module, as in the list
// page goldens.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcActionButton: mockLibrary().NcActionButton,
	NcActions: mockLibrary().NcActions,
	NcButton: mockLibrary().NcButton,
	NcCheckboxRadioSwitch: mockLibrary().NcCheckboxRadioSwitch,
	NcCounterBubble: mockLibrary().NcCounterBubble,
	NcDateTimePickerNative: mockLibrary().NcDateTimePickerNative,
	NcDialog: mockLibrary().NcDialog,
	NcEmptyContent: mockLibrary().NcEmptyContent,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
	NcTextField: mockLibrary().NcTextField,
}))
jest.mock('vue-codemirror6', () => mockIcon('CodeMirror'))
jest.mock('vue-material-design-icons/Alert.vue', () => mockIcon('Alert'))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => mockIcon('AlertCircle'))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => mockIcon('ArrowRight'))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/Check.vue', () => mockIcon('Check'))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => mockIcon('ContentCopy'))
jest.mock('vue-material-design-icons/ContentSave.vue', () => mockIcon('ContentSave'))
jest.mock('vue-material-design-icons/Delete.vue', () => mockIcon('Delete'))
jest.mock('vue-material-design-icons/ExclamationThick.vue', () => mockIcon('ExclamationThick'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockIcon('Eye'))
jest.mock('vue-material-design-icons/FileOutline.vue', () => mockIcon('FileOutline'))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => mockIcon('FormatListChecks'))
jest.mock('vue-material-design-icons/LockOutline.vue', () => mockIcon('LockOutline'))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => mockIcon('OpenInNew'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('vue-material-design-icons/Tag.vue', () => mockIcon('Tag'))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => mockIcon('TextBoxOutline'))
jest.mock('vue-material-design-icons/Upload.vue', () => mockIcon('Upload'))
jest.mock('../../components/i18n/TranslationFieldEditor.vue', () => mockIcon('TranslationFieldEditor'))
jest.mock('../../components/object-relations/ContactsTab.vue', () => mockIcon('ContactsTab'))
jest.mock('../../components/object-relations/DeckTab.vue', () => mockIcon('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () => mockIcon('EmailsTab'))
jest.mock('../../components/object-relations/EventsTab.vue', () => mockIcon('EventsTab'))
jest.mock('../../components/object-relations/RelationsTab.vue', () => mockIcon('RelationsTab'))
jest.mock('../../components/PaginationComponent.vue', () => mockIcon('PaginationComponent'))
jest.mock('../../components/tabs/AppTab.vue', () => mockTab())
jest.mock('../../components/tabs/AppTabs.vue', () => mockIcon('AppTabs'))

/**
 * Stores the modal reads: a reactive state, and a recording stub for any
 * action the modal calls.
 *
 * @return {object} The module
 */
function mockStores() {
	const { reactive } = jest.requireActual('vue')
	const store = (state) => {
		const target = reactive(state)
		return new Proxy(target, {
			get(obj, key) {
				if (typeof key === 'string' && !key.startsWith('__v') && !(key in obj)) {
					obj[key] = jest.fn(() => Promise.resolve(null))
				}
				return obj[key]
			},
		})
	}
	return {
		objectStore: store({
			objectItem: null,
			uses: { results: [] },
			used: { results: [] },
			contracts: [],
			files: { results: [] },
			objectTypes: [],
			availableRegistersForNewObject: [],
			availableSchemasForNewObject: [],
		}),
		navigationStore: store({ modal: 'viewObject' }),
		registerStore: store({ registerItem: null }),
		schemaStore: store({ schemaItem: null, schemaList: [] }),
		dashboardStore: store({}),
		organisationStore: store({}),
	}
}

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = (globalThis.madeStubs = globalThis.madeStubs || {})
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
 * A stand-in tab that renders its content only for the related-object tabs,
 * so the other tabs' content stays out of this record.
 *
 * @return {object} The module
 */
function mockTab() {
	const { h: render } = jest.requireActual('vue')
	const shown = ['t:Uses', 't:Used by', 't:Contracts']
	return {
		__esModule: true,
		default: {
			name: 'AppTab',
			props: { title: { type: String, default: '' } },
			render() {
				return render(
					'x-AppTab',
					{ title: this.title },
					shown.includes(this.title) ? this.$slots.default() : [],
				)
			},
		},
	}
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots. Click
 * listeners are kept so the golden can press buttons.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = {}
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (key === 'onClick') {
					attrs.onClick = value
				} else if (!/^on[A-Z]/.test(key) && typeof value !== 'function') {
					attrs[key] = value
				}
			}
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

const { objectStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * A related object as the store lists it.
 *
 * @param {string} id The object id
 * @return {object} The object
 */
function related(id) {
	return {
		id,
		'@self': {
			id,
			uri: `/uri/${id}`,
			schema: `schema-${id}`,
			register: `register-${id}`,
		},
	}
}

/**
 * The three related-object tabs of the mounted modal.
 *
 * @param {object} wrapper The mounted modal
 * @return {Array} The tab wrappers
 */
function relatedTabs(wrapper) {
	return wrapper
		.findAll('x-AppTab')
		.filter((tab) =>
			['t:Uses', 't:Used by', 't:Contracts'].includes(tab.attributes('title')),
		)
}

/**
 * Mount the modal over a saved object with the given related objects.
 *
 * @param {object} state uses, used and contracts
 * @return {Promise<object>} The wrapper
 */
async function mountWith(state) {
	objectStore.objectItem = {
		id: 'obj-1',
		'@self': { id: 'obj-1', register: 1, schema: 2 },
	}
	objectStore.uses = { results: state.uses }
	objectStore.used = { results: state.used }
	objectStore.contracts = state.contracts
	navigationStore.modal = 'viewObject'
	const wrapper = mount(ViewObject, {
		global: { mocks: { t: (app, text) => `t:${text}`, n: (app, one) => one } },
	})
	await flushPromises()
	return wrapper
}

describe('ViewObject related-object tabs golden', () => {
	beforeEach(() => jest.clearAllMocks())

	it('renders the empty notes', async () => {
		const wrapper = await mountWith({ uses: [], used: [], contracts: [] })
		const tabs = relatedTabs(wrapper)
		expect(tabs).toHaveLength(3)
		expect(tabs.map((tab) => tab.html())).toMatchSnapshot()
	})

	it('renders a row per related object and opens it', async () => {
		const wrapper = await mountWith({
			uses: [related('u1'), related('u2')],
			used: [related('b1')],
			contracts: [related('c1'), related('c2')],
		})
		const tabs = relatedTabs(wrapper)
		expect(tabs).toHaveLength(3)
		const html = tabs.map((tab) => tab.html())
		expect(html.join('')).toContain('/uri/c2')
		expect(html).toMatchSnapshot()

		const pressed = []
		for (const tab of tabs) {
			for (const button of tab.findAll('x-NcButton')) {
				objectStore.setObjectItem.mockClear()
				navigationStore.setModal.mockClear()
				await button.trigger('click')
				pressed.push({
					selected: objectStore.setObjectItem.mock.calls.map(
						(call) => call[0].id,
					),
					modal: navigationStore.setModal.mock.calls,
				})
			}
		}
		expect(pressed).toHaveLength(5)
		expect(pressed).toMatchSnapshot()
		expect(Object.keys(wrapper.emitted())).toMatchSnapshot()
	})
})
