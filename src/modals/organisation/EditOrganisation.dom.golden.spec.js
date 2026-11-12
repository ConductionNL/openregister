import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the organisation modal: its rendered DOM in each state,
 * the group loading and quota fields, the special rights, and what closing
 * and creating another organisation reset.
 *
 * @spec exclude Golden record of client-side dialog markup and form actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditOrganisation from './EditOrganisation.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcNoteCard: mockStub('NcNoteCard'),
	NcSelect: mockStub('NcSelect'),
	NcTextArea: mockStub('NcTextArea'),
	NcTextField: mockStub('NcTextField'),
}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () =>
	mockModule('AccountCircle'),
)
jest.mock('vue-material-design-icons/AccountMinus.vue', () =>
	mockModule('AccountMinus'),
)
jest.mock('vue-material-design-icons/AccountMultiple.vue', () =>
	mockModule('AccountMultiple'),
)
jest.mock('vue-material-design-icons/AccountPlus.vue', () =>
	mockModule('AccountPlus'),
)
jest.mock('vue-material-design-icons/Cancel.vue', () => mockModule('Cancel'))
jest.mock('vue-material-design-icons/Cog.vue', () => mockModule('Cog'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () =>
	mockModule('ContentSaveOutline'),
)
jest.mock('vue-material-design-icons/Database.vue', () => mockModule('Database'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockModule('Plus'))
jest.mock('vue-material-design-icons/Shield.vue', () => mockModule('Shield'))
jest.mock('../../components/RbacTable.vue', () => mockModule('RbacTable'))
jest.mock('../../components/tabs/AppTab.vue', () => mockModule('AppTab'))
jest.mock('../../components/tabs/AppTabs.vue', () => mockModule('AppTabs'))
jest.mock('./RemoveUserDialog.vue', () => mockModule('RemoveUserDialog'))
jest.mock('../../services/groupSearch.js', () => ({
	searchGroupsDebounced: jest.fn(),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	organisationStore: { organisationItem: null },
}))

const stores = jest.requireMock('../../store/store.js')
const { navigationStore, organisationStore } = stores
const { searchGroupsDebounced } = jest.requireMock('../../services/groupSearch.js')

const GROUPS = [
	{ id: 'admin', name: 'Admins', userCount: 2 },
	{ id: 'staff', name: 'Staff', userCount: 9 },
]
const STORED = {
	uuid: 'u1',
	name: 'Org',
	slug: 'org',
	description: 'd',
	owner: 'owner',
	active: false,
	users: ['owner', 'member'],
	groups: ['admin', 'gone'],
	quota: {
		storage: 3145728,
		bandwidth: 1048576,
		requests: 7,
		users: 8,
		groups: 9,
	},
	authorization: {
		register: { read: ['admin'] },
		object_publish: ['admin', 'gone'],
		agent_use: 'odd',
	},
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component name
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, attributes as text, and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	const text = (value) =>
		typeof value === 'object' ? JSON.stringify(value) : value
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const listeners = Object.keys(attrs).filter((key) =>
					/^on[A-Z]/.test(key),
				)
				const plain = Object.fromEntries(
					Object.entries(attrs)
						.filter(([key]) => !listeners.includes(key))
						.map(([key, value]) => [key, text(value)]),
				)
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without scoped-style ids, comment nodes and the line break an
 * empty v-if leaves after a span.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/<\/span>\s+<\/div>/g, '</span></div>')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

/**
 * The modal with its setup bindings supplied. Under jest the compiled bindings
 * of an SFC with both a setup and a plain script resolve the library components
 * to undefined, so the modal would render nothing.
 *
 * @return {object} The modal with stand-in bindings
 */
function organisationModal() {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const bindings = {
		...EditOrganisation.components,
		...jest.requireMock('@nextcloud/vue'),
		...stores,
		AccountCircle: mockStub('AccountCircle'),
		AccountMinus: mockStub('AccountMinus'),
		AccountMultiple: mockStub('AccountMultiple'),
		AccountPlus: mockStub('AccountPlus'),
		Cancel: mockStub('Cancel'),
		Cog: mockStub('Cog'),
		ContentSaveOutline: mockStub('ContentSaveOutline'),
		Database: mockStub('Database'),
		Plus: mockStub('Plus'),
		Shield: mockStub('Shield'),
		AppTab: mockStub('AppTab'),
		AppTabs: mockStub('AppTabs'),
		RbacTable: mockStub('RbacTable'),
		RemoveUserDialog: mockStub('RemoveUserDialog'),
		n: translatePlural,
		t: translate,
	}
	return { ...EditOrganisation, setup: () => bindings }
}

/**
 * Mount the modal for an organisation in the store.
 *
 * @param {object|null} stored The store's organisation
 * @return {Promise<object>} The wrapper
 */
async function mountFor(stored) {
	organisationStore.organisationItem = stored
	const wrapper = mount(organisationModal())
	await flushPromises()
	return wrapper
}

/**
 * The rendered markup of the open modal.
 *
 * @param {object} wrapper The wrapper
 * @return {string} The normalised markup
 */
function domOf(wrapper) {
	const html = normalised(wrapper.html())
	expect(html).toContain('x-ncdialog')
	return html
}

beforeEach(() => {
	jest.clearAllMocks()
	Object.assign(organisationStore, {
		organisationItem: null,
		nextcloudGroups: GROUPS,
		loadNextcloudGroups: jest.fn(() => Promise.resolve()),
	})
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('EditOrganisation markup', () => {
	it('renders the empty create form', async () => {
		const html = domOf(await mountFor(null))
		expect(html).toContain('Create Organisation')
		expect(html).toMatchSnapshot()
	})

	it('renders a stored organisation', async () => {
		const html = domOf(await mountFor(STORED))
		expect(html).toContain('Edit Organisation')
		expect(html).toMatchSnapshot()
	})

	it.each([
		['success on create', null, { success: true }],
		['success on edit', STORED, { success: true }],
		['create another', null, { success: true, createAnother: true }],
		['an error', null, { error: 'Nope' }],
		['loading', STORED, { loading: true }],
		['loading groups', STORED, { loadingGroups: true }],
		['loading users', STORED, { loadingUsers: true }],
		['removing a user', STORED, { removingUser: 'member' }],
		['no members', { uuid: 'u1', name: 'Org', users: [] }, {}],
	])('renders %s', async (name, stored, state) => {
		const wrapper = await mountFor(stored)
		await wrapper.setData(state)
		expect(domOf(wrapper)).toMatchSnapshot()
	})
})

describe('EditOrganisation groups', () => {
	it('fetches the groups when none are cached and maps them afterwards', async () => {
		organisationStore.nextcloudGroups = []
		organisationStore.loadNextcloudGroups = jest.fn(() => {
			organisationStore.nextcloudGroups = GROUPS
			return Promise.resolve()
		})
		const wrapper = await mountFor(STORED)
		expect(wrapper.vm.availableGroups).toEqual(GROUPS)
		expect(wrapper.vm.loadingGroups).toBe(false)
		expect(wrapper.vm.selectedGroups).toMatchSnapshot()
	})

	it('shows an error when the groups cannot be fetched', async () => {
		organisationStore.nextcloudGroups = undefined
		organisationStore.loadNextcloudGroups = jest.fn(() =>
			Promise.reject(new Error('down')),
		)
		const wrapper = await mountFor(null)
		expect(wrapper.vm.error).toBe('Failed to load Nextcloud groups')
		expect(wrapper.vm.loadingGroups).toBe(false)
	})

	it('stores the ids of the picked groups', async () => {
		const wrapper = await mountFor(null)
		wrapper.vm.updateGroups(GROUPS)
		expect(wrapper.vm.organisationItem.groups).toEqual(['admin', 'staff'])
		wrapper.vm.updateGroups(null)
		expect(wrapper.vm.selectedGroups).toEqual([])
		expect(wrapper.vm.organisationItem.groups).toEqual([])
	})

	it('searches through the debounced group search', async () => {
		const wrapper = await mountFor(null)
		wrapper.vm.searchGroups('st')
		const [vm, search] = searchGroupsDebounced.mock.calls[0]
		expect(vm.availableGroups).toEqual(GROUPS)
		expect(search.query).toBe('st')
		expect(search.warnOnRefusal).toBe(false)
		expect(search.toGroup('x')).toEqual({ id: 'x', name: 'x', userCount: 0 })
		organisationStore.nextcloudGroups = [GROUPS[1]]
		search.onEmpty()
		expect(wrapper.vm.availableGroups).toEqual([GROUPS[1]])
	})

	it('offers all groups for special rights until groups are assigned', async () => {
		const wrapper = await mountFor(null)
		expect(wrapper.vm.filteredAvailableGroups).toEqual(GROUPS)
		wrapper.vm.organisationItem.groups = ['staff']
		expect(wrapper.vm.filteredAvailableGroups).toEqual([GROUPS[1]])
		wrapper.vm.organisationItem.groups = undefined
		expect(wrapper.vm.filteredAvailableGroups).toEqual(GROUPS)
	})
})

describe('EditOrganisation quota', () => {
	it.each([
		['updateStorageQuota', 'storage', '2', 2097152],
		['updateBandwidthQuota', 'bandwidth', '3', 3145728],
		['updateRequestQuota', 'requests', '4', 4],
		['updateUserQuota', 'users', '5', 5],
		['updateGroupQuota', 'groups', '6', 6],
		['updateStorageQuota', 'storage', '', 0],
		['updateBandwidthQuota', 'bandwidth', 0, 0],
		['updateRequestQuota', 'requests', '', 0],
		['updateUserQuota', 'users', null, 0],
		['updateGroupQuota', 'groups', undefined, 0],
	])('%s sets %s from %j', async (method, key, value, stored) => {
		const wrapper = await mountFor(null)
		wrapper.vm.organisationItem.quota[key] = 99
		wrapper.vm[method](value)
		expect(wrapper.vm.organisationItem.quota[key]).toBe(stored)
	})

	it.each([
		['updateStorageQuota'],
		['updateBandwidthQuota'],
		['updateRequestQuota'],
		['updateUserQuota'],
		['updateGroupQuota'],
	])('%s creates a missing quota', async (method) => {
		const wrapper = await mountFor(null)
		wrapper.vm.organisationItem.quota = undefined
		wrapper.vm[method]('1')
		expect(wrapper.vm.organisationItem.quota).toMatchSnapshot()
	})

	it('shows stored bytes as megabytes', async () => {
		const wrapper = await mountFor(STORED)
		expect(wrapper.vm.storageQuotaMB).toBe(3)
		expect(wrapper.vm.bandwidthQuotaMB).toBe(1)
		wrapper.vm.organisationItem.quota = undefined
		expect(wrapper.vm.storageQuotaMB).toBe(0)
		expect(wrapper.vm.bandwidthQuotaMB).toBe(0)
	})
})

describe('EditOrganisation special rights', () => {
	it('loads the rights a stored organisation grants', async () => {
		const wrapper = await mountFor(STORED)
		expect(wrapper.vm.selectedSpecialRights).toMatchSnapshot()
	})

	it('writes the ids of a right and keeps the picked groups', async () => {
		const wrapper = await mountFor(null)
		wrapper
			.findComponent({ name: 'SpecialRightsTable' })
			.vm.$emit('update', { right: 'llm_use', groups: GROUPS })
		expect(wrapper.vm.organisationItem.authorization).toEqual({
			llm_use: ['admin', 'staff'],
		})
		expect(wrapper.vm.selectedSpecialRights.llm_use).toEqual(GROUPS)
	})

	it('passes each select of the table on to its right', async () => {
		const wrapper = await mountFor(STORED)
		const selects = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.filter((select) => select.vm.$attrs.inputLabel?.includes('Special'))
		selects.forEach((select) =>
			[select.vm.$attrs['onUpdate:modelValue']]
				.flat()
				.forEach((handler) => handler(GROUPS)),
		)
		expect(wrapper.vm.organisationItem.authorization).toMatchSnapshot()
	})

	it('passes each permission table on to the entity update', async () => {
		const wrapper = await mountFor(null)
		wrapper.findAllComponents({ name: 'RbacTable' }).forEach((table) =>
			table.vm.$attrs.onUpdate({
				entityType: table.vm.$attrs.entityType,
				groupId: 'g',
				action: 'read',
				hasPermission: true,
			}),
		)
		expect(wrapper.vm.organisationItem.authorization).toMatchSnapshot()
	})
})

describe('EditOrganisation leaving and creating another', () => {
	it('resets and closes', async () => {
		const wrapper = await mountFor(STORED)
		await wrapper.setData({
			success: true,
			error: 'x',
			createAnother: true,
			activeTab: 2,
		})
		wrapper.vm.closeModal()
		expect(wrapper.vm.success).toBe(false)
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.createAnother).toBe(false)
		expect(wrapper.vm.selectedGroups).toEqual([])
		expect(wrapper.vm.activeTab).toBe(0)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('closes when the dialog closes, not when it opens', async () => {
		const wrapper = await mountFor(null)
		const dialog = wrapper.findComponent({ name: 'NcDialog' }).vm.$attrs
		dialog['onUpdate:open'](true)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		dialog['onUpdate:open'](false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('reloads the form when another organisation is opened', async () => {
		const wrapper = await mountFor(STORED)
		wrapper.vm.organisationItem = { ...wrapper.vm.organisationItem, name: 'x' }
		organisationStore.organisationItem = { uuid: 'u2', name: 'Other' }
		wrapper.vm.$options.watch['organisationStore.organisationItem'].handler.call(
			wrapper.vm,
			organisationStore.organisationItem,
			STORED,
		)
		expect(wrapper.vm.organisationItem.name).toBe('Other')
		wrapper.vm.organisationItem.name = 'kept'
		wrapper.vm.$options.watch['organisationStore.organisationItem'].handler.call(
			wrapper.vm,
			{ uuid: 'u2' },
			{ uuid: 'u2' },
		)
		expect(wrapper.vm.organisationItem.name).toBe('kept')
	})

	it('clears the form after a save with create another', async () => {
		jest.useFakeTimers()
		const wrapper = await mountFor(null)
		await wrapper.setData({
			success: true,
			activeTab: 3,
			selectedGroups: GROUPS,
		})
		wrapper.vm.organisationItem.name = 'Saved'
		wrapper.vm.prepareForAnother()
		jest.advanceTimersByTime(499)
		expect(wrapper.vm.organisationItem.name).toBe('Saved')
		jest.advanceTimersByTime(1)
		expect(wrapper.vm.organisationItem).toMatchSnapshot()
		expect(wrapper.vm.selectedGroups).toEqual([])
		expect(wrapper.vm.activeTab).toBe(0)
		expect(wrapper.vm.success).toBe(true)
		jest.advanceTimersByTime(1500)
		expect(wrapper.vm.success).toBe(null)
	})
})
