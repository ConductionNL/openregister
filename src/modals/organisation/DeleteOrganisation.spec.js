/**
 * DeleteOrganisation: when the delete is offered (owner, no other members,
 * not default, not active), what confirm sends, and how success, failure
 * and close are handled.
 *
 * @spec exclude Unit spec of client-side dialog logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DeleteOrganisation from './DeleteOrganisation.vue'
import { navigationStore, organisationStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
}))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		organisationStore: reactive({ organisationItem: null, userStats: { active: null }, deleteOrganisation: jest.fn() }),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})
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

const router = { push: jest.fn() }

const frame = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialogFrame' })
const attrs = (wrapper) => frame(wrapper).vm.$attrs
const text = (wrapper) => wrapper.text().replace(/\s+/g, ' ')

/**
 * Mount the dialog with its store globals.
 *
 * @return {object} The wrapper
 */
function open() {
	// The <script setup> block only imports; supplying its bindings the same
	// way keeps the options-API part (the subject) as written.
	const bindings = { ...DeleteOrganisation.components, navigationStore, organisationStore, t: (app, s) => s }
	return mount(
		{ ...DeleteOrganisation, setup: () => bindings },
		{ global: { config: { globalProperties: { $router: router } } } },
	)
}

/**
 * An organisation the current user may delete.
 *
 * @param {object} overrides Fields to change
 * @return {object} The organisation
 */
function deletable(overrides = {}) {
	return { uuid: 'org-1', name: 'Gemeente', owner: 'current-user', users: ['current-user'], isDefault: false, ...overrides }
}

describe('DeleteOrganisation', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		organisationStore.organisationItem = deletable()
		organisationStore.userStats = { active: { uuid: 'org-2' } }
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.useRealTimers()
		console.error.mockRestore()
	})

	it('offers the delete for an owned, empty, inactive, non-default organisation', () => {
		const wrapper = open()
		expect(attrs(wrapper).confirmDisabled).toBe(false)
		expect(attrs(wrapper).showConfirm).toBe(true)
		expect(attrs(wrapper).closeLabel).toBe('Cancel')
		expect(wrapper.find('b').text()).toBe('Gemeente')
		expect(wrapper.find('.note').exists()).toBe(true)
	})

	it.each([
		['the default organisation', { isDefault: true }, null, 'Cannot delete the default organisation'],
		['an organisation someone else owns', { owner: 'other' }, null, 'You can only delete organisations that you own'],
		['an organisation with members', { users: ['current-user', 'b', 'c'] }, null, 'This organisation has 3 members'],
		['the active organisation', {}, { uuid: 'org-1' }, 'Cannot delete your currently active organisation'],
	])('refuses %s', (label, fields, active, reason) => {
		organisationStore.organisationItem = deletable(fields)
		if (active) {
			organisationStore.userStats = { active }
		}
		const wrapper = open()
		expect(attrs(wrapper).confirmDisabled).toBe(true)
		expect(text(wrapper)).toContain(reason)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('treats a missing organisation as not deletable with no members', () => {
		organisationStore.organisationItem = null
		const wrapper = open()
		expect(wrapper.vm.memberCount).toBe(0)
		expect(wrapper.vm.hasMembers).toBe(false)
		expect(wrapper.vm.isOwner).toBe(false)
		expect(attrs(wrapper).confirmDisabled).toBe(true)
	})

	it('confirm deletes a copy of the organisation, navigates back and closes after two seconds', async () => {
		organisationStore.deleteOrganisation.mockResolvedValue({ response: { ok: true } })
		const wrapper = open()
		frame(wrapper).vm.$emit('confirm')
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()

		expect(organisationStore.deleteOrganisation).toHaveBeenCalledWith(deletable())
		expect(organisationStore.deleteOrganisation.mock.calls[0][0]).not.toBe(organisationStore.organisationItem)
		expect(router.push).toHaveBeenCalledWith('/organisation')
		expect(attrs(wrapper).success).toBe(true)
		expect(attrs(wrapper).loading).toBe(false)
		expect(attrs(wrapper).closeLabel).toBe('Close')
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.success).toBe(false)
	})

	it('a refused delete neither navigates nor closes', async () => {
		organisationStore.deleteOrganisation.mockResolvedValue({ response: { ok: false } })
		const wrapper = open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(wrapper.vm.success).toBe(false)
		expect(router.push).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it.each([
		[new Error('Forbidden'), 'Forbidden'],
		[{}, 'An error occurred while deleting the organisation'],
	])('shows the failure message when the delete throws %p', async (failure, message) => {
		organisationStore.deleteOrganisation.mockRejectedValue(failure)
		const wrapper = open()
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).error).toBe(message)
		expect(attrs(wrapper).success).toBe(false)
		expect(attrs(wrapper).loading).toBe(false)
	})

	it('cancel closes the modal and clears its state', async () => {
		const wrapper = open()
		wrapper.vm.error = 'old'
		wrapper.vm.loading = true
		frame(wrapper).vm.$emit('cancel')
		await flushPromises()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.error).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
	})
})
