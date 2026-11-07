import { compileStyle, parse } from '@vue/compiler-sfc'
import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the object modal's styles: the rule every selector ends up
 * with across both style blocks, in cascade order, and that the modal still
 * renders its dialog.
 *
 * @spec exclude Golden record of client-side modal styles.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { execFileSync } from 'child_process'
import { readFileSync } from 'fs'
import { join } from 'path'
import postcss from 'postcss'
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
jest.mock('vue-material-design-icons/ExclamationThick.vue', () =>
	mockIcon('ExclamationThick'),
)
jest.mock('vue-material-design-icons/Eye.vue', () => mockIcon('Eye'))
jest.mock('vue-material-design-icons/FileOutline.vue', () => mockIcon('FileOutline'))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () =>
	mockIcon('FormatListChecks'),
)
jest.mock('vue-material-design-icons/LockOutline.vue', () => mockIcon('LockOutline'))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => mockIcon('OpenInNew'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('vue-material-design-icons/Tag.vue', () => mockIcon('Tag'))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () =>
	mockIcon('TextBoxOutline'),
)
jest.mock('vue-material-design-icons/Upload.vue', () => mockIcon('Upload'))
jest.mock('../../components/i18n/TranslationFieldEditor.vue', () =>
	mockIcon('TranslationFieldEditor'),
)
jest.mock('../../components/object-relations/ContactsTab.vue', () =>
	mockIcon('ContactsTab'),
)
jest.mock('../../components/object-relations/DeckTab.vue', () => mockIcon('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () =>
	mockIcon('EmailsTab'),
)
jest.mock('../../components/object-relations/EventsTab.vue', () =>
	mockIcon('EventsTab'),
)
jest.mock('../../components/object-relations/RelationsTab.vue', () =>
	mockIcon('RelationsTab'),
)
jest.mock('../../components/PaginationComponent.vue', () =>
	mockIcon('PaginationComponent'),
)
jest.mock('../../components/tabs/AppTab.vue', () => mockIcon('AppTab'))
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
				if (
					typeof key === 'string'
					&& !key.startsWith('__v')
					&& !(key in obj)
				) {
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
 * Compile SCSS next to the modal. Sass runs in a plain node process: its
 * compiler does not run inside the jsdom environment.
 *
 * @param {string} scss The SCSS
 * @return {string} The CSS
 */
function compileScss(scss) {
	const script =
		"const sass = require('sass'); let s = ''; process.stdin.on('data', (c) => (s += c))"
		+ ".on('end', () => process.stdout.write(sass.compileString(s, { loadPaths: [process.argv[1]] }).css))"
	return execFileSync(process.execPath, ['-e', script, __dirname], {
		input: scss,
		encoding: 'utf8',
	})
}

/**
 * The CSS of the modal's style blocks, compiled the way the build does.
 *
 * @return {string} The CSS of all blocks, in source order
 */
function compiledStyles() {
	const file = join(__dirname, 'ViewObject.vue')
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	return descriptor.styles
		.map((block) => {
			const css =
				block.lang === 'scss' ? compileScss(block.content) : block.content
			return compileStyle({
				source: css,
				filename: file,
				id: 'data-v-golden',
				scoped: block.scoped,
			}).code
		})
		.join('\n')
}

/**
 * The declaration each selector and property ends up with: the last one in
 * source order, listed in the order those last declarations appear.
 *
 * @param {string} css The CSS
 * @return {Array<string>} One line per effective declaration
 */
function effectiveCascade(css) {
	const last = new Map()
	postcss.parse(css).walkDecls((decl) => {
		const parts = []
		for (
			let node = decl.parent;
			node && node.type !== 'root';
			node = node.parent
		) {
			parts.unshift(
				node.type === 'rule'
					? node.selector
					: `@${node.name} ${node.params}`,
			)
		}
		const key = `${parts.join(' > ')} { ${decl.prop}`
		last.delete(key)
		last.set(
			key,
			`${key}: ${decl.value}${decl.important ? ' !important' : ''} }`,
		)
	})
	return [...last.values()]
}

describe('ViewObject styles golden', () => {
	it('keeps the effective rule of every selector', () => {
		const cascade = effectiveCascade(compiledStyles())
		expect(cascade.length).toBeGreaterThan(50)
		expect(cascade).toMatchSnapshot()
	})

	it('renders the dialog', async () => {
		objectStore.objectItem = null
		navigationStore.modal = 'viewObject'
		const wrapper = mount(ViewObject, {
			global: {
				mocks: { t: (app, text) => `t:${text}`, n: (app, one) => one },
			},
		})
		await flushPromises()
		const dialog = wrapper.find('x-NcDialog')
		expect(dialog.exists()).toBe(true)
		expect(dialog.find('.viewObjectDialog').exists()).toBe(true)
		expect(wrapper.emitted()).toEqual({})
	})
})
