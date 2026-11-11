/**
 * ensureRegisterAndSchemaLists fetches each list only when it is empty, in
 * order, and passes a failure on.
 *
 * @spec exclude Unit test of client-side list loading.
 */
import { registerStore, schemaStore } from '../store/store.js'
import { ensureRegisterAndSchemaLists } from './registerSchemaLists.js'

jest.mock('../store/store.js', () => ({
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
}))

describe('ensureRegisterAndSchemaLists', () => {
	const calls = []
	beforeEach(() => {
		calls.length = 0
		registerStore.refreshRegisterList.mockReset().mockImplementation(async () => calls.push('registers'))
		schemaStore.refreshSchemaList.mockReset().mockImplementation(async () => calls.push('schemas'))
	})

	it.each([
		[[], [], ['registers', 'schemas']],
		[[1], [], ['schemas']],
		[[], [1], ['registers']],
		[[1], [1], []],
	])('registers %j, schemas %j fetches %j', async (registers, schemas, expected) => {
		registerStore.registerList = registers
		schemaStore.schemaList = schemas
		await ensureRegisterAndSchemaLists()
		expect(calls).toEqual(expected)
	})

	it('stops at a failed register fetch', async () => {
		registerStore.registerList = []
		schemaStore.schemaList = []
		registerStore.refreshRegisterList.mockRejectedValue(new Error('no'))
		await expect(ensureRegisterAndSchemaLists()).rejects.toThrow('no')
		expect(schemaStore.refreshSchemaList).not.toHaveBeenCalled()
	})
})
