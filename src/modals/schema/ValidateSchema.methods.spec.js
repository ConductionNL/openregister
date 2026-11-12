/**
 * The validate schema dialog: which rows the results table shows, the
 * validation request and how its answer or failure is shown, and resetting
 * on close. Methods run against a plain `this` with stub stores; only the
 * HTTP call and the request token are faked.
 *
 * @spec exclude Client-side schema validation dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ValidateSchema from './ValidateSchema.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../components/SchemaStatsBlock.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	schemaStore: { schemaItem: { id: 42, title: 'Person' } },
}))

const { navigationStore } = jest.requireMock('../../store/store.js')

/**
 * A plain dialog instance with its own and its mixin's data, and bound methods.
 *
 * @return {object} The instance
 */
function dialog() {
	const vm = { ...ValidateSchema.mixins[0].data(), ...ValidateSchema.data() }
	for (const [name, fn] of Object.entries(ValidateSchema.methods)) vm[name] = fn.bind(vm)
	return vm
}

const filtered = (vm) => ValidateSchema.computed.filteredResults.call(vm)

const RESULTS = {
	valid_count: 2,
	invalid_count: 1,
	valid_objects: [
		{ id: 1, uuid: 'u1', name: 'A' },
		{ id: 2, uuid: 'u2', name: 'B', errors: [{ path: 'stale' }] },
	],
	invalid_objects: [
		{ id: 3, uuid: 'u3', name: 'C', errors: [{ path: '/email', message: 'bad', keyword: 'format' }] },
	],
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	global.fetch = jest.fn()
})

afterEach(() => {
	console.error.mockRestore()
	delete global.fetch
})

describe('ValidateSchema.filteredResults', () => {
	it('is empty before any validation', () => {
		expect(filtered(dialog())).toEqual([])
	})

	it('lists the valid objects first, with their errors cleared, then the invalid ones', () => {
		const vm = dialog()
		vm.validationResults = RESULTS
		expect(filtered(vm)).toEqual([
			{ id: 1, uuid: 'u1', name: 'A', errors: [] },
			{ id: 2, uuid: 'u2', name: 'B', errors: [] },
			RESULTS.invalid_objects[0],
		])
	})

	it('keeps only the objects with errors when filtering on invalid', () => {
		const vm = dialog()
		vm.validationResults = RESULTS
		vm.showOnlyInvalid = true
		expect(filtered(vm)).toEqual([RESULTS.invalid_objects[0]])
	})

	it('drops invalid entries that carry no errors when filtering on invalid', () => {
		const vm = dialog()
		vm.validationResults = { valid_objects: [], invalid_objects: [{ id: 4 }, { id: 5, errors: [] }] }
		vm.showOnlyInvalid = true
		expect(filtered(vm)).toEqual([])
	})
})

describe('ValidateSchema.startValidation', () => {
	it('posts to the validate endpoint of the active schema with the request token', async () => {
		global.fetch.mockResolvedValueOnce({ ok: true, json: async () => RESULTS })
		const vm = dialog()
		const pending = vm.startValidation()
		expect(vm.loading).toBe(true)
		await pending
		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/bulk/schema/42/validate',
			{ method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' } },
		)
		expect(vm.validationResults).toBe(RESULTS)
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it('shows the status text of a refused request', async () => {
		global.fetch.mockResolvedValueOnce({ ok: false, statusText: 'Forbidden' })
		const vm = dialog()
		await vm.startValidation()
		expect(vm.error).toBe('Validation failed: Forbidden')
		expect(vm.validationResults).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('shows the error the answer reports', async () => {
		global.fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ error: 'Schema not found' }) })
		const vm = dialog()
		await vm.startValidation()
		expect(vm.error).toBe('Schema not found')
		expect(vm.validationResults).toBeNull()
	})

	it('falls back to a generic message for an error without one', async () => {
		global.fetch.mockRejectedValueOnce(new Error(''))
		const vm = dialog()
		await vm.startValidation()
		expect(vm.error).toBe('An error occurred during validation')
		expect(vm.loading).toBe(false)
	})

	it('clears an earlier error when it starts again', async () => {
		global.fetch.mockResolvedValueOnce({ ok: true, json: async () => RESULTS })
		const vm = dialog()
		vm.error = 'earlier'
		await vm.startValidation()
		expect(vm.error).toBe(false)
	})
})

describe('ValidateSchema.closeDialog', () => {
	it('closes the dialog and resets every piece of state', () => {
		const vm = dialog()
		Object.assign(vm, {
			loading: true,
			error: 'x',
			validationResults: RESULTS,
			showOnlyInvalid: true,
			objectCount: 9,
			objectStats: { total: 9 },
		})
		vm.closeDialog()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(vm).toMatchObject({
			loading: false,
			error: false,
			validationResults: null,
			showOnlyInvalid: false,
			objectCount: 0,
			objectStats: null,
		})
	})
})

describe('ValidateSchema.logCountFailure', () => {
	it('logs the failed count', () => {
		const err = new Error('boom')
		dialog().logCountFailure(err)
		expect(console.error).toHaveBeenCalledWith('Error in loadObjectCount:', err)
	})
})
