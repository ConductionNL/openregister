import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the drop panel of the upload modal: its notes and prompt
 * in each state.
 *
 * @spec exclude Golden record of client-side modal markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadFiles from './UploadFiles.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'uploadFiles' },
	objectStore: { objectItem: { '@self': { folder: 12 } } },
}))
jest.mock('./../../composables/UseFileSelection.js', () => {
	const selection = { files: { value: [] }, openFileUpload: jest.fn(), reset: jest.fn(), setTags: jest.fn() }
	return { useFileSelection: () => selection }
})
jest.mock('vue-material-design-icons/AlphaXCircle.vue', () => mockModule('AlphaXCircle'))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => mockModule('CheckCircle'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => mockModule('ContentSaveOutline'))
jest.mock('vue-material-design-icons/Exclamation.vue', () => mockModule('Exclamation'))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => mockModule('FolderOutline'))
jest.mock('vue-material-design-icons/Minus.vue', () => mockModule('Minus'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockModule('Plus'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('vue-material-design-icons/TagEdit.vue', () => mockModule('TagEdit'))
jest.mock('vue-material-design-icons/TrayArrowDown.vue', () => mockModule('TrayArrowDown'))

const { openFileUpload } = jest.requireMock('./../../composables/UseFileSelection.js').useFileSelection()

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
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
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
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

/**
 * Mounts the modal in a state.
 *
 * @param {object} state The data to set
 * @param {boolean} tooBig Whether a selected file is too big
 * @return {Promise<object>} The wrapper
 */
async function modal(state, tooBig = false) {
	const { translate: t } = jest.requireMock('@nextcloud/l10n')
	const stores = jest.requireMock('../../store/store.js')
	const bindings = { ...UploadFiles.components, ...stores, t, openFileUpload }
	const wrapper = mount(
		{
			...UploadFiles,
			setup: () => bindings,
			data() {
				return { ...UploadFiles.data.call(this), ...state }
			},
			methods: {
				...UploadFiles.methods,
				getAllTags() {},
				checkForTooBigFiles: () => tooBig,
			},
		},
		{ global: { config: { warnHandler: () => {} } } },
	)
	await flushPromises()
	return wrapper
}

/**
 * The markup of the drop panel, proven not empty.
 *
 * @param {object} wrapper The mounted modal
 * @return {string} The markup
 */
function panel(wrapper) {
	const panels = wrapper.findAll('.tabPanelFileUpload')
	expect(panels).toHaveLength(1)
	const html = panels[0]
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/> +/g, '>')
		.replace(/ +</g, '<')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.join('\n')
	expect(html).toContain('filesListDragDropNoticeWrapper')
	return html
}

const LABELLED = { labelOptions: { value: ['a'] } }

describe('upload drop panel', () => {
	it.each([
		['without labels', {}, false],
		['while loading', { ...LABELLED, loading: true }, false],
		['with labels', LABELLED, false],
		['after success with labels', { ...LABELLED, success: true }, false],
		['after success without labels', { success: true }, false],
		['after an error with labels', { ...LABELLED, success: false, error: 'Broken' }, false],
		['after an error without labels', { success: false, error: 'Broken' }, false],
		['after a failure without a message', { ...LABELLED, success: false }, false],
		['after success with an error left', { ...LABELLED, success: true, error: 'Broken' }, false],
		['with a file too big', LABELLED, true],
	])('shows its notes %s', async (name, state, tooBig) => {
		expect(panel(await modal(state, tooBig))).toMatchSnapshot()
	})

	it('opens the file picker from the add button', async () => {
		const wrapper = await modal(LABELLED)
			openFileUpload.mockClear()
		const buttons = wrapper.find('.tabPanelFileUpload').findAllComponents({ name: 'NcButton' })
		buttons[buttons.length - 1].vm.$attrs.onClick()
		expect(openFileUpload).toHaveBeenCalledTimes(1)
	})
})
