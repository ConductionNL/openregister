/**
 * The bulk delete and validate requests of the schema object modals: what
 * they send, what they keep, and every way they fail.
 *
 * @spec exclude Client-side modal request plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchemaObjects from './DeleteSchemaObjects.vue'
import ValidateSchema from './ValidateSchema.vue'
import { registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DeleteSweep.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('../../components/SchemaStatsBlock.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaItem: { id: 5 }, getSchemaStats: jest.fn() },
}))

/**
 * A bare instance of a modal with its methods and initial state.
 *
 * @param {object} component The modal
 * @return {object} The instance
 */
function modal(component) {
	const vm = { ...component.data() }
	for (const [name, method] of Object.entries(component.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

/**
 * A fetch double answering once.
 *
 * @param {object} response The response
 * @return {Function} The double
 */
function answer(response) {
	return jest.fn(() => Promise.resolve(response))
}

const OK = (body) => ({ ok: true, json: () => Promise.resolve(body) })
const FAILED = { ok: false, statusText: 'Bad Gateway' }

let log

beforeEach(() => {
	jest.clearAllMocks()
	log = []
	jest.spyOn(console, 'error').mockImplementation((...args) => log.push(args))
	registerStore.registerList = [
		{ id: 1, schemas: [{ id: 4 }] },
		{ id: 2, schemas: [{ id: 5 }] },
	]
	registerStore.refreshRegisterList.mockImplementation(() =>
		Promise.resolve(),
	)
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('DeleteSchemaObjects.confirmDeletion', () => {
	it('deletes through the register holding the schema, then refreshes its stats', async () => {
		global.fetch = answer(OK({ deleted: 3 }))
		const vm = modal(DeleteSchemaObjects)
		vm.hardDelete = ['hardDelete']
		await vm.confirmDeletion()
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(global.fetch.mock.calls).toEqual([
			[
				'/index.php/apps/openregister/api/bulk/2/5/delete-schema',
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: '{"hardDelete":true}',
				},
			],
		])
		expect(vm.deletionResult).toEqual({ deleted: 3 })
		expect(vm.success).toBe(true)
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
		expect(schemaStore.getSchemaStats.mock.calls).toEqual([[5]])
	})

	it('sends a soft delete unless hard delete is ticked', async () => {
		global.fetch = answer(OK({}))
		await modal(DeleteSchemaObjects).confirmDeletion()
		expect(global.fetch.mock.calls[0][1].body).toBe('{"hardDelete":false}')
	})

	it.each([
		['no register holds the schema', null, 'Could not find register containing this schema'],
		['the request fails', FAILED, 'Deletion failed: Bad Gateway'],
		['the endpoint reports an error', OK({ error: 'locked' }), 'locked'],
		['an empty error is not a failure', OK({ error: '' }), null],
	])('reports when %s', async (_case, response, message) => {
		if (response === null) {
			registerStore.registerList = [{ id: 1, schemas: [{ id: 4 }] }]
		}
		global.fetch = answer(response)
		const vm = modal(DeleteSchemaObjects)
		await vm.confirmDeletion()
		if (message === null) {
			expect(vm.success).toBe(true)
			return
		}
		expect(vm.error).toBe(message)
		expect(vm.success).toBe(false)
		expect(vm.deletionResult).toBeNull()
		expect(vm.loading).toBe(false)
		expect(log).toEqual([['Deletion error:', new Error(message)]])
		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
	})

	it('falls back to its own message', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('')))
		const vm = modal(DeleteSchemaObjects)
		await vm.confirmDeletion()
		expect(vm.error).toBe('An error occurred during deletion')
	})
})

describe('ValidateSchema.startValidation', () => {
	it('validates the schema and keeps the results', async () => {
		global.fetch = answer(OK({ valid_objects: [] }))
		const vm = modal(ValidateSchema)
		await vm.startValidation()
		expect(global.fetch.mock.calls).toEqual([
			[
				'/index.php/apps/openregister/api/bulk/schema/5/validate',
				{ method: 'POST', headers: { 'Content-Type': 'application/json' } },
			],
		])
		expect(Object.keys(global.fetch.mock.calls[0][1])).toEqual([
			'method',
			'headers',
		])
		expect(vm.validationResults).toEqual({ valid_objects: [] })
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it.each([
		['the request fails', FAILED, 'Validation failed: Bad Gateway'],
		['the endpoint reports an error', OK({ error: 'locked' }), 'locked'],
	])('reports when %s', async (_case, response, message) => {
		global.fetch = answer(response)
		const vm = modal(ValidateSchema)
		await vm.startValidation()
		expect(vm.error).toBe(message)
		expect(vm.validationResults).toBeNull()
		expect(vm.loading).toBe(false)
		expect(log).toEqual([['Validation error:', new Error(message)]])
	})

	it('falls back to its own message', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('')))
		const vm = modal(ValidateSchema)
		await vm.startValidation()
		expect(vm.error).toBe('An error occurred during validation')
	})
})
