/**
 * OrganisationMembers: the Users tab of the organisation modal. Who is
 * listed, who can be removed (never the owner), what the buttons emit, and
 * the loading, empty and unsaved states.
 *
 * @spec exclude Unit spec of a presentational member list.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import OrganisationMembers from './OrganisationMembers.vue'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match)),
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading-icon" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))

const idle = { loading: false, loadingUsers: false, removingUser: null }

/**
 * Mount the member list.
 *
 * @param {object} props Props over a saved organisation owned by alice, with alice and bob as members
 * @return {object} The wrapper
 */
function open(props = {}) {
	return mount(OrganisationMembers, {
		props: {
			organisation: { uuid: 'org-1', owner: 'alice' },
			members: ['alice', 'bob'],
			state: { ...idle },
			...props,
		},
	})
}

const rows = (wrapper) => wrapper.findAll('.user-item')
const removeButton = (row) => row.find('button')

describe('OrganisationMembers', () => {
	it('lists every member under a counted heading', () => {
		const wrapper = open()

		expect(wrapper.find('h3').text()).toBe('Members (2)')
		expect(rows(wrapper).map((row) => row.find('.user-id').text())).toEqual(['alice', 'bob'])
	})

	it('badges the owner and offers no remove for them', () => {
		const [owner, member] = rows(open())

		expect(owner.find('.owner-badge').text()).toBe('Owner')
		expect(removeButton(owner).exists()).toBe(false)
		expect(member.find('.owner-badge').exists()).toBe(false)
		expect(removeButton(member).text()).toBe('Remove')
	})

	it('emits remove with the member to remove', async () => {
		const wrapper = open()

		await removeButton(rows(wrapper)[1]).trigger('click')

		expect(wrapper.emitted('remove')).toEqual([['bob']])
	})

	it('disables and spins the remove of the member being removed only', () => {
		const wrapper = open({ members: ['alice', 'bob', 'carol'], state: { ...idle, removingUser: 'bob' } })
		const [, bob, carol] = rows(wrapper)

		expect(removeButton(bob).attributes('disabled')).toBeDefined()
		expect(bob.find('.loading-icon').exists()).toBe(true)
		expect(removeButton(carol).attributes('disabled')).toBeUndefined()
		expect(carol.find('.loading-icon').exists()).toBe(false)
	})

	it('disables every button while the organisation saves', () => {
		const wrapper = open({ state: { ...idle, loading: true } })

		expect(wrapper.findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true)
	})

	it('emits add from the add button of a saved organisation', async () => {
		const wrapper = open()
		const add = wrapper.find('.users-header button')

		expect(add.text()).toBe('Add User')
		expect(add.attributes('data-variant')).toBe('primary')
		await add.trigger('click')

		expect(wrapper.emitted('add')).toHaveLength(1)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('shows a loading line instead of the list while members load', () => {
		const wrapper = open({ state: { ...idle, loadingUsers: true } })

		expect(wrapper.find('.loading-users').text()).toBe('Loading users...')
		expect(wrapper.find('.users-list').exists()).toBe(false)
		expect(wrapper.find('.no-users').exists()).toBe(false)
	})

	it('says so when the organisation has no members', () => {
		const wrapper = open({ members: [] })

		expect(wrapper.find('.no-users').text()).toBe('No users in this organisation.')
		expect(wrapper.find('.users-list').exists()).toBe(false)
	})

	it('asks to save first and offers no add for an unsaved organisation', () => {
		const wrapper = open({ organisation: { owner: 'alice' }, members: [] })

		expect(wrapper.find('.users-header button').exists()).toBe(false)
		expect(wrapper.find('.note').attributes('data-type')).toBe('warning')
		expect(wrapper.find('.note').text()).toBe('Save the organisation first to manage users.')
	})
})
