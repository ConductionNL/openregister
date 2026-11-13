import { mount } from '@vue/test-utils'
import OrganisationMembers from './OrganisationMembers.vue'
import RemoveUserDialog from './RemoveUserDialog.vue'
import SwitchOrganisationModal from './SwitchOrganisationModal.vue'

/**
 * The small organisation dialogs: the member list of the organisation modal,
 * the remove-user confirmation and the active-organisation switcher. What
 * they show for each state and what they emit.
 *
 * @spec exclude Client-side behaviour of presentational organisation dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		`t:${text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match)}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const simple = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['type', 'variant', 'disabled', 'size', 'name'],
		emits: ['click', 'close'],
		render() {
			return h(
				tag,
				{
					class: `x-${name}`,
					'data-type': this.type,
					disabled: this.disabled || undefined,
					onClick: () => this.$emit('click'),
				},
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	})
	return {
		__esModule: true,
		NcButton: simple('NcButton', 'button'),
		NcLoadingIcon: simple('NcLoadingIcon', 'span'),
		NcNoteCard: simple('NcNoteCard'),
		NcModal: simple('NcModal'),
	}
})

const ORGANISATION = { uuid: 'org-1', owner: 'anna' }
const IDLE = { loading: false, loadingUsers: false, removingUser: null }

/**
 * Mount the member list.
 *
 * @param {object} props The props that differ from a saved organisation with two members
 * @return {object} The wrapper
 */
function members(props = {}) {
	return mount(OrganisationMembers, {
		props: { organisation: ORGANISATION, members: ['anna', 'bram'], state: IDLE, ...props },
	})
}

describe('OrganisationMembers', () => {
	it('lists the members with the owner badged and only others removable', async () => {
		const wrapper = members()

		expect(wrapper.find('h3').text()).toBe('t:Members (2)')
		expect(wrapper.findAll('.user-id').map((node) => node.text())).toEqual(['anna', 'bram'])
		expect(wrapper.findAll('.owner-badge').map((node) => node.text())).toEqual(['t:Owner'])

		const remove = wrapper.findAll('.user-item')[1].find('button')
		expect(wrapper.findAll('.user-item')[0].find('button').exists()).toBe(false)
		await remove.trigger('click')
		expect(wrapper.emitted('remove')).toEqual([['bram']])
	})

	it('offers adding a user only once the organisation is saved', async () => {
		const saved = members()
		await saved.find('.users-header button').trigger('click')
		expect(saved.emitted('add')).toEqual([[]])
		expect(saved.find('[data-type="warning"]').exists()).toBe(false)

		const unsaved = members({ organisation: { owner: 'anna' }, members: [] })
		expect(unsaved.find('.users-header button').exists()).toBe(false)
		expect(unsaved.find('[data-type="warning"]').text()).toBe('t:Save the organisation first to manage users.')
		expect(unsaved.find('.no-users').text()).toBe('t:No users in this organisation.')
	})

	it('shows the loading state instead of the list', () => {
		const wrapper = members({ state: { ...IDLE, loadingUsers: true } })

		expect(wrapper.find('.loading-users').text()).toBe('t:Loading users...')
		expect(wrapper.find('.users-list').exists()).toBe(false)
		expect(wrapper.find('.no-users').exists()).toBe(false)
	})

	it('disables the buttons while busy and spins on the member being removed', () => {
		const removing = members({ members: ['anna', 'bram', 'cees'], state: { ...IDLE, removingUser: 'bram' } })
		const [bram, cees] = removing.findAll('.user-item').slice(1)
		expect(bram.find('button').attributes('disabled')).toBeDefined()
		expect(bram.find('.x-NcLoadingIcon').exists()).toBe(true)
		expect(cees.find('button').attributes('disabled')).toBeUndefined()
		expect(cees.find('.x-NcLoadingIcon').exists()).toBe(false)

		const busy = members({ state: { ...IDLE, loading: true } })
		expect(busy.find('.users-header button').attributes('disabled')).toBeDefined()
		expect(busy.findAll('.user-item')[1].find('button').attributes('disabled')).toBeDefined()
	})
})

