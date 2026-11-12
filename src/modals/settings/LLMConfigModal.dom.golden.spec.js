import axios from '@nextcloud/axios'
/**
 * Golden record of the rendered DOM of the LLM configuration modal: closed,
 * open, and open while saving.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import LLMConfigModal from './LLMConfigModal.vue'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	patch: jest.fn(),
}))
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
	NcSelect: mockStub('NcSelect'),
}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({
	__esModule: true,
	default: mockStub('ContentSave'),
}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({
	__esModule: true,
	default: mockStub('Delete'),
}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	__esModule: true,
	default: mockStub('InformationOutline'),
}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({
	__esModule: true,
	default: mockStub('TestTube'),
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
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
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
	axios.get.mockImplementation(() => Promise.reject(new Error('down')))
})

describe('LLM configuration modal DOM golden', () => {
	it('closed', async () => {
		const wrapper = mountModal(LLMConfigModal, { show: false })
		await flushPromises()
		expect(normalised(wrapper.html())).toMatchSnapshot()
		wrapper.unmount()
	})

	for (const [state, data] of Object.entries({
		open: {},
		saving: { saving: true },
	})) {
		it(state, async () => {
			const wrapper = mountModal(LLMConfigModal, { show: true })
			await flushPromises()
			await wrapper.setData(data)
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})
	}
})

describe('LLM configuration modal provider sections DOM golden', () => {
	for (const [embedding, chat] of [
		['openai', 'fireworks'],
		['ollama', 'openai'],
		['fireworks', 'ollama'],
		['openai', 'openai'],
		['ollama', 'ollama'],
		['fireworks', 'fireworks'],
	]) {
		it(`embedding ${embedding}, chat ${chat}`, async () => {
			const wrapper = mountModal(LLMConfigModal, { show: true })
			await flushPromises()
			await wrapper.setData({
				selectedEmbeddingProvider: { id: embedding, name: embedding },
				selectedChatProvider: { id: chat, name: chat },
			})
			await flushPromises()
			expect(normalised(wrapper.html())).toMatchSnapshot()
			wrapper.unmount()
		})
	}
})

describe('LLM configuration modal footer buttons', () => {
	/**
	 * Click a footer button of an open modal and report what it caused.
	 *
	 * @param {number} index The button, 0 for cancel and 1 for save
	 * @return {Promise<object>} The requests and emitted events
	 */
	async function clickFooter(index) {
		axios.patch.mockClear()
		axios.patch.mockResolvedValue({ data: {} })
		const wrapper = mountModal(LLMConfigModal, { show: true })
		await flushPromises()
		const buttons = wrapper
			.findAllComponents({ name: 'NcButton' })
			.filter((button) => button.element.closest('.actions-right'))
		await buttons[index].vm.$attrs.onClick()
		await flushPromises()
		const caused = {
			buttons: buttons.length,
			patched: axios.patch.mock.calls.map(([url]) => url),
			emitted: Object.keys(wrapper.emitted()).filter(
				(name) => name === 'closing',
			),
		}
		wrapper.unmount()
		return caused
	}

	it('cancel closes without saving', async () => {
		expect(await clickFooter(0)).toEqual({
			buttons: 2,
			patched: [],
			emitted: ['closing'],
		})
	})

	it('save sends the settings and closes', async () => {
		expect(await clickFooter(1)).toEqual({
			buttons: 2,
			patched: ['/apps/openregister/api/settings/llm'],
			emitted: ['closing'],
		})
	})
})
