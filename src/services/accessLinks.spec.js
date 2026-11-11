import axios from '@nextcloud/axios'
import {
	CAPABILITIES,
	linksForSubject,
	linkState,
	listAccessLinks,
	mintAccessLink,
	mintPayload,
	PASSWORD_HEADER,
	passwordOptions,
	printable,
	readableFields,
	revokeAccessLink,
	setAccessLinkDisabled,
} from './accessLinks.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

describe('access links service (#4061)', () => {
	afterEach(() => jest.clearAllMocks())

	it('names the state the owner sees', () => {
		const now = new Date('2026-09-27T12:00:00Z')
		expect(linkState({ expiresAt: '2026-10-01T00:00:00Z' }, now)).toBe('active')
		expect(linkState({ expiresAt: '2026-09-01T00:00:00Z' }, now)).toBe('expired')
		expect(
			linkState({ disabled: true, expiresAt: '2026-10-01T00:00:00Z' }, now),
		).toBe('off')
		expect(
			linkState({ revokedAt: '2026-09-20T00:00:00Z', disabled: true }, now),
		).toBe('revoked')
	})

	it('lists only the live-or-closable links over this one object', () => {
		const links = [
			{ id: 1, subjectType: 'object', subjectId: 'a', created: '2026-09-01' },
			{ id: 2, subjectType: 'object', subjectId: 'b', created: '2026-09-02' },
			{ id: 3, subjectType: 'view', subjectId: 'a', created: '2026-09-03' },
			{ id: 4, subjectType: 'object', subjectId: 'a', created: '2026-09-04' },
			{
				id: 5,
				subjectType: 'object',
				subjectId: 'a',
				created: '2026-09-05',
				revokedAt: '2026-09-06',
			},
		]
		expect(linksForSubject(links, 'object', 'a').map((link) => link.id)).toEqual(
			[4, 1],
		)
	})

	it('builds the mint body the API reads, expiring at the end of the picked day', () => {
		const body = mintPayload({
			subjectType: 'object',
			subjectId: 'uuid-1',
			capabilities: ['upload', 'read', 'bogus'],
			expiresOn: '2026-12-31',
			password: 'geheim',
			label: '',
		})
		expect(body.subjectType).toBe('object')
		expect(body.subjectId).toBe('uuid-1')
		expect(body.capabilities).toEqual(['read', 'upload'])
		expect(body.password).toBe('geheim')
		expect(body).not.toHaveProperty('label')
		const expiry = new Date(body.expiresAt)
		expect(expiry.getFullYear()).toBe(2026)
		expect(expiry.getMonth()).toBe(11)
		expect(expiry.getDate()).toBe(31)
		expect(expiry.getHours()).toBe(23)
	})

	it('leaves the password out when none is given', () => {
		const body = mintPayload({
			subjectType: 'object',
			subjectId: 'x',
			capabilities: CAPABILITIES,
			expiresOn: '2026-12-31',
		})
		expect(body).not.toHaveProperty('password')
	})

	it('calls the owner endpoints', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 7 }] } })
		axios.post.mockResolvedValue({ data: { id: 8, url: 'u', pageUrl: 'p' } })
		axios.put.mockResolvedValue({ data: { id: 8, disabled: true } })
		axios.delete.mockResolvedValue({ data: [] })

		expect(await listAccessLinks()).toEqual([{ id: 7 }])
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/access-links')

		expect((await mintAccessLink({ a: 1 })).pageUrl).toBe('p')
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/access-links',
			{ a: 1 },
		)

		await setAccessLinkDisabled(8, true)
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/openregister/api/access-links/8',
			{ disabled: true },
		)

		await revokeAccessLink(8)
		expect(axios.delete).toHaveBeenCalledWith(
			'/apps/openregister/api/access-links/8',
		)
	})

	it('sends the holder password in the header, never the URL', () => {
		expect(passwordOptions('')).toEqual({})
		expect(passwordOptions('pw')).toEqual({
			headers: { [PASSWORD_HEADER]: 'pw' },
		})
	})

	it('shows a record as its own fields, not its metadata', () => {
		expect(
			readableFields({
				'@self': { id: 'x' },
				id: 'x',
				naam: 'Jan',
				leeftijd: 42,
				adres: { straat: 'A' },
			}),
		).toEqual([
			{ key: 'naam', value: 'Jan' },
			{ key: 'leeftijd', value: '42' },
			{ key: 'adres', value: 'straat: A' },
		])
		expect(readableFields(null)).toEqual([])
	})

	it('never prints JSON for a nested value', () => {
		const fields = readableFields({
			tags: ['a', 'b'],
			contact: { naam: 'Jan', '@self': { x: 1 }, telefoon: ['1', '2'] },
			actief: true,
		})
		expect(fields).toEqual([
			{ key: 'tags', value: 'a, b' },
			{ key: 'contact', value: 'naam: Jan; telefoon: 1, 2' },
			{ key: 'actief', value: '✓' },
		])
		fields.forEach((field) => {
			expect(field.value).not.toMatch(/[{}[\]"]/)
		})
	})

	it('prints empty values as nothing', () => {
		expect(printable(null)).toBe('')
		expect(printable(undefined)).toBe('')
		expect(printable([null, 'x'])).toBe('x')
	})
})
