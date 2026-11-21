import { mount } from '@vue/test-utils'
/**
 * The upload schema dialog as it renders: hidden unless it is the open modal,
 * the form with the upload held back until the JSON is valid, the editor
 * replaced by the success note once uploaded, and the buttons wired to
 * closing, prettifying and uploading.
 *
 * @spec exclude Test of the client-side schema upload dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadSchema from './UploadSchema.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, t: (app, s) => s }))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => { const { h } = jest.requireActual('vue'); return { __esModule: true, default: { name: 'CodeMirror', props: ['modelValue'], render() { return h('textarea', { class: 'cm', value: this.modelValue }) } } } })
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))

/**
 * The library components, as stubs that render their slots and reflect disabled.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	const stub = (name, tag = 'div') => ({
		name,
		props: ['disabled', 'variant', 'type'],
		emits: ['click'],
		render() {
			return h(tag, { class: name, disabled: this.disabled || undefined, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	})
	return {
		__esModule: true,
		NcDialog: { name: 'NcDialog', render() { return h('div', { class: 'NcDialog' }, [this.$slots.default?.(), this.$slots.actions?.()]) } },
		NcTextField: stub('NcTextField'),
		NcButton: stub('NcButton', 'button'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note-' + this.type }, this.$slots.default?.()) } },
	}
}
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => { const { h } = jest.requireActual('vue'); return { __esModule: true, default: { name: 'CloseCancelButton', props: ['label'], emits: ['click'], render() { return h('button', { class: 'close', onClick: () => this.$emit('click') }, this.label) } } } })
jest.mock('vue-material-design-icons/AutoFix.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'uploadSchema', setModal: jest.fn() },
	schemaStore: { uploadSchema: jest.fn() },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the dialog with its data laid over.
 *
 * @param {object} data The data to start from
 * @return {object} The wrapper
 */
function mountDialog(data = {}) {
	return mount(UploadSchema, { data: () => data })
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The dialog
 * @param {string} text The button text
 * @return {object} The button wrapper
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text().trim().includes(text))
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'uploadSchema'
})

describe('UploadSchema as it renders', () => {
	it('renders nothing when another modal is open', () => {
		navigationStore.modal = 'other'

		expect(mountDialog().find('.NcDialog').exists()).toBe(false)
	})

	it('shows the form and lets the upload run once the JSON is valid', () => {
		const invalid = mountDialog({ schema: { json: '{ not json', url: '' } })
		expect(invalid.find('.cm').exists()).toBe(true)
		expect(button(invalid, 'Upload').attributes('disabled')).toBeDefined()

		const valid = mountDialog({ schema: { json: '{"title":"x"}', url: '' } })
		expect(button(valid, 'Upload').attributes('disabled')).toBeUndefined()
	})

	it('replaces the form with the success note once uploaded', () => {
		const wrapper = mountDialog({ success: true })

		expect(wrapper.find('.note-success').text()).toBe('Schema successfully uploaded')
		expect(wrapper.find('.cm').exists()).toBe(false)
		expect(button(wrapper, 'Upload')).toBeUndefined()
	})

	it('shows an upload error', () => {
		expect(mountDialog({ error: 'Bad schema' }).find('.note-error').text()).toBe('Bad schema')
	})

	it('prettifies the JSON in the editor', async () => {
		const wrapper = mountDialog({ schema: { json: '{"a":1}', url: '' } })

		await button(wrapper, 'Prettify').trigger('click')

		expect(wrapper.vm.schema.json).toBe('{\n  "a": 1\n}')
	})

	it('uploads a clean JSON string through the store', async () => {
		schemaStore.uploadSchema.mockResolvedValue({ response: { ok: true } })
		const wrapper = mountDialog({ schema: { json: '{  "title" :  "x" }', url: 'http://x' } })

		await button(wrapper, 'Upload').trigger('click')

		expect(schemaStore.uploadSchema).toHaveBeenCalledWith({ json: '{"title":"x"}', url: 'http://x' })
	})

	it('closes through the Cancel button and resets the form', async () => {
		const wrapper = mountDialog({ success: true, error: 'x' })

		await wrapper.find('.close').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.schema).toEqual({ json: '{}', url: '' })
		expect(wrapper.vm.error).toBe(false)
	})
})
