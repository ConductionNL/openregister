/**
 * Golden record of the rendered DOM of the source and configuration view
 * modals: filled and sparse items, on each tab.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ViewConfiguration from './configuration/ViewConfiguration.vue'
import ViewSource from './source/ViewSource.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one, many) => `t:${many}`,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcActionButton: mockStub('NcActionButton'),
	NcActions: mockStub('NcActions'),
	NcButton: mockStub('NcButton'),
	NcDialog: mockStub('NcDialog'),
	NcEmptyContent: mockStub('NcEmptyContent'),
}))
// As in listActions.dom.golden.spec.js, the library components are stubbed
// on 'vue' as well, or the mixed <script setup> files render nothing.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcActionButton: mockStub('NcActionButton'),
	NcActions: mockStub('NcActions'),
	NcButton: mockStub('NcButton'),
	NcDialog: mockStub('NcDialog'),
	NcEmptyContent: mockStub('NcEmptyContent'),
}))
jest.mock('../store/store.js', () => ({
	navigationStore: { modal: '', setModal: () => {}, setDialog: () => {} },
	registerStore: {
		registerList: [],
		refreshRegisterList: () => Promise.resolve(),
		setRegisterItem: () => {},
	},
	schemaStore: {},
	sourceStore: { sourceItem: null },
	configurationStore: { configurationItem: null },
}))
jest.mock('./register/EditRegisterFormDialog.vue', () => ({
	__esModule: true,
	default: mockStub('EditRegisterFormDialog'),
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: mockStub('Cancel'),
}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({
	__esModule: true,
	default: mockStub('CogOutline'),
}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({
	__esModule: true,
	default: mockStub('DatabaseOutline'),
}))
jest.mock('vue-material-design-icons/Download.vue', () => ({
	__esModule: true,
	default: mockStub('Download'),
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({
	__esModule: true,
	default: mockStub('Eye'),
}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({
	__esModule: true,
	default: mockStub('Pencil'),
}))
jest.mock('vue-material-design-icons/PostOutline.vue', () => ({
	__esModule: true,
	default: mockStub('PostOutline'),
}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: mockStub('TrashCanOutline'),
}))

/**
 * A stand-in for a library component: its tag, attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const plain = Object.fromEntries(
					Object.entries(attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
				)
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without scoped-style ids and comment nodes.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

const store = jest.requireMock('../store/store.js')
const STAMP = '2026-01-02T03:04:05Z'

const CASES = {
	source: {
		component: ViewSource,
		modal: 'viewSource',
		set(full) {
			store.sourceStore.sourceItem = full
				? {
						id: 4,
						title: 'Source',
						description: 'About it',
						type: 'internal',
						databaseUrl: 'mysql://x',
						created: STAMP,
						updated: STAMP,
					}
				: { id: 5, title: 'Bare' }
			store.registerStore.registerList = full
				? [{ id: 1, title: 'Reg', description: 'R', source: 4 }]
				: []
		},
	},
	configuration: {
		component: ViewConfiguration,
		modal: 'viewConfiguration',
		set(full) {
			store.configurationStore.configurationItem = full
				? {
						id: 6,
						title: 'Config',
						description: 'About it',
						type: 'app',
						owner: 'admin',
						created: STAMP,
						updated: STAMP,
						configuration: { a: 1 },
					}
				: { id: 7, title: 'Bare' }
		},
	},
}

describe('view modals DOM golden', () => {
	// The modals print dates with toLocaleString; keep the record zone-free.
	beforeEach(() => {
		jest
			.spyOn(Date.prototype, 'toLocaleString')
			.mockImplementation(function () {
				return this.toISOString()
			})
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [name, spec] of Object.entries(CASES)) {
		for (const full of [true, false]) {
			for (const tab of [0, 1]) {
				it(`${name}, ${full ? 'filled' : 'sparse'}, tab ${tab}`, async () => {
					store.navigationStore.modal = spec.modal
					spec.set(full)
					const wrapper = mount(spec.component, {
						global: {
							config: {
								globalProperties: { t: (app, text) => `t:${text}`, $router: { push: () => {} } },
							},
						},
					})
					await flushPromises()
					if (tab) {
						await wrapper.findAll('button.tabHeader')[tab].trigger('click')
					}
					expect(normalised(wrapper.html())).toMatchSnapshot()
					wrapper.unmount()
				})
			}
		}
	}
})
