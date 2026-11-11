import { mount } from '@vue/test-utils'
/**
 * Golden record of the dialogs that clear a whole table: their markup idle and
 * busy, and what the buttons and the dialog emit.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ClearAuditTrailsDialog from './ClearAuditTrailsDialog.vue'
import ClearBlobObjectsDialog from './ClearBlobObjectsDialog.vue'
import ClearSearchTrailsDialog from './ClearSearchTrailsDialog.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
}))
// The real CnDialog, drawn with the stand-ins above, so the golden shows what
// the shared frame really renders around ClearTableDialog.
jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	CnDialog: jest.requireActual(
		'@conduction/nextcloud-vue/dist/esm/components/CnDialog/CnDialog.vue.js',
	).default,
}))
jest.mock('vue-material-design-icons/Close.vue', () => ({
	__esModule: true,
	default: mockStub('Close'),
}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({
	__esModule: true,
	default: mockStub('Delete'),
}))

const DIALOGS = {
	audit: [ClearAuditTrailsDialog, 'totalAuditTrails'],
	blob: [ClearBlobObjectsDialog, 'totalBlobObjects'],
	search: [ClearSearchTrailsDialog, 'totalSearchTrails'],
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
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
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
 * Mounts a dialog.
 *
 * @param {string} name The table entry
 * @param {boolean} clearing Whether it is busy
 * @return {object} The wrapper
 */
function dialog(name, clearing) {
	const [component, count] = DIALOGS[name]
	return mount(component, {
		props: { open: true, clearing, [count]: 12 },
		global: { mocks: { t: (app, text) => `t:${text}` } },
	})
}

/**
 * The markup without scoped-style ids, comment nodes and the space around a
 * text, proven not empty.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} The markup
 */
function dom(wrapper) {
	const html = wrapper
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+<\/slot-default>/g, '</slot-default>')
		.replace(/<p>\s+/g, '<p>')
		.replace(/\s+<\/p>/g, '</p>')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).toContain('clear-dialog-content')
	return html
}

describe.each(Object.keys(DIALOGS))('clear dialog %s', (name) => {
	it.each([false, true])('renders with clearing %s', (clearing) => {
		expect(dom(dialog(name, clearing))).toMatchSnapshot()
	})

	it('reports closing and confirming', () => {
		const wrapper = dialog(name, false)
		const [cancel, confirm] = wrapper.findAllComponents({ name: 'NcButton' })
		cancel.vm.$emit('click')
		confirm.vm.$emit('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		expect(wrapper.emitted()).toMatchObject({
			closing: [[], []],
			confirm: [[]],
		})
	})
})
