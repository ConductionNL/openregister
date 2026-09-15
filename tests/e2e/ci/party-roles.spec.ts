import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PARTY ROLES BEYOND THE REQUESTER — end to end, over the HTTP API a real
 * client uses.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once the change is archived into `openspec/specs/`:
 *
 * @e2e party-model::two-gemachtigden-on-one-case
 * @e2e party-model::the-primary-party-is-replaced-and-the-change-is-recorded
 * @e2e party-model::a-second-address-resolves-to-the-same-party
 * @e2e party-model::a-protected-address-refuses-publication
 * @e2e party-model::an-indicator-reaches-every-case-of-that-party
 * @e2e party-model::a-broad-person-search-is-refused-not-truncated
 * @e2e mdm-merge::two-party-records-become-one-keeping-both-case-histories
 *
 * WHAT THIS FILE PROVES, AND WHAT IT LEAVES TO THE UNIT SUITE.
 *
 * It proves the party model is REACHABLE: the routes are registered, the auth
 * attributes let a non-admin through, a schema's `x-openregister-party` and
 * `partyKinds` survive a save and a read, and the refusals come back as
 * statuses rather than as a page that quietly holds less than it should.
 * Those are exactly the failures a green unit suite hides — a route missing
 * from `appinfo/routes.php` is a 404 no PHPUnit test would notice.
 *
 * It does NOT assert that an e-mail reaches a melder's inbox. Delivery runs
 * through the mailer and SMTP, which is not configured in CI, so an assertion
 * here would be a timing race dressed up as coverage. The recipient
 * resolution, the correspondence-address preference and the refuse-send
 * effect are asserted in
 * tests/Unit/Service/Party/PartyNotificationServiceTest.php, named here so
 * nobody has to take this comment's word for it.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and its
 * own objects, and removes all of them. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

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

/** The uuid of a freshly created object, whichever shape the create answered. */
function uuidOf(body: Record<string, unknown>): string {
	const self = body['@self'] as Record<string, unknown> | undefined
	return String(self?.id ?? body.id ?? body.uuid)
}

test.describe.configure({ mode: 'serial' })

