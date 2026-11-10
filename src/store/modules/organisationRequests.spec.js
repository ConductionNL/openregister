/**
 * Request and state behaviour of the organisation store's fetch actions.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { Organisation } from '../../entities/index.js'
import { useOrganisationStore } from './organisation.js'

const BASE = '/index.php/apps/openregister/api/organisations'

/**
 * Build a fetch Response stand-in.
 *
 * @param {object} body JSON body.
 * @param {number} status HTTP status.
 * @return {object} Response-like object.
 */
function reply(body, status = 200) {
	return { ok: status >= 200 && status < 300, status, json: async () => body }
}

/**
 * Install a fetch mock answering per url; the organisation list GET always answers.
 *
 * @param {object} routes Map of "METHOD url" to a reply or an Error.
 * @return {jest.Mock} The fetch mock.
 */
function mockFetch(routes = {}) {
	const fn = jest.fn(async (url, opts = {}) => {
		const key = `${opts.method || 'GET'} ${url}`
		if (key in routes) {
			const r = routes[key]
			if (r instanceof Error) throw r
			return r
		}
		if (key === `GET ${BASE}`) {
			return reply({ total: 1, active: { uuid: 'a', name: 'A' }, results: [{ uuid: 'a', name: 'A' }] })
		}
		throw new Error(`unexpected ${key}`)
	})
	global.fetch = fn
	return fn
}

