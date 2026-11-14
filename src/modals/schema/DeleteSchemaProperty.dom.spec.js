import { mount } from '@vue/test-utils'
/**
 * The delete schema property dialog as it renders: the question naming the
 * property, the warning while objects use it, the outcome notes, and the
 * frame's buttons wired to closing and deleting.
 *
 * @spec exclude Test of the client-side schema property delete dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchemaProperty from './DeleteSchemaProperty.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, t: (app, s) => s }))
/**
 * The NcNoteCard the dialog uses, rendering its slot under a type class.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note-' + this.type }, this.$slots.default?.()) } },
	}
}
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../shared/ConfirmDialogFrame.vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		default: {
			name: 'ConfirmDialogFrame',
			props: ['name', 'size', 'loading', 'cancelDisabled', 'showConfirm', 'confirmDisabled', 'closeLabel', 'confirmLabel'],
			emits: ['cancel', 'confirm'],
			render() {
				return h('div', { class: 'frame' }, [
					this.$slots.default?.(),
					h('button', { class: 'cancel', disabled: this.cancelDisabled, onClick: () => this.$emit('cancel') }, this.closeLabel),
					this.showConfirm
						? h('button', { class: 'confirm', disabled: this.confirmDisabled, onClick: () => this.$emit('confirm') }, this.confirmLabel)
						: null,
				])
			},
		},
	}
})
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'deleteSchemaProperty', setModal: jest.fn() },
	objectStore: { currentType: 'object', getCollection: jest.fn(() => []), refreshObjectList: jest.fn() },
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: {
		schemaItem: { id: 7, required: ['birthDate', 'name'], properties: { birthDate: { title: 'Birth date' }, name: { title: 'Name' } } },
		schemaPropertyKey: 'birthDate',
		setSchemaPropertyKey: jest.fn(),
		saveSchema: jest.fn(),
	},
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the dialog with its data laid over.
 *
 * @param {object} data The data to start from
 * @return {object} The wrapper
 */
function mountDialog(data = {}) {
	return mount(DeleteSchemaProperty, { data: () => data })
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'deleteSchemaProperty'
	schemaStore.saveSchema.mockResolvedValue({ response: { ok: true } })
})

describe('DeleteSchemaProperty as it renders', () => {
	it('renders nothing when another modal is open', () => {
		navigationStore.modal = 'other'

		expect(mountDialog().find('.frame').exists()).toBe(false)
	})

	it('asks before deleting the property, by its title, and warns of nothing while unused', () => {
		const wrapper = mountDialog()

		expect(wrapper.text()).toContain('Weet u zeker dat u Birth date permanent wilt verwijderen?')
		expect(wrapper.find('.note-warning').exists()).toBe(false)
		expect(wrapper.find('.cancel').text()).toBe('Annuleren')
		expect(wrapper.find('.confirm').attributes('disabled')).toBeUndefined()
	})

	it('warns, and holds the delete back, while objects use the property', () => {
		const wrapper = mountDialog({ objects: [{ id: 1 }] })

		expect(wrapper.find('.note-warning').text()).toContain('Meerdere objecten zullen niet beschikbaar zijn')
		expect(wrapper.find('.confirm').attributes('disabled')).toBeDefined()
	})

	it('says the property is deleted and offers only Sluiten', () => {
		const wrapper = mountDialog({ success: true })

		expect(wrapper.find('.note-success').text()).toBe('Schema-eigenschap succesvol verwijderd')
		expect(wrapper.find('.note-error').exists()).toBe(false)
		expect(wrapper.find('.cancel').text()).toBe('Sluiten')
		expect(wrapper.find('.confirm').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Weet u zeker')
	})

	it('shows the failure and its reason', () => {
		const wrapper = mountDialog({ success: false, error: 'Schema is locked' })

		const errors = wrapper.findAll('.note-error').map((note) => note.text())
		expect(errors).toEqual(['Er is een fout opgetreden bij het verwijderen van de schema-eigenschap', 'Schema is locked'])
	})

	it('keeps the cancel button disabled while deleting', () => {
		expect(mountDialog({ loading: true }).find('.cancel').attributes('disabled')).toBeDefined()
	})

	it('closes through the frame cancel button and forgets the property', async () => {
		const wrapper = mountDialog()

		await wrapper.find('.cancel').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
	})

	it('deletes through the frame confirm button, saving the schema without the property', async () => {
		const wrapper = mountDialog()

		await wrapper.find('.confirm').trigger('click')

		expect(schemaStore.saveSchema).toHaveBeenCalledTimes(1)
		const saved = schemaStore.saveSchema.mock.calls[0][0]
		expect(saved.properties).toEqual({ name: { title: 'Name' } })
		expect(saved.required).toEqual(['name'])
	})
})
