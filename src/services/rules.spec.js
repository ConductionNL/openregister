import axios from '@nextcloud/axios'
import {
	evaluateRule,
	fetchInventory,
	fetchOperators,
	fetchRuns,
	fetchVocabulary,
	messageFor,
	replayRule,
	setRuleEnabled,
} from './rules.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		patch: jest.fn(),
	},
}))

// Mirror generateUrl's {param} substitution so the exact url is asserted.
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, k) => encodeURIComponent(params[k]))),
}))

const API = '/index.php/apps/openregister/api'

describe('rules service', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('fetchVocabulary GETs the rule vocabulary and returns the body', async () => {
		axios.get.mockResolvedValue({ data: { triggers: ['save'] } })
		await expect(fetchVocabulary()).resolves.toEqual({ triggers: ['save'] })
		expect(axios.get).toHaveBeenCalledWith(`${API}/rules/vocabulary`)
	})

	it('fetchInventory sends idleDays only when given', async () => {
		axios.get.mockResolvedValue({ data: { rules: [] } })
		await expect(fetchInventory('person', 30)).resolves.toEqual({ rules: [] })
		expect(axios.get).toHaveBeenLastCalledWith(`${API}/schemas/person/rules`, { params: { idleDays: 30 } })

		await fetchInventory('person')
		expect(axios.get).toHaveBeenLastCalledWith(`${API}/schemas/person/rules`, { params: {} })
	})

	it('setRuleEnabled PATCHes the enabled flag on the rule', async () => {
		axios.patch.mockResolvedValue({ data: { id: 'r1', enabled: false } })
		await expect(setRuleEnabled('s', 'r1', false)).resolves.toEqual({ id: 'r1', enabled: false })
		expect(axios.patch).toHaveBeenCalledWith(`${API}/schemas/s/rules/r1`, { enabled: false })
	})

	it('evaluateRule POSTs the payload to the evaluate endpoint', async () => {
		axios.post.mockResolvedValue({ data: { matched: true } })
		await expect(evaluateRule('s', 'r1', { object: { a: 1 } })).resolves.toEqual({ matched: true })
		expect(axios.post).toHaveBeenCalledWith(`${API}/schemas/s/rules/r1/evaluate`, { object: { a: 1 } })
	})

	it('replayRule POSTs the payload to the replay endpoint', async () => {
		axios.post.mockResolvedValue({ data: { replayed: 4 } })
		await expect(replayRule('s', 'r 2', { dryRun: true })).resolves.toEqual({ replayed: 4 })
		expect(axios.post).toHaveBeenCalledWith(`${API}/schemas/s/rules/r%202/replay`, { dryRun: true })
	})

	it('fetchRuns GETs the run history with the filters as params', async () => {
		axios.get.mockResolvedValue({ data: { results: [1] } })
		await expect(fetchRuns('r1', { status: 'failed' })).resolves.toEqual({ results: [1] })
		expect(axios.get).toHaveBeenLastCalledWith(`${API}/rules/r1/runs`, { params: { status: 'failed' } })
		await fetchRuns('r1')
		expect(axios.get).toHaveBeenLastCalledWith(`${API}/rules/r1/runs`, { params: {} })
	})

	it('fetchOperators returns the operators list, or [] when absent', async () => {
		axios.get.mockResolvedValueOnce({ data: { operators: ['sum'] } })
		await expect(fetchOperators()).resolves.toEqual(['sum'])
		expect(axios.get).toHaveBeenCalledWith(`${API}/schemas/calculation-operators`)
		axios.get.mockResolvedValueOnce({ data: {} })
		await expect(fetchOperators()).resolves.toEqual([])
		axios.get.mockResolvedValueOnce({})
		await expect(fetchOperators()).resolves.toEqual([])
	})

	describe('messageFor', () => {
		it('prefers the structured server error message', () => {
			expect(messageFor({ response: { data: { error: { message: 'structured' } } }, message: 'm' }, 'f')).toBe('structured')
		})

		it('falls back to a string server error', () => {
			expect(messageFor({ response: { data: { error: 'plain' } }, message: 'm' }, 'f')).toBe('plain')
		})

		it('falls back to the exception message', () => {
			expect(messageFor({ message: 'm' }, 'f')).toBe('m')
		})

		it('falls back to the given fallback, including for a missing error', () => {
			expect(messageFor({}, 'f')).toBe('f')
			expect(messageFor(undefined, 'f')).toBe('f')
		})
	})
})
