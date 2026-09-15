import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE OBJECT AS THE HINGE BETWEEN CASES, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/objects-as-the-hinge-between-cases/specs/`
 * is archived into `openspec/specs/`:
 *
 * @e2e linked-entity-types::an-address-has-a-history
 * @e2e linked-entity-types::the-reverse-view-obeys-access
 * @e2e linked-entity-types::the-besluits-date-on-the-bezwaar-without-a-copy
 * @e2e linked-entity-types::a-lens-cannot-be-written
 * @e2e linked-entity-types::withheld-is-not-the-same-as-empty
 * @e2e linked-entity-types::an-object-type-is-as-usable-as-a-case-list
 * @e2e linked-entity-types::a-second-mailbox-is-configuration-not-code
 * @e2e linked-entity-types::a-source-is-switched-off-without-being-deleted
 * @e2e linked-entity-types::the-case-shows-the-address-it-is-about
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is reachable over HTTP: an address answers with
 * the three records that point at it, grouped by schema and each carrying its
 * status; a caller who may read one of the three gets one of the three and a
 * total of one; a lens renders the besluit's date on the bezwaar and moves
 * when the besluit moves, with nothing written to the bezwaar; a write naming
 * the lens is refused and the refusal names it; a lens over a besluit the
 * caller may not read comes back withheld rather than empty; a schema's
 * declared columns and search fields come back from its list-presentation
 * endpoint; two intake sources are listed and one switched off stays listed;
 * and a case's features carry the address's point with the relation it came
 * through.
 *
 * It does NOT assert that a disabled source stops being POLLED, because
 * nothing polls over HTTP: that half of the scenario is asserted in
 * tests/Unit/Service/Hinge/IntakeSourceRegistryTest.php
 * (testASourceIsSwitchedOffWithoutBeingDeleted), named here so nobody has to
 * take this comment's word for it. The geographic precedence rule — an own
 * feature superseding an inherited one — is marked `@e2e exclude` in the spec
 * delta and asserted in
 * tests/Unit/Service/Hinge/InheritedGeoCollectorTest.php
 * (testACorrectedLocationWins).
 *
 * HERMETIC BY CONSTRUCTION, except for the intake-sources register, which the
 * SeedIntakeSourceRegister repair step materialises on install and upgrade.
 * That test asserts the register is there before leaning on it, so a missing
 * seed reports "the repair step did not run" instead of a confusing 404.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

/*
 * The same fixed uids the sharing and watcher specs use, provisioned by the
 * workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh).
 */
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

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

/** Assert a seeded account is usable before any test leans on it. */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}), did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('an object is the hinge several cases turn on', () => {
	let admin: APIRequestContext
	let other: APIRequestContext
	let registerId: string

	const schemas: Record<string, string> = {}

	/** Every object this spec creates, as [schemaId, uuid]. */
	const created: Array<[string, string]> = []

	async function createSchema(
		key: string,
		title: string,
		properties: Record<string, unknown>,
		options: { read?: string[], configuration?: Record<string, unknown> } = {},
	): Promise<string> {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `${title} ${RUN}`,
				description: 'e2e',
				properties,
				configuration: options.configuration ?? {},
				authorization: {
					read: options.read ?? ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

		const id = String((await res.json()).id)
		schemas[key] = id

		return id
	}

	async function createObject(
		schemaId: string,
		data: Record<string, unknown>,
	): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data })
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push([schemaId, uuid])

		return uuid
	}

	async function readObject(
		ctx: APIRequestContext,
		schemaId: string,
		uuid: string,
	): Promise<Record<string, unknown>> {
		const res = await ctx.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		expect(res.ok(), `object read failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()) as Record<string, unknown>
	}

	async function referencedBy(
		ctx: APIRequestContext,
		schemaId: string,
		uuid: string,
	): Promise<Record<string, unknown>> {
		const res = await ctx.get(
			`${API}/objects/${registerId}/${schemaId}/${uuid}/referenced-by`,
		)
		expect(res.ok(), `reverse view failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()) as Record<string, unknown>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(other, OTHER)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e hinge register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		await createSchema('adres', 'e2e hinge adres', {
			straat: { type: 'string', title: 'Straat', maxLength: 255 },
		})
		await createSchema(
			'melding',
			'e2e hinge melding',
			{
				onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				status: { type: 'string', title: 'Status', maxLength: 64 },
				adres: { type: 'string', title: 'Adres', format: 'uuid', maxLength: 64 },
			},
			{ configuration: { 'x-openregister-lifecycle': { field: 'status', initial: 'open' } } },
		)
		await createSchema('inspectie', 'e2e hinge inspectie', {
			onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
			adres: { type: 'string', title: 'Adres', format: 'uuid', maxLength: 64 },
		})
		// Only an administrator may read a vergunning, which is what makes the
		// access test a real refusal rather than an empty register.
		await createSchema(
			'vergunning',
			'e2e hinge vergunning',
			{
				onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				adres: { type: 'string', title: 'Adres', format: 'uuid', maxLength: 64 },
			},
			{ read: ['admin'] },
		)
	})

	test.afterAll(async () => {
		for (const [schemaId, uuid] of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		for (const schemaId of Object.values(schemas)) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('an address has a history, and the reverse view obeys access', async () => {
		const adres = await createObject(schemas.adres, { straat: `Dorpsstraat ${RUN}` })

		await createObject(schemas.melding, {
			onderwerp: 'Melding over de stoep',
			status: 'open',
			adres,
		})
		await createObject(schemas.inspectie, { onderwerp: 'Inspectie ter plaatse', adres })
		await createObject(schemas.vergunning, { onderwerp: 'Aanvraag dakkapel', adres })

		const asAdmin = await referencedBy(admin, schemas.adres, adres)
		const groups = asAdmin.groups as Array<Record<string, unknown>>

		// Three records, grouped by schema, not one flat list of uuids.
		expect(asAdmin.total, JSON.stringify(asAdmin)).toBe(3)
		expect(groups.length).toBe(3)

		const bySchema: Record<string, Record<string, unknown>> = {}
		for (const group of groups) {
			bySchema[String((group.schema as Record<string, unknown>).id)] = group
		}

		expect(Object.keys(bySchema).sort()).toEqual(
			[schemas.melding, schemas.inspectie, schemas.vergunning].sort(),
		)

		// The melding's schema declares a lifecycle field, so its summary says
		// where the record stands rather than only what it is called.
		const melding = bySchema[schemas.melding]
		const record = (melding.results as Array<Record<string, unknown>>)[0]
		expect(String(record.status)).toBe('open')
		expect(String(record.updated)).not.toBe('')

		// The same read by a caller who may not see a vergunning returns two,
		// and a total of two: the filter is inside the query, so the count does
		// not advertise what the list withholds.
		const asOther = await referencedBy(other, schemas.adres, adres)
		const otherSchemas = (asOther.groups as Array<Record<string, unknown>>).map(
			(group) => String((group.schema as Record<string, unknown>).id),
		)

		expect(otherSchemas).not.toContain(schemas.vergunning)
		expect(asOther.total).toBe(2)
	})

	test('the besluit\'s date shows on the bezwaar without a copy, and cannot be written', async () => {
		// The besluit is admin-only on purpose: the same pair proves the lens
		// resolves for a reader who may look through it and says withheld for
		// one who may not.
		const besluitSchema = await createSchema(
			'besluit',
			'e2e hinge besluit',
			{
				datum: { type: 'string', title: 'Datum', maxLength: 32 },
				geheim: { type: 'string', title: 'Geheim', maxLength: 255 },
			},
			{ read: ['admin'] },
		)
		const bezwaarSchema = await createSchema(
			'bezwaar',
			'e2e hinge bezwaar',
			{
				onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				besluit: { type: 'string', title: 'Besluit', format: 'uuid', maxLength: 64 },
			},
			{
				configuration: {
					'x-openregister-lenses': {
						besluitDatum: { through: 'besluit', property: 'datum', label: 'Datum besluit' },
					},
				},
			},
		)

		const besluit = await createObject(besluitSchema, {
			datum: '2026-09-01',
			geheim: `niet tonen ${RUN}`,
		})
		const bezwaar = await createObject(bezwaarSchema, {
			onderwerp: 'Bezwaar tegen het besluit',
			besluit,
		})

		const before = await readObject(admin, bezwaarSchema, bezwaar)
		expect(before.besluitDatum).toBe('2026-09-01')

		// Move the besluit. The bezwaar is not written, and reads differently.
		const patched = await admin.put(`${API}/objects/${registerId}/${besluitSchema}/${besluit}`, {
			data: { datum: '2026-09-08', geheim: `niet tonen ${RUN}` },
		})
		expect(patched.ok(), `besluit update failed: ${await patched.text()}`).toBeTruthy()

		const after = await readObject(admin, bezwaarSchema, bezwaar)
		expect(after.besluitDatum).toBe('2026-09-08')
		expect(
			(after['@self'] as Record<string, unknown>).updated,
			'reading the lens must not have written the bezwaar',
		).toBe((before['@self'] as Record<string, unknown>).updated)

		// A write naming the lens is refused, and the refusal names it.
		const refused = await admin.put(`${API}/objects/${registerId}/${bezwaarSchema}/${bezwaar}`, {
			data: { onderwerp: 'Bezwaar tegen het besluit', besluit, besluitDatum: '2020-01-01' },
		})
		expect(refused.status(), await refused.text()).toBeGreaterThanOrEqual(400)
		expect(await refused.text()).toContain('besluitDatum')

		// A caller who may not read the besluit gets withheld, not empty. The
		// difference is the whole point: empty reads as "there is no besluit".
		const asOther = await readObject(other, bezwaarSchema, bezwaar)
		expect(asOther.besluitDatum).toEqual(
			expect.objectContaining({ '@withheld': true }),
		)
		expect(JSON.stringify(asOther)).not.toContain('2026-09-08')
		expect(JSON.stringify(asOther)).not.toContain('niet tonen')
	})

	test('an object type is as usable as a case list', async () => {
		const schemaId = await createSchema(
			'zaaktype',
			'e2e hinge zaaktype',
			{
				onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				status: { type: 'string', title: 'Status', maxLength: 64 },
				aanvrager: { type: 'string', title: 'Aanvrager', maxLength: 255 },
				datum: { type: 'string', title: 'Datum', maxLength: 32 },
			},
			{
				configuration: {
					'x-openregister-list': {
						columns: ['onderwerp', { property: 'status', label: 'Stand van zaken' }, 'aanvrager', 'datum'],
						searchFields: ['onderwerp', 'aanvrager'],
					},
				},
			},
		)

		const res = await admin.get(`${API}/schemas/${schemaId}/list-presentation`)
		expect(res.ok(), `list presentation failed: ${await res.text()}`).toBeTruthy()

		const body = (await res.json()) as Record<string, unknown>
		expect(body.declared).toBe(true)
		expect((body.columns as Array<Record<string, unknown>>).map((c) => String(c.property))).toEqual([
			'onderwerp',
			'status',
			'aanvrager',
			'datum',
		])
		expect((body.columns as Array<Record<string, unknown>>)[1].label).toBe('Stand van zaken')
		expect(body.searchFields).toEqual(['onderwerp', 'aanvrager'])

		// A schema declaring none of it keeps today's columns and says so, so a
		// surface with its own defaults can tell the two apart.
		const plain = await admin.get(`${API}/schemas/${schemas.adres}/list-presentation`)
		expect(plain.ok()).toBeTruthy()
		expect(((await plain.json()) as Record<string, unknown>).declared).toBe(false)
	})

	test('the case shows the address it is about, naming the reference', async () => {
		const adresSchema = await createSchema('geoadres', 'e2e hinge geoadres', {
			straat: { type: 'string', title: 'Straat', maxLength: 255 },
		})
		const zaakSchema = await createSchema(
			'geozaak',
			'e2e hinge geozaak',
			{
				onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
				adres: { type: 'string', title: 'Adres', format: 'uuid', maxLength: 64 },
			},
			{ configuration: { 'x-openregister-geo-inheritance': { from: ['adres'] } } },
		)

		const adres = await createObject(adresSchema, {
			straat: `Dorpsstraat ${RUN}`,
			'@self': { geo: { type: 'Point', coordinates: [5.12, 52.09] } },
		})
		const zaak = await createObject(zaakSchema, { onderwerp: 'Zaak op dit adres', adres })

		const res = await admin.get(
			`${API}/objects/${registerId}/${zaakSchema}/${zaak}/geo-features`,
		)
		expect(res.ok(), `geo features failed: ${await res.text()}`).toBeTruthy()

		const body = (await res.json()) as Record<string, unknown>
		const features = body.features as Array<Record<string, unknown>>

		expect(features.length).toBe(1)

		const properties = features[0].properties as Record<string, unknown>
		expect(properties._source).toBe('inherited')
		expect(properties._through).toBe('adres')
		expect(properties._fromObject).toBe(adres)
	})

	test('a second intake source is configuration, and one switched off stays listed', async () => {
		// The intake-sources register is seeded by SeedIntakeSourceRegister on
		// install and upgrade. Assert it is there first, so a skipped repair
		// step reports itself rather than surfacing as a confusing 404.
		const registers = await admin.get(`${API}/registers?limit=500`)
		expect(registers.ok(), `register listing failed: ${await registers.text()}`).toBeTruthy()

		const results = ((await registers.json()).results ?? []) as Array<Record<string, unknown>>
		const intake = results.find((entry) => String(entry.slug) === 'intake-sources')
		expect(
			intake,
			'the intake-sources register is absent: did SeedIntakeSourceRegister run?',
		).toBeTruthy()

		const intakeRegisterId = String((intake as Record<string, unknown>).id)
		const schemaRes = await admin.get(`${API}/schemas?limit=500`)
		expect(schemaRes.ok()).toBeTruthy()

		const schemaResults = ((await schemaRes.json()).results ?? []) as Array<Record<string, unknown>>
		const intakeSchema = schemaResults.find((entry) => String(entry.slug) === 'intake-source')
		expect(intakeSchema, 'the intake-source schema is absent').toBeTruthy()

		const intakeSchemaId = String((intakeSchema as Record<string, unknown>).id)
		const sources: string[] = []

		for (const [slug, enabled] of [[`e2e-postbus-1-${RUN}`, true], [`e2e-postbus-2-${RUN}`, true]] as Array<[string, boolean]>) {
			const res = await admin.post(`${API}/objects/${intakeRegisterId}/${intakeSchemaId}`, {
				data: { slug, title: slug, kind: 'mailbox', enabled, location: `${slug}@example.org` },
			})
			expect(res.ok(), `intake source create failed: ${await res.text()}`).toBeTruthy()

			const uuid = String((await res.json())['@self']?.id)
			created.push([intakeSchemaId, uuid])
			sources.push(uuid)
		}

		// Both are there, which is the whole difference from a mailbox in a
		// settings screen.
		const listed = await admin.get(
			`${API}/objects/${intakeRegisterId}/${intakeSchemaId}?limit=500`,
		)
		expect(listed.ok()).toBeTruthy()

		const slugs = (((await listed.json()).results ?? []) as Array<Record<string, unknown>>).map(
			(entry) => String(entry.slug),
		)
		expect(slugs).toContain(`e2e-postbus-1-${RUN}`)
		expect(slugs).toContain(`e2e-postbus-2-${RUN}`)

		// Switching one off leaves it listed, carrying the reason it is quiet.
		const disabled = await admin.put(
			`${API}/objects/${intakeRegisterId}/${intakeSchemaId}/${sources[1]}`,
			{ data: { slug: `e2e-postbus-2-${RUN}`, title: `e2e-postbus-2-${RUN}`, kind: 'mailbox', enabled: false } },
		)
		expect(disabled.ok(), `disable failed: ${await disabled.text()}`).toBeTruthy()

		const afterRes = await admin.get(
			`${API}/objects/${intakeRegisterId}/${intakeSchemaId}/${sources[1]}`,
		)
		expect(afterRes.ok()).toBeTruthy()
		expect(((await afterRes.json()) as Record<string, unknown>).enabled).toBe(false)
	})
})