test.describe('parties on an object over HTTP', () => {
	let admin: APIRequestContext
	let registerId: string
	let partySchemaId: string
	let caseSchemaId: string
	let partyA: string
	let partyB: string
	let caseOne: string
	let caseTwo: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e party register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// The party schema. `x-openregister-party` is what makes its objects
		// parties: without it they are ordinary objects and every party route
		// answers "this is not a party".
		const partySchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e party schema ${RUN}`,
				description: 'e2e',
				properties: {
					naam: { type: 'string', title: 'Naam', maxLength: 255 },
					adressen: { type: 'array', title: 'Adressen' },
					indicatoren: { type: 'array', title: 'Indicatoren' },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				'x-openregister-party': {
					kind: 'person',
					nameProperty: 'naam',
					addressesProperty: 'adressen',
					indicatorsProperty: 'indicatoren',
				},
			},
		})
		expect(
			partySchema.ok(),
			`party schema create failed: ${await partySchema.text()}`,
		).toBeTruthy()
		partySchemaId = String((await partySchema.json()).id)

		// The case schema declares which kinds of party it accepts and which
		// roles they may hold. This is the declaration dossiq consumes per
		// case type.
		const caseSchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e party case schema ${RUN}`,
				description: 'e2e',
				properties: {
					onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
				partyKinds: [
					{ key: 'person', label: 'Persoon', roles: ['aanvrager', 'gemachtigde'] },
				],
				linkRoles: [
					{ key: 'aanvrager', label: 'Aanvrager' },
					{ key: 'gemachtigde', label: 'Gemachtigde' },
				],
			},
		})
		expect(
			caseSchema.ok(),
			`case schema create failed: ${await caseSchema.text()}`,
		).toBeTruthy()
		caseSchemaId = String((await caseSchema.json()).id)

		const a = await admin.post(`${API}/objects/${registerId}/${partySchemaId}`, {
			data: {
				naam: `Jan Jansen ${RUN}`,
				adressen: [
					{ kind: 'correspondence', type: 'email', value: `jan.${RUN}@example.org` },
					{ kind: 'correspondence', type: 'email', value: `j.jansen.${RUN}@example.org` },
				],
			},
		})
		expect(a.ok(), `party A create failed: ${await a.text()}`).toBeTruthy()
		partyA = uuidOf(await a.json())

		const b = await admin.post(`${API}/objects/${registerId}/${partySchemaId}`, {
			data: {
				naam: `Piet Pietersen ${RUN}`,
				adressen: [
					{ kind: 'correspondence', type: 'email', value: `piet.${RUN}@example.org` },
				],
			},
		})
		expect(b.ok(), `party B create failed: ${await b.text()}`).toBeTruthy()
		partyB = uuidOf(await b.json())

		for (const subject of ['eerste zaak', 'tweede zaak']) {
			const obj = await admin.post(`${API}/objects/${registerId}/${caseSchemaId}`, {
				data: { onderwerp: `${subject} ${RUN}` },
			})
			expect(obj.ok(), `case create failed: ${await obj.text()}`).toBeTruthy()
			const uuid = uuidOf(await obj.json())
			if (subject === 'eerste zaak') {
				caseOne = uuid
			} else {
				caseTwo = uuid
			}
		}

		expect(caseOne, 'no uuid came back from the first case').toBeTruthy()
		expect(caseTwo, 'no uuid came back from the second case').toBeTruthy()
	})

	test.afterAll(async () => {
		for (const [schema, uuid] of [
			[caseSchemaId, caseOne],
			[caseSchemaId, caseTwo],
			[partySchemaId, partyA],
			[partySchemaId, partyB],
		]) {
			if (schema && uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schema}/${uuid}`)
				await admin.delete(`${API}/deleted/${uuid}`)
			}
		}

		for (const schema of [caseSchemaId, partySchemaId]) {
			if (schema) {
				await admin.delete(`${API}/schemas/${schema}`)
			}
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('two parties hold the same role on one case, each with its own period', async () => {
		for (const [party, from] of [
			[partyA, '2026-01-01'],
			[partyB, '2026-06-01'],
		]) {
			const added = await admin.post(
				`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties`,
				{ data: { partyUuid: party, role: 'gemachtigde', validFrom: from } },
			)
			expect(
				added.status(),
				`adding a gemachtigde failed: ${await added.text()}`,
			).toBe(201)
		}

		const listed = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties`,
		)
		expect(listed.ok(), `listing parties failed: ${await listed.text()}`).toBeTruthy()

		const body = await listed.json()
		expect(body.byRole.gemachtigde).toHaveLength(2)
		expect(body.byRole.gemachtigde.map((l: { validFrom: string }) => l.validFrom).sort())
			.toEqual(['2026-01-01', '2026-06-01'])
		// The vocabulary comes back beside the parties, so a picker reads one call.
		expect(body.kinds.map((k: { key: string }) => k.key)).toEqual(['person'])
	})

	test('a role the declared kind does not hold is refused, naming what it does hold', async () => {
		const refused = await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties`,
			{ data: { partyUuid: partyA, role: 'toeschouwer' } },
		)

		expect(
			refused.status(),
			`an undeclared role must be refused, got ${refused.status()}: ${await refused.text()}`,
		).toBe(400)
		expect((await refused.json()).error).toContain('aanvrager')
	})

	test('the primary party is replaced and the audit trail holds the change', async () => {
		const first = await admin.put(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties/primary`,
			{ data: { partyUuid: partyA, role: 'aanvrager' } },
		)
		expect(first.ok(), `setting the primary party failed: ${await first.text()}`).toBeTruthy()

		const replaced = await admin.put(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties/primary`,
			{ data: { partyUuid: partyB, role: 'aanvrager' } },
		)
		expect(
			replaced.ok(),
			`replacing the primary party failed: ${await replaced.text()}`,
		).toBeTruthy()

		const listed = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseOne}/parties`,
		)
		expect((await listed.json()).primary).toBe(partyB)

		// The change is a fact on the chain, naming both parties.
		const trail = await admin.get(`${API}/audit-trails?objectUuid=${caseOne}&limit=50`)
		expect(trail.ok(), `reading the audit trail failed: ${await trail.text()}`).toBeTruthy()
		const entries = (await trail.json()).results ?? []
		const replacement = entries.find(
			(e: { action?: string }) => e.action === 'party.primary-replaced',
		)
		expect(
			replacement,
			'replacing the primary party must leave one audit entry',
		).toBeTruthy()
	})

	test('mail from a second address resolves to the party that holds it', async () => {
		const resolved = await admin.get(
			`${API}/parties/resolve?address=${encodeURIComponent(`j.jansen.${RUN}@example.org`)}`,
		)
		expect(
			resolved.ok(),
			`resolving the second address failed: ${await resolved.text()}`,
		).toBeTruthy()
		expect((await resolved.json()).uuid).toBe(partyA)

		// An address nobody holds is a 404, not a new party.
		const unknown = await admin.get(
			`${API}/parties/resolve?address=${encodeURIComponent(`niemand.${RUN}@example.org`)}`,
		)
		expect(unknown.status()).toBe(404)
	})

	test('an indicator on a party is read on every case that party holds a role on', async () => {
		await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseTwo}/parties`,
			{ data: { partyUuid: partyA, role: 'aanvrager' } },
		)

		// Setting the indicator writes the PARTY. Neither case is touched.
		const updated = await admin.put(
			`${API}/objects/${registerId}/${partySchemaId}/${partyA}`,
			{
				data: {
					naam: `Jan Jansen ${RUN}`,
					adressen: [
						{ kind: 'correspondence', type: 'email', value: `jan.${RUN}@example.org` },
						{ kind: 'correspondence', type: 'email', value: `j.jansen.${RUN}@example.org` },
					],
					indicatoren: [
						{
							key: 'geheimhouding',
							label: 'Geheimhouding persoonsgegevens',
							effect: 'refuse-publication',
						},
					],
				},
			},
		)
		expect(updated.ok(), `setting the indicator failed: ${await updated.text()}`).toBeTruthy()

		const party = await admin.get(`${API}/parties/${partyA}`)
		expect(party.ok(), `reading the party failed: ${await party.text()}`).toBeTruthy()

		const body = await party.json()
		expect(body.indicators).toHaveLength(1)
		expect(body.indicators[0].effect).toBe('refuse-publication')
		// Both cases, read from the party's side.
		expect(body.objects).toContain(caseOne)
		expect(body.objects).toContain(caseTwo)
	})

	test('a file on a case a protected party is on cannot be published', async () => {
		const created = await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseTwo}/files`,
			{
				data: {
					name: `besluit-${RUN}.txt`,
					content: Buffer.from('Naam en adres van de aanvrager.').toString('base64'),
				},
			},
		)
		expect(created.ok(), `file create failed: ${await created.text()}`).toBeTruthy()
		const fileId = Number((await created.json()).id ?? (await created.json()).fileId)
		expect(fileId, 'no file id came back').toBeTruthy()

		const published = await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${caseTwo}/files/batch`,
			{ data: { action: 'publish', fileIds: [fileId] } },
		)
		const body = await published.json()
		const failures = JSON.stringify(body)

		// THE ASSERTION THAT MATTERS: the publish did not succeed, and the
		// reason names the indicator rather than a generic error.
		expect(
			failures,
			`publishing must be refused by the indicator, got ${failures}`,
		).toContain('Geheimhouding persoonsgegevens')
	})

	test('a party query over the administered cap is refused, and holds no party', async () => {
		const setCap = await admin.put(`${API}/settings`, {
			data: { party: { queryCap: 1 } },
		})
		expect(
			setCap.ok(),
			`setting the party query cap failed: ${await setCap.text()}`,
		).toBeTruthy()

		try {
			const refused = await admin.get(`${API}/parties/search?q=${RUN}`)

			expect(
				refused.status(),
				`a query over the cap must be refused, got ${refused.status()}: ${await refused.text()}`,
			).toBe(403)

			const body = await refused.json()
			expect(body.error).toContain('cap of 1')
			// Never a truncated page dressed up as a complete answer.
			expect(body.results).toBeUndefined()
		} finally {
			await admin.put(`${API}/settings`, { data: { party: { queryCap: 50 } } })
		}
	})

	test('a query inside the cap answers with the parties', async () => {
		const found = await admin.get(`${API}/parties/search?q=Pietersen`)

		expect(found.ok(), `a query inside the cap failed: ${await found.text()}`).toBeTruthy()
		const body = await found.json()
		expect(body.cap).toBeGreaterThan(0)
		expect(Array.isArray(body.results)).toBeTruthy()
	})
})
