import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useAvgStore } from './avg.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const AVG = '/index.php/apps/openregister/api/avg'
const CASES = '/index.php/apps/openregister/api/gdpr/cases'
const OBJECTS = '/index.php/apps/openregister/api/objects'
const CASE_URL = `${OBJECTS}/data-subject-requests/dataSubjectRequest`

/**
 * Build an axios-style rejection.
 *
 * @param {object} opts { message, serverError }
 * @return {object}
 */
function failure({ message, serverError } = {}) {
	// A plain object: an Error always carries a ('' prototype) message.
	const e = {}
	if (message !== undefined) e.message = message
	if (serverError) e.response = { data: { error: serverError } }
	return e
}

describe('AVG store — processing activities and DSAR actions', () => {
	let store
	let errorSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		store = useAvgStore()
	})

	afterEach(() => {
		errorSpy.mockRestore()
	})

	describe('getters', () => {
		it('exposes state through its getters and finds an activity by uuid', () => {
			store.$patch({
				activities: [{ uuid: 'a' }, { uuid: 'b', name: 'B' }],
				activeActivity: { uuid: 'a' },
				accountability: { rows: 1 },
				dsarResults: { r: 1 },
				dsarSummary: { s: 1 },
				complianceReport: { c: 1 },
				cases: [{ id: 'c1' }],
				activeCase: { id: 'c1' },
				activePolicyPack: { jurisdiction: 'nl' },
				loading: true,
				error: 'boom',
			})
			expect(store.isLoading).toBe(true)
			expect(store.getError).toBe('boom')
			expect(store.getActivities).toHaveLength(2)
			expect(store.getActiveActivity).toEqual({ uuid: 'a' })
			expect(store.getAccountability).toEqual({ rows: 1 })
			expect(store.getDsarResults).toEqual({ r: 1 })
			expect(store.getDsarSummary).toEqual({ s: 1 })
			expect(store.getComplianceReport).toEqual({ c: 1 })
			expect(store.getCases).toEqual([{ id: 'c1' }])
			expect(store.getActiveCase).toEqual({ id: 'c1' })
			expect(store.getActivePolicyPack).toEqual({ jurisdiction: 'nl' })
			expect(store.getActivityByUuid('b')).toEqual({ uuid: 'b', name: 'B' })
			expect(store.getActivityByUuid('zzz')).toBeNull()
		})

		it('clearError resets the error flag', () => {
			store.error = 'x'
			store.clearError()
			expect(store.error).toBeNull()
		})
	})

	describe('fetchActivities', () => {
		it('GETs the activity list with the filter params and stores the results', async () => {
			axios.get.mockResolvedValue({ data: { results: [{ uuid: 'a' }] } })
			const out = await store.fetchActivities({ status: 'published' })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/processing-activities`, { params: { status: 'published' } })
			expect(out).toEqual([{ uuid: 'a' }])
			expect(store.activities).toEqual([{ uuid: 'a' }])
			expect(store.loading).toBe(false)
		})

		it('stores an empty list when the response carries no results', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.fetchActivities()
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/processing-activities`, { params: {} })
			expect(store.activities).toEqual([])
		})

		it('records the error message and rethrows on failure', async () => {
			axios.get.mockRejectedValue(failure({ message: 'down' }))
			await expect(store.fetchActivities()).rejects.toEqual({ message: 'down' })
			expect(store.error).toBe('down')
			expect(store.loading).toBe(false)
		})

		it('falls back to a default message when the error has none', async () => {
			axios.get.mockRejectedValue(failure())
			await expect(store.fetchActivities()).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch processing activities')
		})
	})

	describe('fetchActivity', () => {
		it('returns null without a request when no identifier is given', async () => {
			expect(await store.fetchActivity('')).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('GETs one activity with an encoded identifier', async () => {
			axios.get.mockResolvedValue({ data: { uuid: 'a/b' } })
			const out = await store.fetchActivity('a/b')
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/processing-activities/a%2Fb`)
			expect(out).toEqual({ uuid: 'a/b' })
			expect(store.activeActivity).toEqual({ uuid: 'a/b' })
		})

		it('stores null when the response body is empty', async () => {
			axios.get.mockResolvedValue({})
			expect(await store.fetchActivity('x')).toBeNull()
		})

		it('records the error and rethrows on failure', async () => {
			axios.get.mockRejectedValue(failure())
			await expect(store.fetchActivity('x')).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch processing activity')
		})
	})

	describe('createActivity', () => {
		it('POSTs the payload and appends the created activity', async () => {
			store.activities = [{ uuid: 'old' }]
			axios.post.mockResolvedValue({ data: { uuid: 'new' } })
			const out = await store.createActivity({ name: 'N' })
			expect(axios.post).toHaveBeenCalledWith(`${AVG}/processing-activities`, { name: 'N' })
			expect(out).toEqual({ uuid: 'new' })
			expect(store.activities).toEqual([{ uuid: 'old' }, { uuid: 'new' }])
			expect(store.activeActivity).toEqual({ uuid: 'new' })
		})

		it('prefers the server error over the exception message', async () => {
			axios.post.mockRejectedValue(failure({ message: 'net', serverError: 'Admin only' }))
			await expect(store.createActivity({})).rejects.toBeDefined()
			expect(store.error).toBe('Admin only')
			expect(store.activities).toEqual([])
		})

		it('falls back to the default create message', async () => {
			axios.post.mockRejectedValue(failure())
			await expect(store.createActivity({})).rejects.toBeDefined()
			expect(store.error).toBe('Failed to create processing activity')
		})
	})

	describe('updateActivity', () => {
		it('PUTs the payload and replaces the matching list entry and active activity', async () => {
			store.activities = [{ uuid: 'a', v: 1 }, { uuid: 'b', v: 1 }]
			store.activeActivity = { uuid: 'a', v: 1 }
			axios.put.mockResolvedValue({ data: { uuid: 'a', v: 2 } })
			const out = await store.updateActivity('a', { v: 2 })
			expect(axios.put).toHaveBeenCalledWith(`${AVG}/processing-activities/a`, { v: 2 })
			expect(out).toEqual({ uuid: 'a', v: 2 })
			expect(store.activities).toEqual([{ uuid: 'a', v: 2 }, { uuid: 'b', v: 1 }])
			expect(store.activeActivity).toEqual({ uuid: 'a', v: 2 })
		})

		it('leaves a different active activity untouched', async () => {
			store.activeActivity = { uuid: 'other' }
			axios.put.mockResolvedValue({ data: { uuid: 'a', v: 2 } })
			await store.updateActivity('a', {})
			expect(store.activeActivity).toEqual({ uuid: 'other' })
		})

		it('records the server error and rethrows', async () => {
			axios.put.mockRejectedValue(failure({ serverError: 'Invalid legal basis' }))
			await expect(store.updateActivity('a', {})).rejects.toBeDefined()
			expect(store.error).toBe('Invalid legal basis')
		})

		it('falls back to the default update message', async () => {
			axios.put.mockRejectedValue(failure())
			await expect(store.updateActivity('a', {})).rejects.toBeDefined()
			expect(store.error).toBe('Failed to update processing activity')
		})
	})

	describe('archiveActivity', () => {
		it('DELETEs the activity and flips the matching rows (by id, uuid or code) to archived', async () => {
			store.activities = [
				{ id: 7, uuid: 'u1', code: 'c1', status: 'published' },
				{ id: 8, uuid: 'u2', code: 'c2', status: 'published' },
			]
			axios.delete.mockResolvedValue({})
			expect(await store.archiveActivity('c1')).toBe(true)
			expect(axios.delete).toHaveBeenCalledWith(`${AVG}/processing-activities/c1`)
			expect(store.activities.map((a) => a.status)).toEqual(['archived', 'published'])

			await store.archiveActivity(8)
			expect(store.activities[1].status).toBe('archived')
		})

		it('matches by uuid as well', async () => {
			store.activities = [{ id: 1, uuid: 'u1', code: 'c1', status: 'published' }]
			axios.delete.mockResolvedValue({})
			await store.archiveActivity('u1')
			expect(store.activities[0].status).toBe('archived')
		})

		it('keeps the list unchanged and records the error on failure', async () => {
			store.activities = [{ id: 1, uuid: 'u1', status: 'published' }]
			axios.delete.mockRejectedValue(failure({ message: 'nope' }))
			await expect(store.archiveActivity('u1')).rejects.toBeDefined()
			expect(store.error).toBe('nope')
			expect(store.activities[0].status).toBe('published')
		})

		it('falls back to the default archive message', async () => {
			axios.delete.mockRejectedValue(failure())
			await expect(store.archiveActivity('u1')).rejects.toBeDefined()
			expect(store.error).toBe('Failed to archive processing activity')
		})
	})

	describe('fetchAccountability / fetchCompliance', () => {
		it('GETs the accountability document and stores it', async () => {
			axios.get.mockResolvedValue({ data: { rows: [1] } })
			expect(await store.fetchAccountability()).toEqual({ rows: [1] })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/accountability`)
			expect(store.accountability).toEqual({ rows: [1] })
		})

		it('records the accountability error', async () => {
			axios.get.mockRejectedValue(failure())
			await expect(store.fetchAccountability()).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch accountability document')
		})

		it('GETs the compliance report and stores it', async () => {
			axios.get.mockResolvedValue({ data: { gaps: 2 } })
			expect(await store.fetchCompliance()).toEqual({ gaps: 2 })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/compliance`)
			expect(store.complianceReport).toEqual({ gaps: 2 })
		})

		it('records the compliance server error, else the default', async () => {
			axios.get.mockRejectedValueOnce(failure({ serverError: 'forbidden' }))
			await expect(store.fetchCompliance()).rejects.toBeDefined()
			expect(store.error).toBe('forbidden')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.fetchCompliance()).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch compliance report')
		})
	})

	describe('runAccess', () => {
		it('returns null without a subject', async () => {
			expect(await store.runAccess({})).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('sends only the subject when type and mode are absent', async () => {
			axios.get.mockResolvedValue({ data: { hits: 1 } })
			await store.runAccess({ subject: 's' })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/access`, { params: { subject: 's' } })
			expect(store.dsarResults).toEqual({ hits: 1 })
		})

		it('adds type and mode when given', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.runAccess({ subject: 's', type: 'bsn', mode: 'full' })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/access`, { params: { subject: 's', type: 'bsn', mode: 'full' } })
		})

		it('records server error, message, or default', async () => {
			axios.get.mockRejectedValueOnce(failure({ serverError: 'srv' }))
			await expect(store.runAccess({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('srv')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.runAccess({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('Failed to run access')
		})
	})

	describe('runErasure', () => {
		it('returns null without a subject', async () => {
			expect(await store.runErasure({})).toBeNull()
			expect(axios.post).not.toHaveBeenCalled()
		})

		it('POSTs a committed erasure without the dryRun flag', async () => {
			axios.post.mockResolvedValue({ data: { erased: 3 } })
			await store.runErasure({ subject: 's' })
			expect(axios.post).toHaveBeenCalledWith(`${AVG}/erasure`, null, { params: { subject: 's' } })
			expect(store.dsarSummary).toEqual({ erased: 3 })
		})

		it('POSTs a dry run with type and dryRun=true', async () => {
			axios.post.mockResolvedValue({ data: {} })
			await store.runErasure({ subject: 's', type: 'email', dryRun: true })
			expect(axios.post).toHaveBeenCalledWith(`${AVG}/erasure`, null, { params: { subject: 's', type: 'email', dryRun: 'true' } })
		})

		it('records the erasure error', async () => {
			axios.post.mockRejectedValueOnce(failure({ message: 'm' }))
			await expect(store.runErasure({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('m')
			axios.post.mockRejectedValueOnce(failure())
			await expect(store.runErasure({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('Failed to run erasure')
		})
	})

	describe('runPortability', () => {
		it('returns null without a subject', async () => {
			expect(await store.runPortability({})).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('GETs the portability envelope with subject and type', async () => {
			axios.get.mockResolvedValue({ data: { envelope: 1 } })
			expect(await store.runPortability({ subject: 's', type: 't' })).toEqual({ envelope: 1 })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/portability`, { params: { subject: 's', type: 't' } })
		})

		it('omits type when absent', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.runPortability({ subject: 's' })
			expect(axios.get).toHaveBeenCalledWith(`${AVG}/portability`, { params: { subject: 's' } })
		})

		it('records the portability error', async () => {
			axios.get.mockRejectedValueOnce(failure({ serverError: 'x' }))
			await expect(store.runPortability({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('x')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.runPortability({ subject: 's' })).rejects.toBeDefined()
			expect(store.error).toBe('Failed to run portability')
		})
	})

	describe('runRectification', () => {
		it('POSTs the change set and returns the response', async () => {
			axios.post.mockResolvedValue({ data: { ok: true } })
			const payload = { objectId: 'o', changes: { name: 'n' } }
			expect(await store.runRectification(payload)).toEqual({ ok: true })
			expect(axios.post).toHaveBeenCalledWith(`${AVG}/rectification`, payload)
		})

		it('records the rectification error', async () => {
			axios.post.mockRejectedValueOnce(failure({ serverError: 'locked' }))
			await expect(store.runRectification({})).rejects.toBeDefined()
			expect(store.error).toBe('locked')
			axios.post.mockRejectedValueOnce(failure())
			await expect(store.runRectification({})).rejects.toBeDefined()
			expect(store.error).toBe('Failed to run rectification')
		})
	})

	describe('case actions', () => {
		it('fetchCases merges filters over the default limit and accepts a bare array', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 1 }] })
			await store.fetchCases({ status: 'open', _limit: 5 })
			expect(axios.get).toHaveBeenCalledWith(CASE_URL, { params: { _limit: 5, status: 'open' } })
			expect(store.cases).toEqual([{ id: 1 }])
		})

		it('fetchCases stores [] when there is no body and records errors', async () => {
			axios.get.mockResolvedValueOnce({})
			await store.fetchCases()
			expect(store.cases).toEqual([])
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.fetchCases()).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch cases')
		})

		it('fetchCase returns null without an identifier', async () => {
			expect(await store.fetchCase(null)).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('fetchCase GETs one case and stores it', async () => {
			axios.get.mockResolvedValue({ data: { id: 'c 1' } })
			expect(await store.fetchCase('c 1')).toEqual({ id: 'c 1' })
			expect(axios.get).toHaveBeenCalledWith(`${CASE_URL}/c%201`)
			expect(store.activeCase).toEqual({ id: 'c 1' })
		})

		it('fetchCase stores null on an empty body and records errors', async () => {
			axios.get.mockResolvedValueOnce({})
			expect(await store.fetchCase('c')).toBeNull()
			axios.get.mockRejectedValueOnce(failure({ serverError: 'not yours' }))
			await expect(store.fetchCase('c')).rejects.toBeDefined()
			expect(store.error).toBe('not yours')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.fetchCase('c')).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch case')
		})

		it('updateCase PUTs the patch and keeps the previous case on an empty body', async () => {
			store.activeCase = { id: 'c', v: 1 }
			axios.put.mockResolvedValueOnce({})
			expect(await store.updateCase('c', { a: 1 })).toEqual({ id: 'c', v: 1 })
			expect(axios.put).toHaveBeenCalledWith(`${CASE_URL}/c`, { a: 1 })
			axios.put.mockResolvedValueOnce({ data: { id: 'c', v: 2 } })
			await store.updateCase('c', {})
			expect(store.activeCase).toEqual({ id: 'c', v: 2 })
		})

		it('updateCase records errors', async () => {
			axios.put.mockRejectedValueOnce(failure())
			await expect(store.updateCase('c', {})).rejects.toBeDefined()
			expect(store.error).toBe('Failed to update case')
		})

		it('transitionCase keeps the previous case when the server returns nothing', async () => {
			store.activeCase = { id: 'c' }
			axios.post.mockResolvedValue({})
			expect(await store.transitionCase('c', 'close')).toEqual({ id: 'c' })
			expect(axios.post).toHaveBeenCalledWith(`${CASES}/c/transition`, { action: 'close' })
		})

		it('transitionCase defaults its error message', async () => {
			axios.post.mockRejectedValue(failure())
			await expect(store.transitionCase('c', 'x')).rejects.toBeDefined()
			expect(store.error).toBe('Transition refused')
		})

		it('draftDenial without a ground skips the case patch', async () => {
			axios.post.mockResolvedValue({ data: { id: 'c' } })
			await store.draftDenial('c', '')
			expect(axios.put).not.toHaveBeenCalled()
			expect(axios.post).toHaveBeenCalledWith(`${CASES}/c/transition`, { action: 'draftDenial' })
		})

		it('finaliseDenial posts the finaliseDenial transition', async () => {
			axios.post.mockResolvedValue({ data: { id: 'c', status: 'denied' } })
			expect(await store.finaliseDenial('c')).toEqual({ id: 'c', status: 'denied' })
			expect(axios.post).toHaveBeenCalledWith(`${CASES}/c/transition`, { action: 'finaliseDenial' })
		})

		it.each([
			['collectEvidence', 'evidence', 'Evidence harvest failed'],
			['generateBundle', 'bundle', 'Bundle generation failed'],
			['verifyIdentity', 'verify-identity', 'Identity verification failed'],
			['escalateRegulator', 'escalate', 'Regulator escalation failed'],
		])('%s POSTs to /%s and reports server error, message, then default', async (action, path, fallback) => {
			axios.post.mockResolvedValueOnce({ data: { done: action } })
			expect(await store[action]('c')).toEqual({ done: action })
			expect(axios.post).toHaveBeenLastCalledWith(`${CASES}/c/${path}`)
			expect(store.loading).toBe(false)

			axios.post.mockRejectedValueOnce(failure({ message: 'm', serverError: 'srv' }))
			await expect(store[action]('c')).rejects.toBeDefined()
			expect(store.error).toBe('srv')

			axios.post.mockRejectedValueOnce(failure({ message: 'm' }))
			await expect(store[action]('c')).rejects.toBeDefined()
			expect(store.error).toBe('m')

			axios.post.mockRejectedValueOnce(failure())
			await expect(store[action]('c')).rejects.toBeDefined()
			expect(store.error).toBe(fallback)
			expect(store.loading).toBe(false)
		})

		it('applyRedaction POSTs the redaction payload', async () => {
			axios.post.mockResolvedValueOnce({ data: { redacted: 'name' } })
			const payload = { field: 'name', after: '***', ground: 'g' }
			expect(await store.applyRedaction('c', payload)).toEqual({ redacted: 'name' })
			expect(axios.post).toHaveBeenCalledWith(`${CASES}/c/redactions`, payload)
			axios.post.mockRejectedValueOnce(failure())
			await expect(store.applyRedaction('c', payload)).rejects.toBeDefined()
			expect(store.error).toBe('Redaction failed')
		})

		it('downloadBundle GETs the blob with the one-time token', async () => {
			axios.get.mockResolvedValueOnce({ data: 'BYTES' })
			expect(await store.downloadBundle('c', 'tok')).toBe('BYTES')
			expect(axios.get).toHaveBeenCalledWith(`${CASES}/c/bundle/download`, { params: { token: 'tok' }, responseType: 'blob' })
			axios.get.mockRejectedValueOnce(failure({ serverError: 'token spent' }))
			await expect(store.downloadBundle('c', 'tok')).rejects.toBeDefined()
			expect(store.error).toBe('token spent')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.downloadBundle('c', 'tok')).rejects.toBeDefined()
			expect(store.error).toBe('Download failed')
		})
	})

	describe('fetchActivePolicyPack edges', () => {
		const PACK_URL = `${OBJECTS}/dsar-policy-packs/dsarPolicyPack`

		it('uses the first pack when neither the jurisdiction nor default exists', async () => {
			axios.get.mockResolvedValue({ data: [{ jurisdiction: 'be' }, { jurisdiction: 'fr' }] })
			expect(await store.fetchActivePolicyPack({ jurisdiction: 'nl' })).toEqual({ jurisdiction: 'be' })
			expect(axios.get).toHaveBeenCalledWith(PACK_URL, { params: { _limit: 200 } })
		})

		it('stores null when there are no packs at all', async () => {
			axios.get.mockResolvedValue({})
			expect(await store.fetchActivePolicyPack()).toBeNull()
			expect(store.activePolicyPack).toBeNull()
		})

		it('records the pack error', async () => {
			axios.get.mockRejectedValueOnce(failure({ serverError: 'srv' }))
			await expect(store.fetchActivePolicyPack()).rejects.toBeDefined()
			expect(store.error).toBe('srv')
			axios.get.mockRejectedValueOnce(failure())
			await expect(store.fetchActivePolicyPack()).rejects.toBeDefined()
			expect(store.error).toBe('Failed to fetch policy pack')
		})
	})
})
