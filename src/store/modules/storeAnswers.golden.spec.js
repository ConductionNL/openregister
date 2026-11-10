/**
 * Golden record of the register, configuration, schema and source store
 * actions that send an object and read one back: the requests, what each
 * returns or throws, the item it leaves and what it logs, for an object
 * answer, a refused answer, an answer that is not an object and an
 * unreadable body.
 *
 * @spec exclude Golden record of thin API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useConfigurationStore } from './configuration.js'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'
import { useSourceStore } from './source.js'

// The schema store imports @nextcloud/axios, which jest cannot parse
// unmocked (ESM); these tests make no axios requests.
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const ACTIONS = [
	[useRegisterStore, 'registerItem', 'deleteRegister', [{ id: 5, title: 'Five' }]],
	[useRegisterStore, 'registerItem', 'saveRegister', [{ id: 5, title: 'Five' }]],
	[useRegisterStore, 'registerItem', 'saveRegister', [{ title: 'New' }]],
	[useRegisterStore, 'registerItem', 'uploadRegister', [{ title: 'Up', id: 3 }]],
	[
		useConfigurationStore,
		'configurationItem',
		'saveConfiguration',
		[{ id: 4, title: 'Four' }],
	],
	[
		useConfigurationStore,
		'configurationItem',
		'saveConfiguration',
		[{ title: 'New' }],
	],
	[
		useConfigurationStore,
		'configurationItem',
		'uploadConfiguration',
		[{ title: 'Up' }],
	],
	[useSchemaStore, 'schemaItem', 'uploadSchema', [{ title: 'Up', id: 2 }]],
	[useSourceStore, 'sourceItem', 'deleteSource', [{ id: 6, title: 'Six' }]],
	[useSourceStore, 'sourceItem', 'saveSource', [{ id: 6, title: 'Six' }]],
	[useSourceStore, 'sourceItem', 'saveSource', [{ title: 'New' }]],
]

const ANSWERS = [
	['object', { ok: true, status: 200, body: { id: 9, title: 'Saved' } }],
	['refused', { ok: false, status: 409, body: { error: 'Conflict' } }],
	['null body', { ok: true, status: 200, body: null }],
	['text body', { ok: true, status: 200, body: 'done' }],
	['unreadable body', { ok: true, status: 200, badJson: true }],
]

/**
 * A response double.
 *
 * @param {object} answer The scripted answer.
 * @return {object} The response.
 */
function responseOf(answer) {
	return {
		ok: answer.ok,
		status: answer.status,
		json: async () => {
			if (answer.badJson) {
				throw new SyntaxError('Unexpected token')
			}
			return answer.body
		},
	}
}

describe('object answers of the list stores golden', () => {
	beforeEach(() => {
		jest.spyOn(Date.prototype, 'toISOString').mockReturnValue(
			'2026-01-01T00:00:00.000Z',
		)
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	for (const [useStore, itemKey, name, args] of ACTIONS) {
		it(`${name}(${JSON.stringify(args)})`, async () => {
			const records = []
			for (const [answerName, answer] of ANSWERS) {
				setActivePinia(createPinia())
				const store = useStore()
				store[itemKey] = { id: 1, title: 'Before' }
				let first = true
				global.fetch = jest.fn(async () => {
					if (first) {
						first = false
						return responseOf(answer)
					}
					return responseOf({
						ok: true,
						status: 200,
						body: { results: [] },
					})
				})
				const logged = []
				jest.spyOn(console, 'error').mockImplementation((text, error) =>
					logged.push([text, error?.message]),
				)
				let outcome
				try {
					const returned = await store[name](...args)
					outcome = {
						returned: JSON.parse(JSON.stringify(returned ?? null)),
					}
				} catch (error) {
					outcome = { threw: `${error.name}: ${error.message}` }
				}
				await new Promise((resolve) => setTimeout(resolve, 0))
				console.error.mockRestore()
				records.push([
					answerName,
					{
						calls: global.fetch.mock.calls.map(([url, init]) => [
							url,
							init && {
								...init,
								body:
									typeof init.body === 'string'
										? init.body
										: typeof init.body,
							},
						]),
						outcome,
						item: JSON.parse(JSON.stringify(store[itemKey] ?? null)),
						logged,
					},
				])
			}
			expect(records).toMatchSnapshot()
		})
	}
})