describe('RemoveUserDialog', () => {
	const ConfirmDialogFrame = {
		name: 'ConfirmDialogFrame',
		props: ['name', 'closable', 'loading', 'cancelDisabled', 'confirmLabel', 'showConfirm'],
		emits: ['cancel', 'confirm'],
		template: '<div class="frame"><slot /></div>',
	}

	/**
	 * Mount the dialog with the confirm frame stubbed.
	 *
	 * @param {object} props The props
	 * @return {object} The wrapper
	 */
	function dialog(props) {
		return mount(RemoveUserDialog, { props, global: { stubs: { ConfirmDialogFrame, AccountMinus: true } } })
	}

	it('names the user and forwards cancel and confirm', () => {
		const wrapper = dialog({ show: true, userId: 'bram' })
		const frame = wrapper.findComponent(ConfirmDialogFrame)

		expect(wrapper.find('strong').text()).toBe('bram')
		expect(frame.props()).toMatchObject({ name: 'Remove User', closable: true, loading: false, cancelDisabled: false, confirmLabel: 'Remove User' })
		frame.vm.$emit('confirm')
		frame.vm.$emit('cancel')
		expect(wrapper.emitted('confirm')).toEqual([[]])
		expect(wrapper.emitted('cancel')).toEqual([[]])
	})

	it('cannot be closed while removing, and renders nothing when hidden', () => {
		const removing = dialog({ show: true, userId: 'bram', removing: true })
		expect(removing.findComponent(ConfirmDialogFrame).props()).toMatchObject({ closable: false, loading: true, cancelDisabled: true })

		expect(dialog({ show: false, userId: 'bram' }).findComponent(ConfirmDialogFrame).exists()).toBe(false)
	})
})

describe('SwitchOrganisationModal', () => {
	const ORGANISATIONS = [
		{ uuid: 'a', name: 'Gemeente A', isDefault: true, description: 'Hoofdorganisatie' },
		{ uuid: 'b', name: 'Gemeente B' },
	]

	/**
	 * Mount the switcher.
	 *
	 * @param {object} props The props that differ from showing both organisations with B active
	 * @return {object} The wrapper
	 */
	function switcher(props = {}) {
		return mount(SwitchOrganisationModal, { props: { show: true, organisations: ORGANISATIONS, activeOrganisationUuid: 'b', ...props } })
	}

	it('marks the default and the active organisation', () => {
		const options = switcher().findAll('.organisationOption')

		expect(options.map((option) => option.find('.organisationOptionName').text())).toEqual(['Gemeente A', 'Gemeente B'])
		expect(options.map((option) => option.classes('active'))).toEqual([false, true])
		expect(options[0].find('.defaultBadge').text()).toBe('t:Default')
		expect(options[0].find('.activeBadge').exists()).toBe(false)
		expect(options[1].find('.activeBadge').text()).toBe('t:Current')
		expect(options[0].find('.organisationOptionDescription').text()).toBe('Hoofdorganisatie')
		expect(options[1].find('.organisationOptionDescription').exists()).toBe(false)
	})

	it('treats no active organisation as none active, even one without a uuid', () => {
		const wrapper = switcher({ activeOrganisationUuid: null, organisations: [{ name: 'Zonder uuid', uuid: null }] })

		expect(wrapper.find('.organisationOption').classes('active')).toBe(false)
	})

	it('switches by click, Enter or Space and closes from the modal', async () => {
		const wrapper = switcher()
		const [first, second] = wrapper.findAll('.organisationOption')

		await first.trigger('click')
		await second.trigger('keydown', { key: 'Enter' })
		await second.trigger('keydown', { key: ' ' })
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('switch')).toEqual([[ORGANISATIONS[0]], [ORGANISATIONS[1]], [ORGANISATIONS[1]]])
		expect(wrapper.emitted('close')).toEqual([[]])
	})

	it('renders nothing when hidden', () => {
		expect(switcher({ show: false }).find('.organisationSwitcher').exists()).toBe(false)
	})
})
