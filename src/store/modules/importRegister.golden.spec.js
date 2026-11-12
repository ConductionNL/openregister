/**
 * Golden record of registerStore.importRegister: what it refuses, the
 * request it posts, what it returns or throws, the heartbeat and the
 * background refresh it runs, and what it logs, for each kind of file,
 * register, schema and answer.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useRegisterStore } from './register.js'

const schemaState = { schemaItem: null }

jest.mock('./schema.js', () => ({
	useSchemaStore: () => schemaState,
}))

const ANSWERS = {
	ok: () =>
		Promise.resolve({
			ok: true,
			status: 200,
			json: async () => ({ imported: 3 }),
		}),
	okNull: () => Promise.resolve({ ok: true, status: 200, json: async () => null }),
	okText: () =>
		Promise.resolve({ ok: true, status: 200, json: async () => 'done' }),
	refusedNamed: () =>
		Promise.resolve({
			ok: false,
			status: 422,
			json: async () => ({ error: 'Bad sheet' }),
		}),
	refusedBare: () =>
		Promise.resolve({ ok: false, status: 500, json: async () => ({}) }),
	refusedNull: () =>
		Promise.resolve({ ok: false, status: 502, json: async () => null }),
	offline: () => Promise.reject(new Error('offline')),
}

/**
 * Run one import and describe what it did.
 *
 * @param {object} c The case: file, register id, schema item, answer, refresh outcome.
 * @return {Promise<object>} The record.
 */
async function runCase(c) {
	setActivePinia(createPinia())
	const store = useRegisterStore()
	store.registerItem = c.registerId === undefined ? null : { id: c.registerId }
	schemaState.schemaItem = c.schema
	const stop = jest.fn()
	store.startImportHeartbeat = jest.fn(() => ({ stop }))
	store.refreshRegisterList = jest.fn(() =>
		c.refreshFails
			? Promise.reject(new Error('refresh failed'))
			: Promise.resolve(),
	)
	fetch.mockReset()
	fetch.mockImplementation(ANSWERS[c.answer])
	console.error.mockClear()
	const record = {}
	try {
		const result = await store.importRegister(c.file, c.callback)
		record.result = {
			keys: Object.keys(result),
			responseStatus: result.response.status,
			responseData: result.responseData,
		}
	} catch (error) {
		record.thrown = error.message
	}
	await Promise.resolve()
	await Promise.resolve()
	record.fetch = fetch.mock.calls.map(([url, init]) => ({
		url,
		method: init.method,
		body: [...init.body.entries()].map(([key, value]) => [
			key,
			typeof value === 'string' ? value : value.name,
		]),
		signal: init.signal instanceof AbortSignal,
		keys: Object.keys(init),
	}))
	record.heartbeat = store.startImportHeartbeat.mock.calls.map(([ms, cb]) => [
		ms,
		cb === c.callback,
	])
	record.stopped = stop.mock.calls.length
	record.refreshed = store.refreshRegisterList.mock.calls.length
	record.consoleError = console.error.mock.calls.map(([msg, err]) => [
		msg,
		err && err.message,
	])
	record.timers = jest.getTimerCount()
	return record
}

const CSV = new File(['a,b'], 'Data.CSV')
const JSON_FILE = new File(['{}'], 'pack.json')
const NO_EXTENSION = new File(['x'], 'README')

describe('registerStore.importRegister (golden)', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.clearAllTimers()
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	it('matches the recorded behaviour', async () => {
		const callback = () => {}
		const cases = {
			noFile: { file: null, registerId: 5, answer: 'ok' },
			noRegister: { file: CSV, registerId: undefined, answer: 'ok' },
			registerWithoutId: { file: CSV, registerId: 0, answer: 'ok' },
			csvWithSchema: {
				file: CSV,
				registerId: 5,
				schema: { id: 9 },
				answer: 'ok',
				callback,
			},
			csvWithoutSchema: {
				file: CSV,
				registerId: 5,
				schema: null,
				answer: 'ok',
			},
			jsonWithSchema: {
				file: JSON_FILE,
				registerId: 'r-1',
				schema: { id: 9 },
				answer: 'ok',
			},
			noExtension: {
				file: NO_EXTENSION,
				registerId: 5,
				schema: { id: 9 },
				answer: 'ok',
			},
			refreshFails: {
				file: JSON_FILE,
				registerId: 5,
				answer: 'ok',
				refreshFails: true,
			},
		}
		for (const answer of Object.keys(ANSWERS)) {
			cases[`csv/${answer}`] = {
				file: CSV,
				registerId: 5,
				schema: { id: 9 },
				answer,
			}
		}
		const records = {}
		for (const [name, c] of Object.entries(cases)) {
			records[name] = await runCase(c)
		}
		expect(records).toMatchSnapshot()
	})
})
