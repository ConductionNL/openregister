import axios from '@nextcloud/axios'
/**
 * The organisation roles dialog mounted: the groups it loads and offers, the
 * groups it adds and removes, the save it sends and the auto-close after it,
 * and the errors it shows when loading or saving fails.
 *
 * @spec exclude Client-side organisation roles dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ManageOrganisationRoles from './ManageOrganisationRoles.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The dialog mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcDialog: mockLibrary().NcDialog,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
}))
jest.mock('vue-material-design-icons/AccountGroup.vue', () => mockModule('AccountGroup'))
jest.mock('vue-material-design-icons/Close.vue', () => mockModule('Close'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => mockModule('ContentSaveOutline'))
jest.mock('../../components/shared/CloseCancelButton.vue', () => mockModule('CloseCancelButton'))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	organisationStore: { organisationItem: null, saveOrganisation: jest.fn() },
}))

const { navigationStore, organisationStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and every slot.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'function')
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			// A scoped `option` slot renders once per option, as the select does.
			const slots = Object.entries(this.$slots).map(([slot, fn]) =>
				render(
					'section',
					{ 'data-slot': slot },
					slot === 'option' ? (this.$attrs.options || []).flatMap((option) => fn(option)) : fn(),
				),
			)
			return render(`x-${name}`, attrs, slots)
		},
	}
}

/**
 * Mount the dialog and let the group load settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function open() {
	const wrapper = mount(ManageOrganisationRoles, {
		global: { config: { globalProperties: { t: (app, text) => `t:${text}` } } },
	})
	await flushPromises()
	return wrapper
}

const select = (wrapper) => wrapper.findComponent({ name: 'NcSelect' })
const notes = (wrapper) => wrapper.findAllComponents({ name: 'NcNoteCard' })
const chips = (wrapper) => wrapper.findAll('.role-chip .role-name').map((chip) => chip.text())

/**
 * The Save Roles button.
 *
 * @param {object} wrapper The mounted dialog
 * @return {object} The button stub
 */
