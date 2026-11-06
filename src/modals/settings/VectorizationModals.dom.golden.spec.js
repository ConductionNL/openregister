import axios from '@nextcloud/axios'
/**
 * Golden record of the rendered DOM of the file and object vectorization
 * modals in each of their states: closed, open, a narrowed selection,
 * nothing to process, processing and processing with failures.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import FileVectorizationModal from './FileVectorizationModal.vue'
import ObjectVectorizationModal from './ObjectVectorizationModal.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcProgressBar: mockStub('NcProgressBar'),
}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	__esModule: true,
	default: mockStub('InformationOutline'),
}))
jest.mock('vue-material-design-icons/PlayCircle.vue', () => ({
	__esModule: true,
	default: mockStub('PlayCircle'),
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
	'/apps/openregister/api/files/types': {
		success: true,
		data: [
			{ mime: 'application/pdf', name: 'PDF', fileCount: 1, chunkCount: 4 },
			{ mime: 'text/plain', name: 'Text', fileCount: 3, chunkCount: 1 },
		],
	},
	'/apps/openregister/api/settings/objects/vectorize': {
		data: { vectorizeAllViews: false, enabledViews: [2], batchSize: 50 },
	},
	'/apps/openregister/api/views': {
		results: [
			{ id: 1, name: 'First view' },
			{ id: 2, name: 'Second view' },
		],
	},
	'/apps/openregister/api/objects/vectorize/stats': { total_objects: 4321 },
}

const FILE_PROPS = {
	extractionStats: { completed: 12, totalChunks: 345678 },
	vectorStats: { stats: { file_vectors: 45 } },
}

const PROCESSING = { processing: true, processed: 30, vectorized: 30, failed: 0 }

const FAILING = { processing: true, processed: 40, vectorized: 31, failed: 9 }

const MODALS = {
	file: {
		component: FileVectorizationModal,
		props: FILE_PROPS,
		states: {
			open: {},
			narrowed: { vectorizeAllTypes: false, selectedTypes: ['text/plain'] },
			processing: PROCESSING,
			failing: FAILING,
		},
		empty: { extractionStats: {}, vectorStats: {} },
	},
	object: {
		component: ObjectVectorizationModal,
		props: {},
		states: {
			open: {},
			narrowed: { vectorizeAllViews: false, maxObjects: 100 },
			processing: PROCESSING,
			failing: FAILING,
		},
		empty: null,
	},
}

/**
 * Mount a modal with the translation helper the app mixes in.
 *
 * @param {object} component The modal
 * @param {object} props The modal props
 * @return {object} The wrapper
 */
function mountModal(component, props) {
	return mount(component, {
		props,
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

beforeEach(() => {
	jest.spyOn(console, 'error').mockImplementation(() => {})
	axios.get.mockImplementation((url) => Promise.resolve({ data: ANSWERS[url] }))
})

describe('vectorization modals DOM golden', () => {
	for (const [name, modal] of Object.entries(MODALS)) {
		it(`${name}: closed`, async () => {
			const wrapper = mountModal(modal.component, {
				...modal.props,
				show: false,
			})
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})

		for (const [state, data] of Object.entries(modal.states)) {
			it(`${name}: ${state}`, async () => {
				const wrapper = mountModal(modal.component, {
					...modal.props,
					show: true,
				})
				await flushPromises()
				await wrapper.setData(data)
				await flushPromises()
				expect(normalised(wrapper.html())).toMatchSnapshot()
				wrapper.unmount()
			})
		}

		it(`${name}: nothing to process`, async () => {
			axios.get.mockImplementation(() => Promise.reject(new Error('down')))
			const wrapper = mountModal(modal.component, {
				...(modal.empty ?? modal.props),
				show: true,
			})
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})
	}
})
