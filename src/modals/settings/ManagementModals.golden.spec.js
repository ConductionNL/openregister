/**
 * Golden record of the file and object vectorization settings modals: the
 * rendered DOM in each of their states, and what loading and saving the
 * settings read, send, show and emit.
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import FileManagementModal from './FileManagementModal.vue'
import ObjectManagementModal from './ObjectManagementModal.vue'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	patch: jest.fn(),
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({
	__esModule: true,
	default: mockStub('AlertCircle'),
}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({
	__esModule: true,
	default: mockStub('ContentSave'),
}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	__esModule: true,
	default: mockStub('InformationOutline'),
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

const ANSWERS = {
	'/apps/openregister/api/settings/objects/vectorize': {
		success: true,
		data: {
			vectorizeAllViews: false,
			enabledViews: [2],
			maxNestingDepth: 4,
			batchSize: 50,
		},
	},
	'/apps/openregister/api/views': {
		results: [
			{ id: 1, name: 'First view', description: 'The first' },
			{ id: 2, name: 'Second view' },
		],
	},
	'/apps/openregister/api/settings/llm': {
		embeddingProvider: 'ollama',
		embeddingModel: 'nomic',
		vectorDimensions: 768,
	},
}

const MODALS = {
	file: {
		component: FileManagementModal,
		states: {
			open: {},
			disabled: { config: { vectorizationEnabled: false } },
			saving: { saving: true },
		},
	},
	object: {
		component: ObjectManagementModal,
		states: {
			open: {},
			disabled: { config: { vectorizationEnabled: false } },
			allViews: { config: { vectorizeAllViews: true } },
			noViews: { views: [] },
			saving: { saving: true },
		},
	},
}

/**
 * Mount a modal with the translation helper the app mixes in.
 *
 * @param {object} component The modal
 * @param {boolean} show Whether the modal is open
 * @return {object} The wrapper
 */
function mountModal(component, show) {
	return mount(component, {
		props: { show },
		global: {
			mocks: {
				t: (app, text, vars) =>
					vars ? `${text} ${JSON.stringify(vars)}` : text,
			},
		},
	})
}

/**
 * The markup without the scoped-style ids, which depend on the file name,
 * and without comment nodes, which only development builds render.
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

/**
 * Everything a modal read, sent, showed, emitted and kept. The loading flag
 * is left out: nothing renders or reads it.
 *
 * @param {object} wrapper The mounted modal
 * @return {object} The record
 */
function recorded(wrapper) {
	return {
		get: axios.get.mock.calls,
		post: axios.post.mock.calls,
		patch: axios.patch.mock.calls,
		success: showSuccess.mock.calls,
		error: showError.mock.calls,
		emitted: wrapper.emitted(),
		data: JSON.parse(
			JSON.stringify({ ...wrapper.vm.$data, loading: undefined }),
		),
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	axios.get.mockImplementation((url) => Promise.resolve({ data: ANSWERS[url] }))
	axios.post.mockResolvedValue({ data: {} })
	axios.patch.mockResolvedValue({ data: {} })
})

describe('vectorization settings modals golden', () => {
	for (const [name, modal] of Object.entries(MODALS)) {
		it(`${name}: closed`, async () => {
			const wrapper = mountModal(modal.component, false)
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})

		for (const [state, data] of Object.entries(modal.states)) {
			it(`${name}: ${state}`, async () => {
				const wrapper = mountModal(modal.component, true)
				await flushPromises()
				await wrapper.setData(data)
				expect(normalised(wrapper.html())).toMatchSnapshot()
				wrapper.unmount()
			})
		}

		it(`${name}: loads`, async () => {
			const wrapper = mountModal(modal.component, true)
			await flushPromises()
			expect(recorded(wrapper)).toMatchSnapshot()
			wrapper.unmount()
		})

		it(`${name}: loads nothing`, async () => {
			axios.get.mockRejectedValue(new Error('down'))
			const wrapper = mountModal(modal.component, true)
			await flushPromises()
			expect(recorded(wrapper)).toMatchSnapshot()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})

		it(`${name}: loads an unsuccessful answer`, async () => {
			axios.get.mockResolvedValue({ data: { success: false } })
			const wrapper = mountModal(modal.component, true)
			await flushPromises()
			expect(recorded(wrapper)).toMatchSnapshot()
			wrapper.unmount()
		})

		it(`${name}: saves`, async () => {
			const wrapper = mountModal(modal.component, true)
			await flushPromises()
			jest.clearAllMocks()
			await wrapper.vm.saveConfiguration()
			expect(recorded(wrapper)).toMatchSnapshot()
			wrapper.unmount()
		})

		for (const [failure, error] of Object.entries({
			answered: { response: { data: { error: 'Refused' } } },
			unanswered: new Error('Offline'),
		})) {
			it(`${name}: fails to save, ${failure}`, async () => {
				const wrapper = mountModal(modal.component, true)
				await flushPromises()
				jest.clearAllMocks()
				axios.post.mockRejectedValue(error)
				axios.patch.mockRejectedValue(error)
				await wrapper.vm.saveConfiguration()
				expect(recorded(wrapper)).toMatchSnapshot()
				wrapper.unmount()
			})
		}
	}
})
