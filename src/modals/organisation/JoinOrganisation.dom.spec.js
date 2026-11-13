/**
 * The join organisation dialog as rendered: the organisation and user options
 * its selects show, the searches and selections they report, the add button
 * and its states, and closing.
 *
 * @spec exclude Client-side dialog markup and its event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import JoinOrganisation from './JoinOrganisation.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		`t:${text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match))}`,
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	/**
	 * A stand-in that renders its default slot, or nothing.
	 *
	 * @param {string} name The component name
	 * @return {object} The stub
	 */
	const plain = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h(`x-${name}`, {}, [this.$slots.default?.(), this.$slots.icon?.(), this.$slots.actions?.()])
		},
	})
	return {
		__esModule: true,
		NcDialog: plain('NcDialog'),
		NcButton: plain('NcButton'),
		NcNoteCard: plain('NcNoteCard'),
		NcLoadingIcon: plain('NcLoadingIcon'),
		NcSelect: {
			name: 'NcSelect',
			inheritAttrs: false,
			render() {
				const { options = [], modelValue } = this.$attrs
				return h('x-NcSelect', { id: this.$attrs.inputId }, [
					...options.map((option) => h('div', { class: 'option' }, this.$slots.option(option))),
					modelValue ? h('div', { class: 'selected' }, this.$slots['selected-option'](modelValue)) : null,
				])
			},
		},
	}
})
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({
	__esModule: true,
	default: { name: 'AccountPlus', render: () => null },
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({
	__esModule: true,
	default: { name: 'CloseCancelButton', inheritAttrs: false, render: () => null },
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {
		setModal: jest.fn(),
		getTransferData: jest.fn(() => null),
		clearTransferData: jest.fn(),
	},
	organisationStore: {
		searchOrganisations: jest.fn(),
		getOrganisation: jest.fn(),
		joinOrganisation: jest.fn(),
	},
}))

const ORGANISATIONS = [
	{ uuid: 'o-1', name: 'Gemeente A', description: 'The town hall', users: ['ann', 'bob'], isDefault: true },
	{ uuid: 'o-2', name: 'Waterschap B' },
]

/**
 * Mounts the dialog with the given organisations and users to load.
 *
 * @return {Promise<object>} The wrapper
 */
async function dialog() {
	const stores = jest.requireMock('../../store/store.js')
	stores.organisationStore.searchOrganisations.mockResolvedValue(ORGANISATIONS)
	global.fetch = jest.fn(() => Promise.resolve({
		ok: true,
		json: () => Promise.resolve({ ocs: { data: { users: ['bob', 'carl'] } } }),
	}))
	const { translate: t } = jest.requireMock('@nextcloud/l10n')
	const bindings = { ...JoinOrganisation.components, ...stores, t }
	const wrapper = mount({ ...JoinOrganisation, setup: () => bindings }, {
		global: { config: { warnHandler: () => {} } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The select whose input has the given id.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} id The input id
 * @return {object} The select component
 */
function select(wrapper, id) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((item) => item.vm.$attrs.inputId === id)
}

/**
 * The primary add button.
 *
 * @param {object} wrapper The mounted dialog
 * @return {object} The button component, or undefined once it is hidden
 */
function addButton(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((item) => item.text().includes('Add User'))
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	window.OC = { getCurrentUser: () => ({ uid: 'ann', displayName: 'Ann' }) }
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	jest.useRealTimers()
	delete window.OC
})

describe('JoinOrganisation options', () => {
	it('shows each organisation with its badge, description and member count', async () => {
		const wrapper = await dialog()
		const options = select(wrapper, 'organisation-select').findAll('.organisation-option')
		expect(options.map((option) => ({
			name: option.find('.organisation-name').text(),
			isDefault: option.find('.badge-default').exists(),
			description: option.find('.organisation-description').exists()
				? option.find('.organisation-description').text()
				: null,
			meta: option.find('.organisation-meta').text(),
		}))).toEqual([
			{ name: 'Gemeente A', isDefault: true, description: 'The town hall', meta: 't:2 members' },
			{ name: 'Waterschap B', isDefault: false, description: null, meta: 't:0 members' },
		])
	})

	it('shows each user with display name and id, the current user selected', async () => {
		const wrapper = await dialog()
		const users = select(wrapper, 'user-select')
		expect(users.findAll('.user-option').map((option) => [
			option.find('.user-name').text(),
			option.find('.user-id').text(),
		])).toEqual([['Ann', 'ann'], ['bob', 'bob'], ['carl', 'carl']])
		expect(users.find('.selected').text()).toBe('Ann')
	})

	it('filters organisations by name or description, ignoring case', async () => {
		const wrapper = await dialog()
		const filterBy = select(wrapper, 'organisation-select').vm.$attrs.filterBy
		const [first, second] = wrapper.vm.organisationOptions
		expect(filterBy(first, 'Gemeente A', 'TOWN')).toBe(true)
		expect(filterBy(first, 'Gemeente A', 'gemeente')).toBe(true)
		expect(filterBy(second, 'Waterschap B', 'town')).toBeFalsy()
	})
})

describe('JoinOrganisation searches and selections', () => {
	it('searches organisations after the debounce, from the select', async () => {
		jest.useFakeTimers()
		const wrapper = await dialog()
		const { organisationStore } = jest.requireMock('../../store/store.js')
		organisationStore.searchOrganisations.mockResolvedValueOnce([{ uuid: 'o-9', name: 'Found' }])
		select(wrapper, 'organisation-select').vm.$emit('search', '  Fou ')
		await jest.advanceTimersByTimeAsync(500)
		await flushPromises()
		expect(organisationStore.searchOrganisations).toHaveBeenLastCalledWith('Fou', 20, 0)
		expect(select(wrapper, 'organisation-select').findAll('.organisation-name').map((n) => n.text())).toEqual(['Found'])
	})

	it('searches users after the debounce, from the select', async () => {
		jest.useFakeTimers()
		const wrapper = await dialog()
		select(wrapper, 'user-select').vm.$emit('search', 'car')
		await jest.advanceTimersByTimeAsync(500)
		await flushPromises()
		expect(global.fetch).toHaveBeenLastCalledWith(
			'/ocs/v2.php/cloud/users?search=car&limit=20',
			expect.any(Object),
		)
	})

	it('stores the organisation and user picked in the selects', async () => {
		const wrapper = await dialog()
		const [, second] = wrapper.vm.organisationOptions
		select(wrapper, 'organisation-select').vm.$emit('update:modelValue', second)
		select(wrapper, 'user-select').vm.$emit('update:modelValue', { id: 'bob', displayName: 'bob' })
		await flushPromises()
		expect(wrapper.vm.selectedOrganisation).toBe(second)
		expect(wrapper.vm.selectedUser).toEqual({ id: 'bob', displayName: 'bob' })
		expect(select(wrapper, 'organisation-select').find('.selected').text()).toBe('Waterschap B')
	})
})

describe('JoinOrganisation adding', () => {
	it('keeps the add button disabled until an organisation is picked', async () => {
		const wrapper = await dialog()
		expect(addButton(wrapper).vm.$attrs.disabled).toBe(true)
		wrapper.vm.selectedOrganisation = wrapper.vm.organisationOptions[0]
		await flushPromises()
		expect(addButton(wrapper).vm.$attrs.disabled).toBe(false)
	})

	it('adds the user from the button, then shows success and hides the form', async () => {
		jest.useFakeTimers()
		const wrapper = await dialog()
		const { organisationStore, navigationStore } = jest.requireMock('../../store/store.js')
		organisationStore.joinOrganisation.mockResolvedValue({})
		wrapper.vm.selectedOrganisation = wrapper.vm.organisationOptions[0]
		await flushPromises()
		await addButton(wrapper).vm.$attrs.onClick()
		await flushPromises()
		expect(organisationStore.joinOrganisation).toHaveBeenCalledWith('o-1', 'ann')
		expect(wrapper.text()).toContain('t:Successfully added user to organisation: Gemeente A')
		expect(wrapper.find('.selection-section').exists()).toBe(false)
		expect(addButton(wrapper)).toBeUndefined()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(3000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('shows the refusal the store reports', async () => {
		const wrapper = await dialog()
		const { organisationStore } = jest.requireMock('../../store/store.js')
		organisationStore.joinOrganisation.mockRejectedValue(new Error('Already a member'))
		wrapper.vm.selectedOrganisation = wrapper.vm.organisationOptions[0]
		await flushPromises()
		await addButton(wrapper).vm.$attrs.onClick()
		await flushPromises()
		expect(wrapper.text()).toContain('Already a member')
		expect(wrapper.find('.selection-section').exists()).toBe(true)
		expect(addButton(wrapper).vm.$attrs.disabled).toBe(false)
	})
})

describe('JoinOrganisation closing', () => {
	it('closes and resets from the cancel button', async () => {
		const wrapper = await dialog()
		const { navigationStore } = jest.requireMock('../../store/store.js')
		wrapper.vm.selectedOrganisation = wrapper.vm.organisationOptions[0]
		wrapper.vm.error = 'stale'
		wrapper.findComponent({ name: 'CloseCancelButton' }).vm.$attrs.onClick()
		await flushPromises()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.selectedOrganisation).toBeNull()
		expect(wrapper.vm.organisationOptions).toEqual([])
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.selectedUser).toMatchObject({ id: 'ann' })
	})

	it('closes when the dialog reports it closed', async () => {
		const wrapper = await dialog()
		const { navigationStore } = jest.requireMock('../../store/store.js')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$attrs['onUpdate:open'](false)
		await flushPromises()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})