function saveButton(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((button) => button.text().endsWith('t:Save Roles'))
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	organisationStore.organisationItem = {
		uuid: 'org-1',
		name: 'Gemeente',
		roles: [{ id: 'admin', name: 'admin', userCount: 0 }],
	}
	axios.get.mockResolvedValue({ data: { ocs: { data: { groups: ['admin', 'editors', 'viewers'] } } } })
	organisationStore.saveOrganisation.mockResolvedValue()
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('ManageOrganisationRoles mounted', () => {
	it('loads the Nextcloud groups and offers those not yet selected', async () => {
		const wrapper = await open()
		expect(axios.get).toHaveBeenCalledWith('/index.php/ocs/v1.php/cloud/groups?format=json', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(wrapper.get('.organisation-info h3').text()).toBe('Gemeente')
		expect(chips(wrapper)).toEqual(['admin'])
		expect(wrapper.get('.selected-roles-section h4').text()).toBe('t:Selected Groups (1)')
		expect(select(wrapper).vm.$attrs.options).toEqual([
			{ id: 'editors', name: 'editors', userCount: 0, label: 'editors' },
			{ id: 'viewers', name: 'viewers', userCount: 0, label: 'viewers' },
		])
		expect(select(wrapper).vm.$attrs.loading).toBe(false)
		expect(wrapper.findAll('.group-name').map((name) => name.text())).toEqual(['editors', 'viewers'])
		expect(wrapper.findAll('.group-meta').map((meta) => meta.text())).toEqual(['0 t:members', '0 t:members'])
		expect(saveButton(wrapper).vm.$attrs.disabled).toBe(true)
	})

	it('starts empty for an organisation that is not saved yet', async () => {
		organisationStore.organisationItem = { name: 'Draft', roles: [{ id: 'admin' }] }
		const wrapper = await open()
		expect(wrapper.get('.organisation-info h3').text()).toBe('')
		expect(wrapper.find('.selected-roles-section').exists()).toBe(false)
		expect(select(wrapper).vm.$attrs.options).toHaveLength(3)
	})

	it('treats organisation roles that are not a list as none', async () => {
		organisationStore.organisationItem = { uuid: 'org-1', name: 'Gemeente', roles: 'admin' }
		const wrapper = await open()
		expect(wrapper.find('.selected-roles-section').exists()).toBe(false)
	})

	it('offers nothing when the answer carries no groups', async () => {
		axios.get.mockResolvedValue({ data: { ocs: { data: {} } } })
		const wrapper = await open()
		expect(select(wrapper).vm.$attrs.options).toEqual([])
		expect(notes(wrapper)).toHaveLength(0)
	})

	it('shows an error when the groups cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('forbidden'))
		const wrapper = await open()
		expect(console.error).toHaveBeenCalledWith('Error loading Nextcloud groups:', expect.any(Error))
		expect(notes(wrapper).map((note) => note.text())).toEqual(['t:Failed to load Nextcloud groups'])
		expect(select(wrapper).vm.$attrs.loading).toBe(false)
	})

	it('adds a picked group once, clears the pick and enables saving', async () => {
		const wrapper = await open()
		const editors = select(wrapper).vm.$attrs.options[0]
		await select(wrapper).vm.$emit('update:modelValue', editors)
		expect(chips(wrapper)).toEqual(['admin', 'editors'])
		expect(wrapper.vm.roleToAdd).toBeNull()
		expect(select(wrapper).vm.$attrs.options.map((option) => option.id)).toEqual(['viewers'])
		expect(saveButton(wrapper).vm.$attrs.disabled).toBe(false)

		await select(wrapper).vm.$emit('update:modelValue', editors)
		await select(wrapper).vm.$emit('update:modelValue', null)
		expect(chips(wrapper)).toEqual(['admin', 'editors'])
	})

	it('removes a group with its chip button', async () => {
		const wrapper = await open()
		const remove = wrapper.get('.role-chip').findComponent({ name: 'NcButton' })
		expect(remove.vm.$attrs['aria-label']).toBe('t:Remove group')
		await remove.vm.$emit('click')
		expect(wrapper.find('.selected-roles-section').exists()).toBe(false)
		expect(saveButton(wrapper).vm.$attrs.disabled).toBe(false)
	})

	it('saves the selected groups, reports success and closes after two seconds', async () => {
		jest.useFakeTimers()
		const wrapper = await open()
		await select(wrapper).vm.$emit('update:modelValue', select(wrapper).vm.$attrs.options[1])
		saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(organisationStore.saveOrganisation).toHaveBeenCalledWith({
			uuid: 'org-1',
			name: 'Gemeente',
			roles: [
				{ id: 'admin', name: 'admin', userCount: 0 },
				{ id: 'viewers', name: 'viewers', userCount: 0 },
			],
		})
		expect(notes(wrapper).map((note) => note.text())).toEqual(['t:Roles updated successfully'])
		expect(wrapper.find('.roles-manager').exists()).toBe(false)
		expect(saveButton(wrapper)).toBeUndefined()

		jest.advanceTimersByTime(1999)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it.each([
		[new Error('Not allowed'), 'Not allowed'],
		[new Error(''), 't:Failed to save roles'],
	])('shows why a save failed %#', async (failure, message) => {
		organisationStore.saveOrganisation.mockRejectedValue(failure)
		const wrapper = await open()
		await wrapper.get('.role-chip').findComponent({ name: 'NcButton' }).vm.$emit('click')
		saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(console.error).toHaveBeenCalledWith('Error saving roles:', failure)
		expect(notes(wrapper).map((note) => note.text())).toEqual([message])
		expect(saveButton(wrapper).vm.$attrs.disabled).toBe(false)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('closes when the dialog or its close button closes', async () => {
		const wrapper = await open()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		navigationStore.setModal.mockClear()
		wrapper.findComponent({ name: 'CloseCancelButton' }).vm.$emit('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})
})
