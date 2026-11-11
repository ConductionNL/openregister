import { flushPromises, mount } from '@vue/test-utils'
import PurgeMultiple from './PurgeMultiple.vue'
import { deletedStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, s, vars = {}) => s.replace(/{(\w+)}/g, (m, k) => vars[k] ?? m),
	translatePlural: (app, one, many, count, vars = {}) =>
		(count === 1 ? one : many).replace(/{(\w+)}/g, (m, k) => vars[k] ?? m),
}))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		deletedStore: reactive({ selectedForBulkAction: [], permanentlyDeleteMultiple: jest.fn(), clearSelectedForBulkAction: jest.fn() }),
		navigationStore: reactive({ dialog: 'permanentlyDeleteMultiple', setDialog: jest.fn() }),
	}
})
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { emit: jest.fn() } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { template: '<i class="trash" />' } }))
// The shared review dialog has its own specs; a stand-in exposes the props this
// dialog passes and raises the events it listens to.
jest.mock('./BulkSelectionDialog.vue', () => ({
	__esModule: true,
	default: {
		name: 'BulkSelectionDialog',
		inheritAttrs: false,
		emits: ['close', 'remove', 'submit'],
		template: '<div class="bulk"><slot name="submitIcon" /></div>',
	},
}))

const SELECTED = [{ id: 'a' }, { id: 'b' }]
const dialog = (wrapper) => wrapper.findComponent({ name: 'BulkSelectionDialog' })

describe('PurgeMultiple (mounted)', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		navigationStore.dialog = 'permanentlyDeleteMultiple'
		deletedStore.selectedForBulkAction = [...SELECTED]
	})

	it('renders only while its dialog is open', () => {
		navigationStore.dialog = 'restoreMultiple'
		expect(mount(PurgeMultiple).find('.bulk').exists()).toBe(false)
	})

	it('takes the bulk selection on mount and titles the dialog with its count', async () => {
		const wrapper = mount(PurgeMultiple)
		await wrapper.vm.$nextTick()
		const attrs = dialog(wrapper).vm.$attrs
		expect(attrs.name).toBe('Purge 2 objects from database')
		expect(attrs.heading).toBe('Confirm Permanent Object Deletion')
		expect(attrs.submitLabel).toBe('Purge')
		expect(attrs.objects).toEqual(SELECTED)
		expect(attrs.note).toContain('This action cannot be undone.')
	})

	it('uses the singular title for one object and renders the trash icon', async () => {
		deletedStore.selectedForBulkAction = [{ id: 'a' }]
		const wrapper = mount(PurgeMultiple)
		await wrapper.vm.$nextTick()
		expect(dialog(wrapper).vm.$attrs.name).toBe('Purge 1 object from database')
		expect(wrapper.find('.trash').exists()).toBe(true)
	})

	it('removing an object drops it from the selection', async () => {
		const wrapper = mount(PurgeMultiple)
		dialog(wrapper).vm.$emit('remove', 'a')
		await wrapper.vm.$nextTick()
		expect(dialog(wrapper).vm.$attrs.objects).toEqual([{ id: 'b' }])
	})

	it('submit purges the selected ids and reports the count', async () => {
		deletedStore.permanentlyDeleteMultiple.mockResolvedValue({ deleted: 2, failed: 0 })
		const wrapper = mount(PurgeMultiple)
		dialog(wrapper).vm.$emit('submit')
		await flushPromises()
		expect(deletedStore.permanentlyDeleteMultiple).toHaveBeenCalledWith(['a', 'b'])
		expect(dialog(wrapper).vm.$attrs.successMessage).toBe('Successfully permanently deleted 2 objects')
	})

	it('close closes the dialog', () => {
		const wrapper = mount(PurgeMultiple)
		dialog(wrapper).vm.$emit('close')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(deletedStore.clearSelectedForBulkAction).toHaveBeenCalled()
	})
})
