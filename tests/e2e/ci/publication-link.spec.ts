import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ACCESS BY LINK RATHER THAN BY ACCOUNT, end to end, over the HTTP API a real
 * holder uses, with a context that carries no credentials at all.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e public-access-links::a-besluitenlijst-is-published-from-the-record
 * @e2e public-access-links::knowing-the-case-number-is-not-knowing-the-link
 * @e2e public-access-links::an-adviser-may-read-and-comment-and-not-upload
 * @e2e public-access-links::a-link-without-an-end-date-is-not-minted
 * @e2e public-access-links::a-forwarded-link-still-asks
 * @e2e public-access-links::revocation-is-immediate-and-silent
 * @e2e public-access-links::an-internal-note-stays-internal
 * @e2e public-access-links::a-hidden-property-stays-hidden
 *
 * WHY THIS LAYER AND NOT PHPUNIT.
 *
 * The claim is that somebody with NO ACCOUNT reaches a record. A PHPUnit test
 * cannot make that claim: it constructs the controller itself and there is no
 * middleware between it and the method, so `#[PublicPage]` is never exercised
 * and a route that was never registered looks exactly like one that was. Only a
 * real request with no Authorization header proves both.
 *
 * The anonymous context below is deliberately built once and reused, and it
 * carries no `Authorization` header. If a test in here starts passing because
 * it borrowed the admin context, it is proving nothing.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schema and objects,
 * and removes all three. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** Seven days out, which every mint in here uses. */
function inSevenDays(): string {
	return new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString()
}

/** Build an API context authenticated as one user. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** A context with no credentials at all: the holder of a link. */
async function anonymousContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: { Accept: 'application/json' },
	})
}

test.describe.configure({ mode: 'serial' })

