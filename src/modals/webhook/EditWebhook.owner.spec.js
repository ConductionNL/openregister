/**
 * The webhook modal for a person who owns their webhooks (webhooks-for-owners,
 * D-7): only object events, a required register and schemas, and no
 * interception or private-target toggles. An administrator keeps the full form.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import EditWebhook, { OWNED_WEBHOOK_EVENTS } from './EditWebhook.vue'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), getTransferData: jest.fn() },
	webhookStore: { saveWebhook: jest.fn() },
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../components/shared/FormCheckboxField.vue', () => ({}))
jest.mock('../../components/shared/FormSelectField.vue', () => ({}))
jest.mock('../../components/shared/SelectOption.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Tune.vue', () => ({}))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({}))

const { registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = EditWebhook

/**
 * The modal's initial state with computed getters and bound methods.
 *
 * @param {object} state Values to replace
 * @return {object} The instance
 */
function modal(state = {}) {
	const vm = EditWebhook.data.call({})
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

const catalogue = [
	{ class: 'OCA\\OpenRegister\\Event\\ObjectCreatingEvent', name: 'Object Creating', category: 'Object', type: 'before', properties: ['object'] },
	{ class: 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent', name: 'Object Created', category: 'Object', type: 'after', properties: ['object'] },
	{ class: 'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent', name: 'Object Updated', category: 'Object', type: 'after', properties: ['newObject', 'oldObject'] },
	{ class: 'OCA\\OpenRegister\\Event\\ObjectDeletedEvent', name: 'Object Deleted', category: 'Object', type: 'after', properties: ['object'] },
	{ class: 'OCA\\OpenRegister\\Event\\SchemaCreatedEvent', name: 'Schema Created', category: 'Schema', type: 'after', properties: ['schema'] },
]

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.registerList = [
		{ id: 7, title: 'Planning', schemas: [11, { id: 12 }] },
		{ id: 8, title: 'Other', schemas: [13] },
	]
	schemaStore.schemaList = [
		{ id: 11, title: 'Task' },
		{ id: 12, title: 'Milestone' },
		{ id: 13, title: 'Elsewhere' },
	]
	axios.get.mockResolvedValue({ data: { events: catalogue } })
})

describe('EditWebhook for an owner', () => {
	beforeEach(() => getCurrentUser.mockReturnValue({ uid: 'bob', isAdmin: false }))

	it('offers only the object created, updated and deleted events', async () => {
		const vm = modal()
		await vm.loadAvailableEvents()

		expect(vm.ownerView).toBe(true)
		expect(vm.eventOptions.map((option) => option.value)).toEqual(OWNED_WEBHOOK_EVENTS)
	})

	it('loads the registers and schemas to scope to', async () => {
		await modal().loadScopeOptions()

		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
	})

	it('offers the schemas of the chosen register only, and re-asks them when the register changes', () => {
		const vm = modal({ webhookItem: { name: 'Tasks', url: 'https://receiver.example' } })
		expect(vm.schemaOptions).toEqual([])

		vm.updateScopeRegister({ value: '7', label: 'Planning' })
		expect(vm.selectedRegisterOption).toEqual({ value: '7', label: 'Planning' })
		expect(vm.schemaOptions).toEqual([
			{ value: '11', label: 'Task' },
			{ value: '12', label: 'Milestone' },
		])

		vm.updateScopeSchemas([{ value: '11', label: 'Task' }, { value: '12', label: 'Milestone' }])
		expect(vm.webhookItem.filters).toEqual({ register: '7', schema: ['11', '12'] })
		expect(vm.selectedSchemaOptions.map((option) => option.label)).toEqual(['Task', 'Milestone'])

		vm.updateScopeRegister({ value: '8', label: 'Other' })
		expect(vm.webhookItem.filters).toEqual({ register: '8', schema: [] })
	})

	it('cannot be saved without an event, a register and a schema', () => {
		const vm = modal({ webhookItem: { name: 'Tasks', url: 'https://receiver.example', events: [] } })
		expect(vm.isValid).toBe(false)

		vm.webhookItem.events = [OWNED_WEBHOOK_EVENTS[0]]
		vm.updateScopeRegister({ value: '7' })
		expect(vm.isValid).toBe(false)

		vm.updateScopeSchemas([{ value: '11' }])
		expect(vm.isValid).toBe(true)
	})
})

describe('EditWebhook for an administrator', () => {
	beforeEach(() => getCurrentUser.mockReturnValue({ uid: 'admin', isAdmin: true }))

	it('keeps every event and needs only a name and URL', async () => {
		const vm = modal({ webhookItem: { name: 'All', url: 'https://receiver.example' } })
		await vm.loadAvailableEvents()
		await vm.loadScopeOptions()

		expect(vm.ownerView).toBe(false)
		expect(vm.eventOptions).toHaveLength(catalogue.length)
		expect(vm.isValid).toBe(true)
		expect(registerStore.refreshRegisterList).not.toHaveBeenCalled()
	})

	it('treats an unknown user as an administrator; the server decides', () => {
		getCurrentUser.mockReturnValue(null)

		expect(modal().ownerView).toBe(false)
	})
})
