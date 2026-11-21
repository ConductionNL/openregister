import { flushPromises, mount } from '@vue/test-utils'
import EditApplication from './EditApplication.vue'
import { searchGroupsDebounced } from '../../services/groupSearch.js'
import { applicationStore, navigationStore, organisationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => s }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		applicationStore: reactive({
			applicationItem: null,
			nextcloudGroups: [],
			loadNextcloudGroups: jest.fn(),
			saveApplication: jest.fn(),
		}),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
		organisationStore: { refreshOrganisationList: jest.fn() },
	}
})
jest.mock('../../services/groupSearch.js', () => ({ __esModule: true, searchGroupsDebounced: jest.fn() }))
// vue3-jest merges this SFC's two <script> blocks so that both `vue` and
// `@nextcloud/vue` land on one `_vue` binding and the template reads the Nc*
// components off the `vue` module; the stand-ins are exposed on both.
jest.mock('@nextcloud/vue', () => ({ __esModule: true, ...mockNcStubs() }))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockNcStubs() }))
jest.mock('vue-material-design-icons/Cog.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('vue-material-design-icons/Database.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('vue-material-design-icons/Shield.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
// The app's own child components have their own specs; stand-ins render their
// slots and raise the events this modal listens to.
jest.mock('../../components/RbacTable.vue', () => ({
	__esModule: true,
	default: { name: 'RbacTable', props: ['authorization', 'availableGroups', 'organisationGroups'], emits: ['update'], template: '<div class="rbac" />' },
}))
jest.mock('../../components/shared/GroupSelect.vue', () => ({
	__esModule: true,
	default: { name: 'GroupSelect', props: ['modelValue', 'options'], emits: ['update:modelValue', 'search'], template: '<div class="groups" />' },
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({
	__esModule: true,
	default: { name: 'CloseCancelButton', props: ['label'], emits: ['click'], template: '<button class="cancel" @click="$emit(\'click\')">{{ label }}</button>' },
}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({ __esModule: true, default: { name: 'AppTabs', template: '<div><slot /></div>' } }))
jest.mock('../../components/tabs/AppTab.vue', () => ({ __esModule: true, default: { name: 'AppTab', template: '<div><slot name="title" /><slot /></div>' } }))

/**
 * Stand-ins for the Nextcloud components (hoisted for the factories).
 *
 * @return {object}
 */
function mockNcStubs() {
	const field = (name) => ({
		name,
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	})
	return {
		NcDialog: { name: 'NcDialog', props: ['name'], emits: ['update:open'], template: '<section class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></section>' },
		NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div :class="`note-${type}`"><slot /></div>' },
		NcTextField: field('NcTextField'),
		NcTextArea: field('NcTextArea'),
		NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
		NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button class="save" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	}
}

const GROUPS = [
	{ id: 'admin', name: 'admin', userCount: 1 },
	{ id: 'staff', name: 'staff', userCount: 4 },
]

/**
 * Mount the modal and let the mount-time loads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountModal() {
	const wrapper = mount(EditApplication)
	await flushPromises()
	return wrapper
}

describe('EditApplication', () => {
	let errorSpy

	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		applicationStore.applicationItem = null
		applicationStore.nextcloudGroups = GROUPS
	})

	afterEach(() => {
		jest.useRealTimers()
		errorSpy.mockRestore()
	})

	describe('opening', () => {
		it('refreshes the organisations and uses the cached groups', async () => {
			const wrapper = await mountModal()
			expect(organisationStore.refreshOrganisationList).toHaveBeenCalledTimes(1)
			expect(applicationStore.loadNextcloudGroups).not.toHaveBeenCalled()
			expect(wrapper.findComponent({ name: 'RbacTable' }).props('availableGroups')).toEqual(GROUPS)
		})

		it('titles a new application Create, with a disabled Create button until it has a name', async () => {
			const wrapper = await mountModal()
			expect(wrapper.find('h2').text()).toBe('Create Application')
			expect(wrapper.find('button.save').text()).toBe('Create')
			expect(wrapper.find('button.save').attributes('disabled')).toBeDefined()
			await wrapper.find('input').setValue('Zaken')
			expect(wrapper.find('button.save').attributes('disabled')).toBeUndefined()
		})

		it('loads an existing application, mapping its group ids to known or placeholder groups', async () => {
			applicationStore.applicationItem = { uuid: 'u1', name: 'Zaken', groups: ['staff', 'ghost'] }
			const wrapper = await mountModal()
			expect(wrapper.find('h2').text()).toBe('Edit Application')
			expect(wrapper.find('button.save').text()).toBe('Save')
			expect(wrapper.findComponent({ name: 'GroupSelect' }).props('modelValue')).toEqual([
				GROUPS[1],
				{ id: 'ghost', name: 'ghost', userCount: 0 },
			])
		})

		it('keeps the empty selection for an application without groups', async () => {
			applicationStore.applicationItem = { uuid: 'u1', name: 'Zaken', groups: [] }
			const wrapper = await mountModal()
			expect(wrapper.findComponent({ name: 'GroupSelect' }).props('modelValue')).toEqual([])
		})

		it('loads the groups when none are cached, then re-maps the selection', async () => {
			applicationStore.nextcloudGroups = []
			applicationStore.applicationItem = { uuid: 'u1', name: 'Zaken', groups: ['admin'] }
			applicationStore.loadNextcloudGroups.mockImplementation(async () => {
				applicationStore.nextcloudGroups = GROUPS
			})
			const wrapper = await mountModal()
			expect(applicationStore.loadNextcloudGroups).toHaveBeenCalledTimes(1)
			expect(wrapper.findComponent({ name: 'GroupSelect' }).props('modelValue')).toEqual([GROUPS[0]])
			expect(wrapper.findComponent({ name: 'RbacTable' }).props('availableGroups')).toEqual(GROUPS)
		})

		it('shows an error when the groups cannot be loaded', async () => {
			applicationStore.nextcloudGroups = null
			applicationStore.loadNextcloudGroups.mockRejectedValue(new Error('ocs down'))
			const wrapper = await mountModal()
			expect(wrapper.find('.note-error').text()).toBe('Failed to load Nextcloud groups')
		})
	})

	describe('groups and permissions', () => {
		it('stores only the selected group ids', async () => {
			const wrapper = await mountModal()
			wrapper.findComponent({ name: 'GroupSelect' }).vm.$emit('update:modelValue', GROUPS)
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent({ name: 'RbacTable' }).props('organisationGroups')).toEqual(['admin', 'staff'])
			wrapper.findComponent({ name: 'GroupSelect' }).vm.$emit('update:modelValue', null)
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent({ name: 'RbacTable' }).props('organisationGroups')).toEqual([])
		})

		it('only offers the assigned groups once groups are assigned', async () => {
			const wrapper = await mountModal()
			expect(wrapper.vm.filteredAvailableGroups).toEqual(GROUPS)
			wrapper.findComponent({ name: 'GroupSelect' }).vm.$emit('update:modelValue', [GROUPS[1]])
			expect(wrapper.vm.filteredAvailableGroups).toEqual([GROUPS[1]])
		})

		it('grants and revokes a permission without duplicates', async () => {
			const wrapper = await mountModal()
			const rbac = () => wrapper.findComponent({ name: 'RbacTable' })
			rbac().vm.$emit('update', { groupId: 'staff', action: 'read', hasPermission: true })
			rbac().vm.$emit('update', { groupId: 'staff', action: 'read', hasPermission: true })
			await wrapper.vm.$nextTick()
			expect(rbac().props('authorization').read).toEqual(['staff'])
			rbac().vm.$emit('update', { groupId: 'staff', action: 'read', hasPermission: false })
			rbac().vm.$emit('update', { groupId: 'nobody', action: 'read', hasPermission: false })
			await wrapper.vm.$nextTick()
			expect(rbac().props('authorization').read).toEqual([])
		})

		it('creates the authorization map and a missing action list on demand', async () => {
			applicationStore.applicationItem = { uuid: 'u1', name: 'Zaken', authorization: null }
			const wrapper = await mountModal()
			const rbac = () => wrapper.findComponent({ name: 'RbacTable' })
			rbac().vm.$emit('update', { groupId: 'admin', action: 'delete', hasPermission: true })
			rbac().vm.$emit('update', { groupId: 'admin', action: 'publish', hasPermission: true })
			await wrapper.vm.$nextTick()
			expect(rbac().props('authorization')).toEqual({ create: [], read: [], update: [], delete: ['admin'], publish: ['admin'] })
		})

		it('searches groups through the shared debounced search', async () => {
			const wrapper = await mountModal()
			wrapper.vm.searchGroups('st')
			expect(searchGroupsDebounced).toHaveBeenCalledWith(wrapper.vm.$.proxy, expect.objectContaining({ query: 'st', warnOnRefusal: false }))
			const opts = searchGroupsDebounced.mock.calls[0][1]
			expect(opts.toGroup('x')).toEqual({ id: 'x', name: 'x', userCount: 0 })
			applicationStore.nextcloudGroups = [GROUPS[0]]
			opts.onEmpty()
			expect(wrapper.vm.$data.availableGroups).toEqual([GROUPS[0]])
		})
	})

	describe('saving', () => {
		it('refuses a blank name without calling the store', async () => {
			const wrapper = await mountModal()
			wrapper.vm.$data.applicationItem.name = '   '
			await wrapper.vm.saveApplication()
			await wrapper.vm.$nextTick()
			expect(applicationStore.saveApplication).not.toHaveBeenCalled()
			expect(wrapper.find('.note-error').text()).toBe('Application name is required')
		})

		it('saves a copy of the form, shows success and closes after two seconds', async () => {
			applicationStore.saveApplication.mockResolvedValue({ response: { ok: true } })
			const wrapper = await mountModal()
			await wrapper.find('input').setValue('Zaken')
			await wrapper.find('button.save').trigger('click')
			await flushPromises()
			const sent = applicationStore.saveApplication.mock.calls[0][0]
			expect(sent.name).toBe('Zaken')
			expect(sent).not.toBe(wrapper.vm.$data.applicationItem)
			expect(wrapper.find('.note-success').text()).toBe('Application successfully created')
			expect(wrapper.find('button.cancel').text()).toBe('Close')
			expect(navigationStore.setModal).not.toHaveBeenCalled()
			jest.advanceTimersByTime(2000)
			expect(navigationStore.setModal).toHaveBeenCalledWith(false)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		})

		it('reports an updated application', async () => {
			applicationStore.applicationItem = { uuid: 'u1', name: 'Zaken' }
			applicationStore.saveApplication.mockResolvedValue({ response: { ok: true } })
			const wrapper = await mountModal()
			await wrapper.find('button.save').trigger('click')
			await flushPromises()
			expect(wrapper.find('.note-success').text()).toBe('Application successfully updated')
		})

		it('stays open without success when the server refuses', async () => {
			applicationStore.saveApplication.mockResolvedValue({ response: { ok: false } })
			const wrapper = await mountModal()
			await wrapper.find('input').setValue('Zaken')
			await wrapper.find('button.save').trigger('click')
			await flushPromises()
			jest.advanceTimersByTime(2000)
			expect(wrapper.find('.note-success').exists()).toBe(false)
			expect(navigationStore.setModal).not.toHaveBeenCalled()
		})

		it.each([
			[new Error('Name taken'), 'Name taken'],
			[{}, 'An error occurred while saving the application'],
		])('shows a thrown save error', async (error, message) => {
			applicationStore.saveApplication.mockRejectedValue(error)
			const wrapper = await mountModal()
			await wrapper.find('input').setValue('Zaken')
			await wrapper.find('button.save').trigger('click')
			await flushPromises()
			expect(wrapper.find('.note-error').text()).toBe(message)
		})
	})

	describe('closing', () => {
		it('cancel closes modal and dialog', async () => {
			const wrapper = await mountModal()
			await wrapper.find('button.cancel').trigger('click')
			expect(navigationStore.setModal).toHaveBeenCalledWith(false)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		})

		it('closes when the dialog closes, not when it opens', async () => {
			const wrapper = await mountModal()
			const dialog = wrapper.findComponent({ name: 'NcDialog' })
			dialog.vm.$emit('update:open', true)
			expect(navigationStore.setModal).not.toHaveBeenCalled()
			dialog.vm.$emit('update:open', false)
			expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		})
	})
})
