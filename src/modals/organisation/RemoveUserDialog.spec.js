/**
 * RemoveUserDialog: shown only on request, names the user, locks while the
 * removal runs, and hands cancel / confirm back to its parent.
 *
 * @spec exclude Unit spec of a presentational confirm dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import RemoveUserDialog from './RemoveUserDialog.vue'

// The shared frame is presentational; a stand-in exposes its props, renders
// the slot and lets the spec raise its cancel / confirm events.
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({
	__esModule: true,
	default: {
		name: 'ConfirmDialogFrame',
		inheritAttrs: false,
		emits: ['cancel', 'confirm'],
		template: '<div class="frame-stub"><slot /></div>',
	},
}))

const frame = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialogFrame' })

/**
 * Mount the dialog.
 *
 * @param {object} props The dialog's props
 * @return {object} The wrapper
 */
function open(props) {
	return mount(RemoveUserDialog, { props: { show: true, userId: 'alice', ...props } })
}

describe('RemoveUserDialog', () => {
	it('renders nothing while not shown', () => {
		const wrapper = open({ show: false })

		expect(frame(wrapper).exists()).toBe(false)
		expect(wrapper.text()).toBe('')
	})

	it('asks to remove the named user', () => {
		const wrapper = open()

		expect(wrapper.text().replace(/\s+/g, ' ')).toBe('Are you sure you want to remove user alice from this organisation?')
		expect(wrapper.find('strong').text()).toBe('alice')
	})

	it('offers a closable, enabled confirm while idle', () => {
		const attrs = frame(open()).vm.$attrs

		expect(attrs.name).toBe('Remove User')
		expect(attrs.confirmLabel).toBe('Remove User')
		expect(attrs.closeLabel).toBe('Cancel')
		expect(attrs.closable).toBe(true)
		expect(attrs.loading).toBe(false)
		expect(attrs.cancelDisabled).toBe(false)
	})

	it('locks while the removal runs', () => {
		const attrs = frame(open({ removing: true })).vm.$attrs

		expect(attrs.closable).toBe(false)
		expect(attrs.loading).toBe(true)
		expect(attrs.cancelDisabled).toBe(true)
	})

	it('hands cancel and confirm to the parent', async () => {
		const wrapper = open()

		await frame(wrapper).vm.$emit('cancel')
		await frame(wrapper).vm.$emit('confirm')

		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})
})
