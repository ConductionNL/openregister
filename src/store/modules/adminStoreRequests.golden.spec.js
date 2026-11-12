/**
 * Golden record of the fetch-based write and read actions of the register,
 * configuration, schema, application and source stores, and of the
 * save-cleaning of every admin store: the requests each action sends, what it
 * returns or throws, the state it leaves and what it logs, for every kind of
 * answer.
 *
 * @spec exclude Golden record of API passthroughs and client-side payload cleaning.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useApplicationStore } from './application.js'
import { useConfigurationStore } from './configuration.js'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'
import { useSourceStore } from './source.js'
import { useViewsStore } from './views.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))

const ITEM = {
	id: 'x1',
	uuid: 'u1',
	title: 'Item',
	created: 'c',
	updated: 'u',
	usage: 3,
	owner: 'o',
	active: '',
}

const ANSWERS = [
	[
		'ok object',
		{
			ok: true,
			status: 200,
			body: { id: 'srv', title: 'Server', results: [{ id: 'r1' }], total: 1 },
		},
	],
	['ok null', { ok: true, status: 200, body: null }],
	['ok text', { ok: true, status: 200, body: 'text' }],
	['404 with error', { ok: false, status: 404, body: { error: 'Not here' } }],
	['500 without error', { ok: false, status: 500, body: {} }],
	['unreadable body', { ok: true, status: 200, unreadable: true }],
	['network failure', { reject: 'Network down' }],
]

const file = () => new File(['a,b'], 'data.csv', { type: 'text/csv' })

const ACTIONS = [
	[useRegisterStore, 'getRegister', () => ['x1']],
	[useRegisterStore, 'getRegisterStats', () => ['x1']],
	[useRegisterStore, 'deleteRegister', () => [{ ...ITEM }]],
	[useRegisterStore, 'saveRegister', () => [{ ...ITEM }]],
	[useRegisterStore, 'saveRegister', () => [{ title: 'New' }]],
	[useRegisterStore, 'uploadRegister', () => [{ title: 'Up' }]],
	[
		useRegisterStore,
		'importRegister',
		() => [file()],
		{ registerItem: { id: 'x1' } },
	],
	[useConfigurationStore, 'getConfiguration', () => ['x1']],
	[useConfigurationStore, 'deleteConfiguration', () => [{ ...ITEM }]],
	[useConfigurationStore, 'saveConfiguration', () => [{ ...ITEM }]],
	[useConfigurationStore, 'saveConfiguration', () => [{ title: 'New' }]],
	[useConfigurationStore, 'uploadConfiguration', () => [{ title: 'Up' }]],
	[useConfigurationStore, 'importConfiguration', () => [file(), true]],
	[useSchemaStore, 'getSchema', () => ['x1', { setItem: true }]],
	[useSchemaStore, 'getSchemaStats', () => ['x1']],
	[useSchemaStore, 'deleteSchema', () => [{ ...ITEM }]],
	[useSchemaStore, 'saveSchema', () => [{ ...ITEM }]],
	[useSchemaStore, 'saveSchema', () => [{ title: 'New' }]],
	[useSchemaStore, 'uploadSchema', () => [{ title: 'Up' }]],
	[useSchemaStore, 'getObjectCount', () => ['x1']],
	[useApplicationStore, 'refreshApplicationList', () => ['find me']],
	[useApplicationStore, 'getApplication', () => ['x1']],
	[useApplicationStore, 'deleteApplication', () => [{ ...ITEM }]],
	[useApplicationStore, 'saveApplication', () => [{ ...ITEM }]],
	[
		useApplicationStore,
		'saveApplication',
		() => [{ title: 'New', active: 'yes' }],
	],
	[useSourceStore, 'getSource', () => ['x1']],
	[useSourceStore, 'deleteSource', () => [{ ...ITEM }]],
	[useSourceStore, 'saveSource', () => [{ ...ITEM }]],
]

const CLEANERS = [
	[useRegisterStore, 'cleanRegisterForSave'],
	[useConfigurationStore, 'cleanConfigurationForSave'],
	[useSchemaStore, 'cleanSchemaForSave'],
	[useApplicationStore, 'cleanApplicationForSave'],
	[useViewsStore, 'cleanViewForSave'],
]

/**
 * A request's init as plain data.
 *
 * @param {object|undefined} init The fetch init.
 * @return {object|undefined} The init, with a FormData body listed by its keys.
 */
function plainInit(init) {
	if (!init) {
		return init
	}
	const out = { ...init }
	if (init.body instanceof FormData) {
		out.body = { formData: [...init.body.keys()] }
	}
	if (init.signal) {
		out.signal = 'AbortSignal'
	}
	return out
}

/**
 * A value as plain data, with fetch responses named instead of serialised.
 *
 * @param {*} value The value.
 * @return {*} The plain value.
 */
function plain(value) {
	return JSON.parse(
		JSON.stringify(value, (key, item) =>
			item && item.isGoldenResponse ? `<response ${item.status}>` : item,
		) ?? 'null',
	)
}

let consoleError
beforeEach(() => {
	setActivePinia(createPinia())
	jest.useFakeTimers()
	jest.setSystemTime(new Date('2026-01-01T00:00:00Z'))
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	consoleError.mockRestore()
})

describe('admin store requests golden', () => {
	for (const [useStore, action, args, seed] of ACTIONS) {
		for (const [answerName, answer] of ANSWERS) {
			it(`${useStore.$id}.${action}(${JSON.stringify(plain(args()))}): ${answerName}`, async () => {
				global.fetch = jest.fn(async () => {
					// Only the action's own request gets the answer under test; the list
					// refreshes that follow it get an empty list.
					if (global.fetch.mock.calls.length > 1) {
						return {
							isGoldenResponse: true,
							ok: true,
							status: 200,
							json: async () => ({ results: [] }),
						}
					}
					if (answer.reject) {
						throw new Error(answer.reject)
					}
					return {
						isGoldenResponse: true,
						ok: answer.ok,
						status: answer.status,
						json: async () => {
							if (answer.unreadable) {
								throw new SyntaxError('Unexpected token')
							}
							return JSON.parse(JSON.stringify(answer.body))
						},
					}
				})
				const store = useStore()
				if (seed) {
					store.$patch(seed)
				}
				const before = plain(store.$state)
				let outcome
				try {
					outcome = { returned: plain(await store[action](...args())) }
				} catch (error) {
					outcome = { thrown: error.message, cause: error.cause?.message }
				}
				await jest.runOnlyPendingTimersAsync()
				await new Promise((resolve) =>
					jest.requireActual('timers').setImmediate(resolve),
				)
				const after = plain(store.$state)
				expect({
					outcome,
					requests: global.fetch.mock.calls.map(([url, init]) => [
						url,
						plain(plainInit(init)),
					]),
					state: Object.fromEntries(
						Object.keys(after)
							.filter(
								(key) =>
									JSON.stringify(after[key])
									!== JSON.stringify(before[key]),
							)
							.map((key) => [key, after[key]]),
					),
					logged: consoleError.mock.calls.map(([text, error]) => [
						String(text),
						error?.message,
					]),
				}).toMatchSnapshot()
			})
		}
	}

	for (const [useStore, cleaner] of CLEANERS) {
		it(`${useStore.$id}.${cleaner}`, () => {
			const store = useStore()
			const item = { ...ITEM, extra: 1 }
			expect({
				full: store[cleaner](item),
				untouched: item,
				active: store[cleaner]({ active: 0 }),
				empty: store[cleaner]({}),
			}).toMatchSnapshot()
		})
	}
})