describe('organisation store requests', () => {
	let errSpy
	beforeEach(() => {
		setActivePinia(createPinia())
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		errSpy.mockRestore()
		delete global.fetch
	})

	it('getters expose view mode, active organisation and user list', () => {
		const store = useOrganisationStore()
		store.setViewMode('table')
		store.setActiveOrganisation({ uuid: 'x', name: 'X' })
		store.setUserStats({ results: [{ uuid: 'y', name: 'Y' }] })
		expect(store.getViewMode).toBe('table')
		expect(store.activeOrganisationGetter.uuid).toBe('x')
		expect(store.getUserOrganisations.map((o) => o.uuid)).toEqual(['y'])
		expect(store.userStats.total).toBe(0)
		expect(store.userStats.active).toBeNull()
	})

	it('getActiveOrganisation stores the active organisation it receives', async () => {
		const fetchMock = mockFetch({ [`GET ${BASE}/active`]: reply({ activeOrganisation: { uuid: 'act', name: 'Act' } }) })
		const store = useOrganisationStore()
		const result = await store.getActiveOrganisation()
		expect(fetchMock).toHaveBeenCalledWith(`${BASE}/active`, { method: 'GET' })
		expect(result.uuid).toBe('act')
		expect(store.activeOrganisation).toBeInstanceOf(Organisation)
		expect(store.activeOrganisation.uuid).toBe('act')
	})

	it('getActiveOrganisation leaves state alone when none is active', async () => {
		mockFetch({ [`GET ${BASE}/active`]: reply({}) })
		const store = useOrganisationStore()
		expect(await store.getActiveOrganisation()).toBeUndefined()
		expect(store.activeOrganisation).toBeNull()
	})

	it('getActiveOrganisation rethrows a network failure', async () => {
		mockFetch({ [`GET ${BASE}/active`]: new Error('down') })
		await expect(useOrganisationStore().getActiveOrganisation()).rejects.toThrow('down')
	})

	it('getOrganisation sets the item only when asked', async () => {
		mockFetch({ [`GET ${BASE}/u1`]: reply({ organisation: { uuid: 'u1', name: 'One' } }) })
		const store = useOrganisationStore()
		const plain = await store.getOrganisation('u1')
		expect(plain.name).toBe('One')
		expect(store.organisationItem).toBe(false)
		await store.getOrganisation('u1', { setItem: true })
		expect(store.organisationItem.uuid).toBe('u1')
	})

	it('getOrganisation returns the raw body without an organisation key', async () => {
		mockFetch({ [`GET ${BASE}/u2`]: reply({ error: 'nope' }) })
		expect(await useOrganisationStore().getOrganisation('u2')).toEqual({ error: 'nope' })
	})

	it('getOrganisation rethrows a failure', async () => {
		mockFetch({ [`GET ${BASE}/u3`]: new Error('boom') })
		await expect(useOrganisationStore().getOrganisation('u3')).rejects.toThrow('boom')
	})

	it('setActiveOrganisationById posts, stores the answer and refreshes the list', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/u1/set-active`]: reply({ activeOrganisation: { uuid: 'u1', name: 'One' } }) })
		const store = useOrganisationStore()
		const { data } = await store.setActiveOrganisationById('u1')
		expect(fetchMock).toHaveBeenNthCalledWith(1, `${BASE}/u1/set-active`, { method: 'POST' })
		expect(fetchMock).toHaveBeenNthCalledWith(2, BASE, { method: 'GET' })
		expect(data.activeOrganisation.uuid).toBe('u1')
		expect(store.activeOrganisation.uuid).toBe('u1')
	})

	it('setActiveOrganisationById does not store when the answer names none', async () => {
		mockFetch({ [`POST ${BASE}/u1/set-active`]: reply({}) })
		const store = useOrganisationStore()
		await store.setActiveOrganisationById('u1')
		expect(store.activeOrganisation).toBeNull()
	})

	it('setActiveOrganisationById refuses a non-ok answer', async () => {
		mockFetch({ [`POST ${BASE}/u1/set-active`]: reply({}, 403) })
		await expect(useOrganisationStore().setActiveOrganisationById('u1'))
			.rejects.toThrow('Failed to set active organisation: HTTP error! status: 403')
	})

	it('joinOrganisation sends the user id when given', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/u1/join`]: reply({ ok: 1 }) })
		const { data } = await useOrganisationStore().joinOrganisation('u1', 'alice')
		expect(fetchMock.mock.calls[0][1]).toEqual({
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ userId: 'alice' }),
		})
		expect(data).toEqual({ ok: 1 })
		expect(fetchMock).toHaveBeenCalledWith(BASE, { method: 'GET' })
	})

	it('joinOrganisation sends an empty body for the current user', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/u1/join`]: reply({}) })
		await useOrganisationStore().joinOrganisation('u1')
		expect(fetchMock.mock.calls[0][1].body).toBe('{}')
	})

	it('joinOrganisation refuses a non-ok answer', async () => {
		mockFetch({ [`POST ${BASE}/u1/join`]: reply({}, 409) })
		await expect(useOrganisationStore().joinOrganisation('u1'))
			.rejects.toThrow('Failed to join organisation: HTTP error! status: 409')
	})

	it('leaveOrganisation refreshes the list and the active organisation', async () => {
		const fetchMock = mockFetch({
			[`POST ${BASE}/u1/leave`]: reply({ left: true }),
			[`GET ${BASE}/active`]: reply({ activeOrganisation: { uuid: 'b', name: 'B' } }),
		})
		const store = useOrganisationStore()
		const { data } = await store.leaveOrganisation('u1')
		expect(data).toEqual({ left: true })
		expect(fetchMock.mock.calls.map((c) => c[0])).toEqual([`${BASE}/u1/leave`, BASE, `${BASE}/active`])
		expect(store.activeOrganisation.uuid).toBe('b')
	})

	it('leaveOrganisation refuses a non-ok answer', async () => {
		mockFetch({ [`POST ${BASE}/u1/leave`]: reply({}, 500) })
		await expect(useOrganisationStore().leaveOrganisation('u1'))
			.rejects.toThrow('Failed to leave organisation: HTTP error! status: 500')
	})

	it('deleteOrganisation refuses without a uuid', async () => {
		const fetchMock = mockFetch()
		await expect(useOrganisationStore().deleteOrganisation({ id: 4 })).rejects.toThrow('No organisation UUID to delete')
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('deleteOrganisation sends a DELETE for the uuid', async () => {
		const res = reply({})
		const fetchMock = mockFetch({ [`DELETE ${BASE}/u9`]: res })
		const { response } = await useOrganisationStore().deleteOrganisation({ uuid: 'u9' })
		expect(response).toBe(res)
		expect(fetchMock).toHaveBeenCalledWith(`${BASE}/u9`, { method: 'DELETE' })
		expect(fetchMock).toHaveBeenCalledWith(BASE, { method: 'GET' })
	})

	it('createOrganisation posts the data and stores the saved organisation', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}`]: reply({ organisation: { uuid: 'n1', name: 'New' } }) })
		const store = useOrganisationStore()
		const { data } = await store.createOrganisation({ name: 'New' })
		expect(fetchMock.mock.calls[0]).toEqual([BASE, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ name: 'New' }),
		}])
		expect(data.uuid).toBe('n1')
		expect(store.organisationItem.uuid).toBe('n1')
	})

	it('createOrganisation accepts a bare organisation body', async () => {
		mockFetch({ [`POST ${BASE}`]: reply({ uuid: 'n2', name: 'Bare' }) })
		const { data } = await useOrganisationStore().createOrganisation({ name: 'Bare' })
		expect(data.uuid).toBe('n2')
	})

	it('createOrganisation surfaces the server message, else the status', async () => {
		mockFetch({ [`POST ${BASE}`]: reply({ message: 'Slug taken' }, 400) })
		await expect(useOrganisationStore().createOrganisation({})).rejects.toThrow('Failed to create organisation: Slug taken')
		mockFetch({ [`POST ${BASE}`]: reply({}, 502) })
		await expect(useOrganisationStore().createOrganisation({})).rejects.toThrow('HTTP error! status: 502')
	})

	it('updateOrganisation refuses without an id or uuid', async () => {
		await expect(useOrganisationStore().updateOrganisation({ name: 'x' })).rejects.toThrow('Organisation UUID is required for updates')
	})

	it('updateOrganisation puts the cleaned data and patches list and item', async () => {
		const fetchMock = mockFetch({ [`PUT ${BASE}/u1`]: reply({ organisation: { uuid: 'u1', name: 'Renamed' } }) })
		const store = useOrganisationStore()
		// Keep the refreshed list from overwriting what the update patched in.
		store.refreshOrganisationList = jest.fn()
		store.setOrganisationList([{ uuid: 'u1', name: 'Old' }, { uuid: 'u2', name: 'Other' }])
		store.setOrganisationItem({ uuid: 'u1', name: 'Old' })
		const { data } = await store.updateOrganisation({ uuid: 'u1', id: 3, name: 'Renamed', slug: '', users: ['a'] })
		const [url, opts] = fetchMock.mock.calls[0]
		expect(url).toBe(`${BASE}/u1`)
		expect(opts.method).toBe('PUT')
		expect(JSON.parse(opts.body)).toEqual({ name: 'Renamed' })
		expect(data.name).toBe('Renamed')
		expect(store.organisationList[0].name).toBe('Renamed')
		expect(store.organisationList[1].name).toBe('Other')
		expect(store.organisationItem.name).toBe('Renamed')
		expect(store.refreshOrganisationList).toHaveBeenCalled()
	})

	it('updateOrganisation by id leaves an unrelated item untouched', async () => {
		mockFetch({ [`PUT ${BASE}/7`]: reply({ id: 7, name: 'Seven' }) })
		const store = useOrganisationStore()
		store.refreshOrganisationList = jest.fn()
		store.setOrganisationList([{ id: 8, name: 'Eight' }])
		store.setOrganisationItem({ uuid: 'zz', name: 'Item' })
		await store.updateOrganisation({ id: 7, name: 'Seven' })
		expect(store.organisationList[0].name).toBe('Eight')
		expect(store.organisationItem.name).toBe('Item')
	})

	it('updateOrganisation surfaces the server message', async () => {
		mockFetch({ [`PUT ${BASE}/u1`]: reply({ message: 'Forbidden' }, 403) })
		await expect(useOrganisationStore().updateOrganisation({ uuid: 'u1' })).rejects.toThrow('Failed to update organisation: Forbidden')
		mockFetch({ [`PUT ${BASE}/u1`]: reply({}, 500) })
		await expect(useOrganisationStore().updateOrganisation({ uuid: 'u1' })).rejects.toThrow('HTTP error! status: 500')
	})

	it('saveOrganisation creates a new one and updates an existing one', async () => {
		const store = useOrganisationStore()
		store.createOrganisation = jest.fn(async () => 'created')
		store.updateOrganisation = jest.fn(async () => 'updated')
		expect(await store.saveOrganisation({ name: 'n' })).toBe('created')
		expect(await store.saveOrganisation({ id: 2 })).toBe('updated')
		expect(store.createOrganisation).toHaveBeenCalledWith({ name: 'n' })
		expect(store.updateOrganisation).toHaveBeenCalledWith({ id: 2 })
	})

	it('cleanOrganisationForSave keeps a slug, coerces active and drops null slugs', () => {
		const store = useOrganisationStore()
		expect(store.cleanOrganisationForSave({ slug: 'keep', active: '' })).toEqual({ slug: 'keep', active: true })
		expect(store.cleanOrganisationForSave({ slug: null, active: 0 })).toEqual({ active: false })
		expect(store.cleanOrganisationForSave({ isDefault: true, groupCount: 2, usage: {}, owner: 'x' })).toEqual({})
	})

	it('searchOrganisations builds the query string and returns the list', async () => {
		const fetchMock = mockFetch({ [`GET ${BASE}/search?query=acme&_limit=10&_offset=5`]: reply({ organisations: [{ uuid: 'o' }] }) })
		expect(await useOrganisationStore().searchOrganisations('  acme ', 10, 5)).toEqual([{ uuid: 'o' }])
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})

	it('searchOrganisations omits an empty query and defaults to an empty list', async () => {
		mockFetch({ [`GET ${BASE}/search?_limit=50&_offset=0`]: reply({}) })
		expect(await useOrganisationStore().searchOrganisations()).toEqual([])
	})

	it('searchOrganisations refuses a non-ok answer', async () => {
		mockFetch({ [`GET ${BASE}/search?_limit=50&_offset=0`]: reply({}, 500) })
		await expect(useOrganisationStore().searchOrganisations()).rejects.toThrow('Failed to search organisations: HTTP error! status: 500')
	})

	it('clearCache posts and refreshes the list', async () => {
		const fetchMock = mockFetch({ [`POST ${BASE}/clear-cache`]: reply({ cleared: true }) })
		const { data } = await useOrganisationStore().clearCache()
		expect(data).toEqual({ cleared: true })
		expect(fetchMock.mock.calls.map((c) => c[0])).toEqual([`${BASE}/clear-cache`, BASE])
	})

	it('clearCache wraps a failure', async () => {
		mockFetch({ [`POST ${BASE}/clear-cache`]: new Error('x') })
		await expect(useOrganisationStore().clearCache()).rejects.toThrow('Failed to clear cache: x')
	})
})
