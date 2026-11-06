import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the rendered DOM of the LLM settings section, with the
 * LLM switched off and on.
 *
 * @spec exclude Golden record of client-side settings markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import LlmConfiguration from './LlmConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcActionButton: mockStub('NcActionButton'),
	NcActions: mockStub('NcActions'),
	NcActionSeparator: mockStub('NcActionSeparator'),
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () =>
	mockModule('CubeOutline'),
)
jest.mock('vue-material-design-icons/DotsVertical.vue', () =>
	mockModule('DotsVertical'),
)
jest.mock('vue-material-design-icons/FileDocument.vue', () =>
	mockModule('FileDocument'),
)
jest.mock('vue-material-design-icons/FileDocumentCheckOutline.vue', () =>
	mockModule('FileDocumentCheckOutline'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('vue-material-design-icons/Robot.vue', () => mockModule('Robot'))
jest.mock('vue-material-design-icons/VectorSquare.vue', () =>
	mockModule('VectorSquare'),
)
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	mockModule('SettingsSection'),
)
jest.mock('../../../modals/settings/FileManagementModal.vue', () =>
	mockModule('FileManagementModal'),
)
jest.mock('../../../modals/settings/FileVectorizationModal.vue', () =>
	mockModule('FileVectorizationModal'),
)
jest.mock('../../../modals/settings/LLMConfigModal.vue', () =>
	mockModule('LLMConfigModal'),
)
jest.mock('../../../modals/settings/ObjectManagementModal.vue', () =>
	mockModule('ObjectManagementModal'),
)
jest.mock('../../../modals/settings/ObjectVectorizationModal.vue', () =>
	mockModule('ObjectVectorizationModal'),
)
const mockStore = {
	extractionStats: { completed: 3 },
	vectorStats: { stats: { file_vectors: 2 } },
	getLlmSettings: () => Promise.reject(new Error('down')),
	getExtractionStats: () => Promise.resolve({}),
	getChatStats: () => Promise.reject(new Error('down')),
	getVectorStats: () => Promise.reject(new Error('down')),
}

jest.mock('pinia', () => ({
	mapStores: () => ({ settingsStore: () => mockStore }),
}))
jest.mock('../../../store/settings.js', () => ({ useSettingsStore: () => ({}) }))

/**
 * A stand-in component module.
 *
 * @param {string} name The component name
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, attributes and slots.
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
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without the scoped-style ids and comment nodes.
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

beforeEach(() => {
	jest.spyOn(console, 'error').mockImplementation(() => {})
	jest.spyOn(console, 'warn').mockImplementation(() => {})
})

describe('LLM settings section DOM golden', () => {
	for (const [state, enabled] of Object.entries({ off: false, on: true })) {
		it(`LLM ${state}`, async () => {
			const wrapper = mount(LlmConfiguration, {
				global: {
					mocks: {
						t: (app, text, vars) =>
							vars ? `${text} ${JSON.stringify(vars)}` : text,
						n: (app, one, more, count) => (count === 1 ? one : more),
					},
				},
			})
			await flushPromises()
			await wrapper.setData({
				llmSettings: { ...wrapper.vm.llmSettings, enabled },
			})
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})
	}
})