test.describe('access by link rather than by account', () => {
	let admin: APIRequestContext
	let anon: APIRequestContext
	let registerId: string
	let schemaId: string
	let objectUuid: string
	let viewUuid: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anon = await anonymousContext()

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e access-link register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// `bsn` is write-only, which is the schema's own way of saying "never
		// returned on a read". If a link ever serves it, the link is seeing past
		// the object's rules, which is exactly REQ-ABL-004.
		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e access-link schema ${RUN}`,
				description: 'e2e',
				properties: {
					onderwerp: {
						type: 'string',
						title: 'Onderwerp',
						maxLength: 255,
					},
					bsn: {
						type: 'string',
						title: 'BSN',
						maxLength: 32,
						writeOnly: true,
					},
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const obj = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { onderwerp: `Bezwaar ${RUN}`, bsn: '123456782' },
		})
		expect(obj.ok(), `object create failed: ${await obj.text()}`).toBeTruthy()
		const body = await obj.json()
		objectUuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(objectUuid, 'no uuid came back from the object create').toBeTruthy()

		// The besluitenlijst: a saved view over this schema, which is what
		// actieve openbaarmaking publishes rather than a retyped web page.
		const view = await admin.post(`${API}/views`, {
			data: {
				name: `e2e besluitenlijst ${RUN}`,
				description: 'e2e',
				query: { '@self': { register: registerId, schema: schemaId } },
			},
		})
		expect(view.ok(), `view create failed: ${await view.text()}`).toBeTruthy()
		const viewBody = await view.json()
		viewUuid = String(viewBody.uuid ?? viewBody.id)
		expect(viewUuid, 'no uuid came back from the view create').toBeTruthy()
	})

	test.afterAll(async () => {
		if (objectUuid) {
			await admin.delete(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
			)
			await admin.delete(`${API}/deleted/${objectUuid}`)
		}

		if (viewUuid) {
			await admin.delete(`${API}/views/${viewUuid}`)
		}

		if (schemaId) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}

		await anon.dispose()
	})

	test('a link without an end date is not minted', async () => {
		const res = await admin.post(`${API}/access-links`, {
			data: { subjectType: 'object', subjectId: objectUuid },
		})

		expect(res.status(), 'a mint with no expiry must be refused').toBe(400)
		expect(
			(await res.json()).message,
			'the refusal must name the requirement, not just fail',
		).toContain('expiry')
	})

	test('a record is opened at a link by somebody with no account', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
				label: 'Advies omgevingsdienst',
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()
		expect(link.anchor, 'the mint must return an anchor').toBeTruthy()

		const opened = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(
			opened.ok(),
			`an anonymous open failed: ${await opened.text()}`,
		).toBeTruthy()

		const body = await opened.json()
		expect(body.subject.onderwerp).toBe(`Bezwaar ${RUN}`)
		expect(body.link.capabilities).toEqual(['read'])

		// REQ-ABL-004: a write-only property is never returned on a read, and a
		// link is a read.
		expect(
			body.subject.bsn,
			'a hidden property must stay hidden through a link',
		).toBeUndefined()

		// The platform's own bookkeeping is not part of what gets published.
		for (const forbidden of [
			'owner',
			'organisation',
			'folder',
			'authorization',
		]) {
			expect(
				body.subject['@self'][forbidden],
				`a link must not publish @self.${forbidden}`,
			).toBeUndefined()
		}

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('a besluitenlijst is published from the record, as a saved view', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'view',
				subjectId: viewUuid,
				expiresAt: inSevenDays(),
				label: `Besluitenlijst ${RUN}`,
			},
		})
		expect(mint.ok(), `view mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()

		const opened = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(
			opened.ok(),
			`an anonymous view open failed: ${await opened.text()}`,
		).toBeTruthy()

		const body = await opened.json()
		expect(body.link.subjectType).toBe('view')
		expect(
			Array.isArray(body.results),
			'a view link answers a list',
		).toBeTruthy()
		expect(
			body.total,
			'the published view should carry the record',
		).toBeGreaterThan(0)

		// Every row is filtered the same way a single object is.
		for (const row of body.results as Array<Record<string, unknown>>) {
			expect(
				row.bsn,
				'a hidden property must stay hidden in a published view',
			).toBeUndefined()
			const self = (row['@self'] ?? {}) as Record<string, unknown>
			expect(
				self.owner,
				'a published view must not carry @self.owner',
			).toBeUndefined()
		}

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('knowing the case number is not knowing the link', async () => {
		// The object uuid is public knowledge to anybody who got a letter. If it
		// resolved as an anchor, the link would not be a secret at all.
		const guessed = await anon.get(
			`${API}/public/links/${objectUuid.replace(/-/g, '')}`,
		)

		expect(
			guessed.status(),
			'an anchor built from the subject must resolve to nothing',
		).toBe(404)
	})

	test('an adviser may read and comment, and not upload', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				capabilities: ['read', 'comment'],
				expiresAt: inSevenDays(),
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()
		expect(link.capabilities).toEqual(['read', 'comment'])

		const commented = await anon.post(
			`${API}/public/links/${link.anchor}/comments`,
			{
				data: { message: 'Advies: akkoord met kanttekening.' },
			},
		)
		expect(
			commented.status(),
			`an allowed comment failed: ${await commented.text()}`,
		).toBe(201)

		const refused = await anon.post(`${API}/public/links/${link.anchor}/files`, {
			data: { name: 'advies.txt', content: 'akkoord' },
		})
		expect(
			refused.status(),
			'an undeclared capability must be refused, not silently ignored',
		).toBe(403)
		expect((await refused.json()).capabilities).toEqual(['read', 'comment'])

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('a forwarded link still asks for its password', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
				password: 'Geheim-2026-link',
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()
		expect(link.hasPassword).toBe(true)

		const without = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(
			without.status(),
			'a password-protected link must not serve without the password',
		).toBe(401)
		expect(JSON.stringify(await without.json())).not.toContain(`Bezwaar ${RUN}`)

		const wrong = await anon.get(`${API}/public/links/${link.anchor}`, {
			headers: { 'X-OpenRegister-Link-Password': 'not-it' },
		})
		expect(wrong.status(), 'a wrong password must not serve either').toBe(401)

		const right = await anon.get(`${API}/public/links/${link.anchor}`, {
			headers: { 'X-OpenRegister-Link-Password': 'Geheim-2026-link' },
		})
		expect(
			right.ok(),
			`the right password should open the link: ${await right.text()}`,
		).toBeTruthy()
		expect((await right.json()).subject.onderwerp).toBe(`Bezwaar ${RUN}`)

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('revocation is immediate and silent', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()

		const before = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(before.ok(), 'the link should work before it is revoked').toBeTruthy()

		const revoked = await admin.delete(`${API}/access-links/${link.id}`)
		expect(revoked.ok(), `revoke failed: ${await revoked.text()}`).toBeTruthy()

		const after = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(
			after.status(),
			'a revoked link answers 404, never 403 and never a reduced page',
		).toBe(404)

		const body = JSON.stringify(await after.json())
		expect(body, 'the 404 must reveal nothing about the record').not.toContain(
			`Bezwaar ${RUN}`,
		)
		expect(body).not.toContain(objectUuid)
	})

	test('a switched-off link answers the same 404, and can be switched back on', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()

		const off = await admin.put(`${API}/access-links/${link.id}`, {
			data: { disabled: true },
		})
		expect(off.ok(), `switching off failed: ${await off.text()}`).toBeTruthy()

		expect((await anon.get(`${API}/public/links/${link.anchor}`)).status()).toBe(
			404,
		)

		const on = await admin.put(`${API}/access-links/${link.id}`, {
			data: { disabled: false },
		})
		expect(on.ok(), `switching on failed: ${await on.text()}`).toBeTruthy()

		expect(
			(await anon.get(`${API}/public/links/${link.anchor}`)).ok(),
		).toBeTruthy()

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('an internal note stays internal', async () => {
		const notesUrl = `${API}/objects/${registerId}/${schemaId}/${objectUuid}/notes`
		const internal = await admin.post(notesUrl, {
			data: {
				message: `Intern: let op de termijn ${RUN}`,
				visibility: 'internal',
			},
		})
		const publicNote = await admin.post(notesUrl, {
			data: {
				message: `Openbaar: ontvangstbevestiging ${RUN}`,
				visibility: 'public',
			},
		})

		// The notes API is a separate surface. When it is not reachable here the
		// test says so rather than passing on an empty timeline, which would be
		// a green that proves nothing.
		expect(
			internal.ok() && publicNote.ok(),
			`the notes API did not accept the fixture notes: ${await internal.text()}`,
		).toBeTruthy()

		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()

		const opened = await anon.get(`${API}/public/links/${link.anchor}`)
		expect(opened.ok(), `open failed: ${await opened.text()}`).toBeTruthy()

		const timeline = JSON.stringify((await opened.json()).timeline ?? [])
		expect(timeline, 'the public entry should be served').toContain(
			`Openbaar: ontvangstbevestiging ${RUN}`,
		)
		expect(
			timeline,
			'an internal entry must never be served through a link',
		).not.toContain(`Intern: let op de termijn ${RUN}`)

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('a link is listed to its minter and revocable from that listing', async () => {
		const mint = await admin.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
			},
		})
		expect(mint.ok(), `mint failed: ${await mint.text()}`).toBeTruthy()
		const link = await mint.json()

		const listed = await admin.get(`${API}/access-links`)
		expect(listed.ok(), `listing failed: ${await listed.text()}`).toBeTruthy()

		const rows = (await listed.json()).results as Array<Record<string, unknown>>
		const mine = rows.find((row) => row.id === link.id)
		expect(
			mine,
			'a capability you cannot see is a capability you cannot revoke',
		).toBeTruthy()

		await admin.delete(`${API}/access-links/${link.id}`)
	})

	test('an anonymous caller cannot mint or list links', async () => {
		const minted = await anon.post(`${API}/access-links`, {
			data: {
				subjectType: 'object',
				subjectId: objectUuid,
				expiresAt: inSevenDays(),
			},
		})
		expect(
			minted.status(),
			'minting is a write and is never anonymous',
		).toBeGreaterThanOrEqual(400)

		const listed = await anon.get(`${API}/access-links`)
		if (listed.ok()) {
			expect(
				(await listed.json()).results,
				"an anonymous listing must be empty, never everybody's links",
			).toEqual([])
		} else {
			expect(listed.status()).toBeGreaterThanOrEqual(400)
		}
	})
})
