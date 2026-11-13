import { mount } from '@vue/test-utils'
/**
 * The delete schema dialog as it renders: hidden unless it is the open
 * dialog, the question while the schema is empty, the refusal naming the
 * objects and their register while it is not, and the frame's buttons wired
 * to closing and deleting.
 *
 * @spec exclude Test of the client-side schema delete dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchema from './DeleteSchema.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, t: (app, s) => s }))
jest.mock('../shared/ConfirmDialogFrame.vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		default: {
			name: 'ConfirmDialogFrame',
			props: ['name', 'success', 'error', 'loading', 'showConfirm', 'confirmDisabled', 'closeLabel', 'confirmLabel'],
			emits: ['cancel', 'confirm'],
			render() {
				return h('div', { class: 'frame' }, [
					this.$slots.default?.(),
					h('button', { class: 'cancel', onClick: () => this.$emit('cancel') }, this.closeLabel),
					this.showConfirm
						? h('button', { class: 'confirm', disabled: this.confirmDisabled, onClick: () => this.$emit('confirm') }, this.confirmLabel)
						: null,
				])
			},
		},
	}
})
jest.mock('../../store/store.js', () => ({
	navigationStore: { dialog: 'deleteSchema', setDialog: jest.fn() },
	objectStore: { currentType: 'object', getCollection: jest.fn(() => []), setFilters: jest.fn(), refreshObjectList: jest.fn() },
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaItem: { id: 7, title: 'Person' }, getSchemaStats: jest.fn(), deleteSchema: jest.fn() },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the dialog with its data laid over.
 *
 * @param {object} data The data to start from
 * @return {object} The wrapper
 */
function mountDialog(data = {}) {
	return mount(DeleteSchema, { data: () => data })
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.dialog = 'deleteSchema'
	schemaStore.deleteSchema.mockResolvedValue({ response: { ok: true } })
})

describe('DeleteSchema as it renders', () => {
	it('renders nothing when another dialog is open', () => {
		navigationStore.dialog = 'somethingElse'

		expect(mountDialog().find('.frame').exists()).toBe(false)
	})

	it('asks before deleting an empty schema, by its title', () => {
		const wrapper = mountDialog()

		expect(wrapper.text()).toContain('Wil je Person permanent verwijderen?')
		expect(wrapper.find('.cancel').text()).toBe('Annuleren')
		expect(wrapper.find('.confirm').attributes('disabled')).toBeUndefined()
	})

	it('refuses a schema holding several objects and names their register', () => {
		const wrapper = mountDialog({ objects: [{ id: 1 }, { id: 2 }], registerName: 'People' })

		expect(wrapper.text()).toContain('Er zijn 2 objecten in dit schema in het register People')
		expect(wrapper.text()).toContain('Je moet deze eerst verwijderen.')
		expect(wrapper.text()).not.toContain('permanent verwijderen?')
		expect(wrapper.find('.confirm').attributes('disabled')).toBeDefined()
	})

	it('speaks of one object in the singular', () => {
		const wrapper = mountDialog({ objects: [{ id: 1 }], registerName: 'People' })

		expect(wrapper.text()).toContain('Er is 1 object in dit schema')
		expect(wrapper.text()).toContain('Je moet dit eerst verwijderen.')
	})

	it('offers only Sluiten once deleted', () => {
		const wrapper = mountDialog({ success: true })

		expect(wrapper.find('.cancel').text()).toBe('Sluiten')
		expect(wrapper.find('.confirm').exists()).toBe(false)
	})

	it('closes through the frame cancel button', async () => {
		const wrapper = mountDialog({ error: 'x' })

		await wrapper.find('.cancel').trigger('click')

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.vm.error).toBe(false)
	})

	it('deletes the schema through the frame confirm button', async () => {
		const wrapper = mountDialog()

		await wrapper.find('.confirm').trigger('click')

		expect(schemaStore.deleteSchema).toHaveBeenCalledWith({ id: 7, title: 'Person' })
	})
})
