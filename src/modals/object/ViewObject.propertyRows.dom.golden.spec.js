/**
 * Golden record of the object modal's Properties tab (stored and edited
 * values of every display kind) and of the five object-relation tabs.
 *
 * @spec exclude Golden record of client-side property-table markup.
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
 * A stand-in tab that renders its content only for the Properties tab and
 * the object-relation tabs, so the other tabs' content stays out of this
 * record.
 *
 * @return {object} The module
 */
function mockTab() {
	const { h: render } = jest.requireActual('vue')
	const shown = ['t:Properties', 'Emails', 'Events', 'Contacts', 'Deck', 'Relations']
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
 * Mount the modal over a saved object with a value of every display kind.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountObject() {
	objectStore.objectItem = {
		id: 'obj-1',
		'@self': { id: 'obj-1', register: 1, schema: 2 },
		name: 'Alpha',
		count: 3,
		meta: { a: 1 },
		when: '2024-01-02T03:04:05.000Z',
		flag: true,
		empty: null,
	}
	navigationStore.modal = 'viewObject'
	const wrapper = mount(ViewObject, {
		global: { mocks: { t: (app, text) => `t:${text}`, n: (app, one) => one } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The tabs of the mounted modal with the given titles.
 *
 * @param {object} wrapper The mounted modal
 * @param {Array<string>} titles The tab titles
 * @return {Array<string>} Their HTML, without the template comments a
 *   production build strips and the whitespace between tags that the
 *   pretty-printer adds around template fragments
 */
function tabsTitled(wrapper, titles) {
	return wrapper
		.findAll('x-AppTab')
		.filter((tab) => titles.includes(tab.attributes('title')))
		.map((tab) =>
			tab
				.html()
				.replace(/<!--(?!v-if)[^>]*-->/g, '')
				.replace(/>\s+</g, '><'),
		)
}

describe('ViewObject property rows and relation tabs golden', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		jest
			.spyOn(Date.prototype, 'toLocaleString')
			.mockImplementation(function() {
				return `local:${this.toISOString()}`
			})
	})

	afterEach(() => jest.restoreAllMocks())

	it('renders the stored values', async () => {
		const wrapper = await mountObject()
		// The modal mixes <script setup> with <script>, so its options data
		// is reached through the internal instance.
		wrapper.vm.$.data.formData = {}
		await flushPromises()
		expect(tabsTitled(wrapper, ['t:Properties'])).toMatchSnapshot()
	})

	it('renders edited values marked as edited', async () => {
		const wrapper = await mountObject()
		wrapper.vm.$.data.formData = {
			name: 'Beta',
			meta: { b: 2 },
			when: '2025-06-07T08:09:10.000Z',
			count: 0,
		}
		await flushPromises()
		expect(tabsTitled(wrapper, ['t:Properties'])).toMatchSnapshot()
	})

	it('renders the five relation tabs in order for a saved object', async () => {
		const wrapper = await mountObject()
		const titles = ['Emails', 'Events', 'Contacts', 'Deck', 'Relations']
		const tabs = tabsTitled(wrapper, titles)
		expect(tabs).toHaveLength(5)
		expect(
			wrapper
				.findAll('x-AppTab')
				.map((tab) => tab.attributes('title'))
				.filter((title) => titles.includes(title)),
		).toEqual(titles)
		expect(tabs).toMatchSnapshot()
	})
})
