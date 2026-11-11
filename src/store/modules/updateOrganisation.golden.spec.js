/**
 * Golden record of organisationStore.updateOrganisation: what it refuses,
 * the request it sends, how it updates the list and the current item, what
 * it returns or throws (with the cause), and what it logs, for organisations
 * named by uuid or id, in or out of the list, and for each kind of answer.
 */
import { createPinia, setActivePinia } from 'pinia'
import { toRaw } from 'vue'
import { useOrganisationStore } from './organisation.js'

const SAVED = { uuid: 'u-1', id: 1, name: 'Saved name', slug: 'saved' }

const ANSWERS = {
	wrapped: () =>
		Promise.resolve({
			ok: true,
			status: 200,
			json: async () => ({ organisation: SAVED }),
		}),
	bare: () =>
		Promise.resolve({
			ok: true,
			status: 200,
			json: async () => ({ ...SAVED, name: 'Bare' }),
		}),
	refusedNamed: () =>
		Promise.resolve({
			ok: false,
			status: 403,
			json: async () => ({ message: 'Not yours' }),
		}),
	refusedBare: () =>
		Promise.resolve({ ok: false, status: 500, json: async () => ({}) }),
	offline: () => Promise.reject(new Error('offline')),
}

/**
 * Run one update and describe what it did.
 *
 * @param {object} c The case: input, answer, starting list and item.
 * @return {Promise<object>} The record.
 */
async function runCase(c) {
	setActivePinia(createPinia())
	const store = useOrganisationStore()
	store.organisationList = c.list.map((org) => ({ ...org }))
	store.organisationItem = c.item ? { ...c.item } : null
	store.refreshOrganisationList = jest.fn(() => Promise.resolve())
	fetch.mockReset()
	fetch.mockImplementation(ANSWERS[c.answer])
	console.error.mockClear()
	const record = {}
	try {
		const result = await store.updateOrganisation({ ...c.input })
		record.result = {
			keys: Object.keys(result),
			status: result.response.status,
			data: result.data,
		}
	} catch (error) {
		record.thrown = error.message
		record.cause = error.cause ? error.cause.message : null
	}
	record.fetch = fetch.mock.calls.map(([url, init]) => [
		url,
		init.method,
		init.headers,
		JSON.parse(init.body),
	])
	record.list = JSON.parse(JSON.stringify(toRaw(store.organisationList)))
	record.listTypes = store.organisationList.map((org) => org.constructor.name)
	record.item = JSON.parse(JSON.stringify(store.organisationItem))
	record.refreshed = store.refreshOrganisationList.mock.calls.length
	record.consoleError = console.error.mock.calls.map(([msg, err]) => [
		msg,
		err && err.message,
	])
	return record
}

const LIST = [
	{ uuid: 'u-0', id: 0, name: 'Other' },
	{ uuid: 'u-1', id: 1, name: 'Old name' },
]

describe('organisationStore.updateOrganisation (golden)', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('matches the recorded behaviour', async () => {
		const full = {
			uuid: 'u-1',
			id: 1,
			name: 'Edited',
			users: ['a'],
			created: 'x',
			updated: 'y',
		}
		const cases = {
			noKey: {
				input: { name: 'x' },
				answer: 'wrapped',
				list: LIST,
				item: null,
			},
			byUuid: {
				input: full,
				answer: 'wrapped',
				list: LIST,
				item: { uuid: 'u-1', id: 1 },
			},
			byIdOnly: {
				input: { id: 1, name: 'Edited' },
				answer: 'wrapped',
				list: LIST,
				item: { uuid: 'u-9', id: 1 },
			},
			bareAnswer: {
				input: full,
				answer: 'bare',
				list: LIST,
				item: { uuid: 'u-2', id: 2 },
			},
			notInList: {
				input: { uuid: 'u-7', name: 'Edited' },
				answer: 'wrapped',
				list: LIST,
				item: null,
			},
			firstInList: {
				input: full,
				answer: 'wrapped',
				list: [LIST[1], LIST[0]],
				item: null,
			},
			itemByUuidOnly: {
				input: { uuid: 'u-1' },
				answer: 'wrapped',
				list: [],
				item: { uuid: 'u-1' },
			},
		}
		for (const answer of ['refusedNamed', 'refusedBare', 'offline']) {
			cases[answer] = {
				input: full,
				answer,
				list: LIST,
				item: { uuid: 'u-1', id: 1 },
			}
		}
		const records = {}
		for (const [name, c] of Object.entries(cases)) {
			records[name] = await runCase(c)
		}
		expect(records).toMatchSnapshot()
	})
})
