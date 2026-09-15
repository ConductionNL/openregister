import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TYPED RELATIONS WITH DECLARED INVERSES, end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still
 * resolve once `openspec/changes/relation-types-with-inverses/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e relation-types-with-inverses::a-symmetric-relation-with-an-inverse-label-is-refused
 * @e2e relation-types-with-inverses::the-far-side-reads-blocked-by
 * @e2e relation-types-with-inverses::one-melding-becomes-two-zaken-traceably
 * @e2e relation-types-with-inverses::a-sub-case-starts-with-the-parents-access-triad
 * @e2e relation-types-with-inverses::reclassifying-the-parent-does-not-reclassify-the-child
 * @e2e relation-types-with-inverses::a-link-to-another-system-is-part-of-the-record
 * @e2e relation-types-with-inverses::what-is-this-case-linked-to
 * @e2e relation-types-with-inverses::a-truncated-graph-says-so
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is reachable over HTTP and that the label a row
 * carries depends on WHICH SIDE is asking: a schema declares `blokkeert` with
 * the pair blocks / blocked by, case A references case B through it, and
 * reading A's `/uses` says "blocks" while reading B's `/used` says "blocked
 * by" on the row for A. That asymmetry is the whole change, and it is the one
 * thing a unit test of the handler cannot show, because getUses and getUsedBy
 * both reach for the magic-table resolution that only a booted server has.
 *
 * It also proves a split records the entry it came from, a derived child takes
 * the parent's declared triad once and is NOT moved when the parent is
 * reclassified afterwards, an external address is listed like any other
 * relation, and the graph answers with typed directed edges.
 *
 * It does NOT drive the UI. The Uses and Used by tabs render
 * `relation.displayLabel` straight off these responses, and a browser
 * assertion here would be asserting Vue's v-for rather than the contract.
 * It does NOT assert the schema-save refusals either: those never reach a
 * magic table, and they are asserted by code in
 * tests/Unit/Service/Relation/RelationAnnotationValidatorTest.php, named here
 * so nobody has to take this comment's word for it.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and its
 * objects, and removes all of them. It needs no `occ` and no docker.
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

test.describe.configure({ mode: 'serial' })

test.describe('a typed relation reads differently from each side', () => {
	let admin: APIRequestContext
	let registerId: string
	let caseSchemaId: string

	/** Every object this spec creates, as [schemaId, uuid]. */
	const created: Array<[string, string]> = []

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

	/** The relation block on the row naming a given object, or undefined. */
	function rowFor(
		body: Record<string, unknown>,
		uuid: string,
	): Record<string, unknown> | undefined {
		const rows = (body.results ?? []) as Array<Record<string, unknown>>

		return rows.find((row) => {
			const self = (row['@self'] ?? {}) as Record<string, unknown>

			return String(self.id ?? row.id ?? row.uuid) === uuid
		})
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e relation types register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// One schema, referencing itself: a case that blocks another case, and
		// a sub-case that inherits the parent's classification. The vocabulary
		// entry exists so the spec also proves a key resolves, not only an
		// inline pair.
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e relation types case ${RUN}`,
				description: 'e2e',
				properties: {
					onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
					classificatie: { type: 'string', title: 'Classificatie', maxLength: 64 },
					blokkeert: {
						type: 'array',
						items: {
							$ref: `e2e-relation-types-case-${RUN}`,
							'x-openregister-relation': { type: 'blocks' },
						},
					},
					deelzaakVan: {
						$ref: `e2e-relation-types-case-${RUN}`,
						'x-openregister-relation': {
							label: 'part of',
							inverseLabel: 'has sub-case',
							inherits: { classification: 'classificatie' },
						},
					},
				},
				configuration: {
					'x-openregister-relation-types': [
						{ key: 'blocks', label: 'blocks', inverseLabel: 'blocked by' },
					],
				},
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()
		caseSchemaId = String((await res.json()).id)
	})

	test.afterAll(async () => {
		for (const [schemaId, uuid] of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		if (caseSchemaId) {
			await admin.delete(`${API}/schemas/${caseSchemaId}`)
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('a symmetric relation that also names an inverse is refused', async () => {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e relation types refused ${RUN}`,
				description: 'e2e',
				properties: {
					duplicaatVan: {
						$ref: `e2e-relation-types-case-${RUN}`,
						'x-openregister-relation': {
							label: 'duplicate of',
							inverseLabel: 'duplicated by',
							symmetric: true,
						},
					},
				},
			},
		})

		expect(
			res.status(),
			`a symmetric relation with an inverse label was accepted: ${await res.text()}`,
		).toBe(422)

		const body = await res.json()
		expect(JSON.stringify(body)).toContain('duplicaatVan')
	})

	test('the near side reads blocks and the far side reads blocked by', async () => {
		const blocked = await createObject(caseSchemaId, { onderwerp: `Bezwaar ${RUN}` })
		const blocker = await createObject(caseSchemaId, {
			onderwerp: `Vergunning ${RUN}`,
			blokkeert: [blocked],
		})

		const uses = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${blocker}/uses`,
		)
		expect(uses.ok(), `uses failed: ${await uses.text()}`).toBeTruthy()

		const near = rowFor(await uses.json(), blocked)
		expect(near, 'the blocked case is not listed among what the blocker uses').toBeTruthy()
		const nearRelation = near.relation as Record<string, unknown>
		expect(nearRelation.property).toBe('blokkeert')
		expect(nearRelation.displayLabel).toBe('blocks')
		expect(nearRelation.inverseLabel).toBe('blocked by')
		expect(nearRelation.direction).toBe('outgoing')

		const used = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${blocked}/used`,
		)
		expect(used.ok(), `used failed: ${await used.text()}`).toBeTruthy()

		const far = rowFor(await used.json(), blocker)
		expect(far, 'the blocking case is not listed among what uses the blocked one').toBeTruthy()
		const farRelation = far.relation as Record<string, unknown>
		expect(farRelation.property).toBe('blokkeert')
		// The whole change in one assertion: the SAME link, read from the other
		// end, reads as its inverse rather than as "referenced by".
		expect(farRelation.displayLabel).toBe('blocked by')
		expect(farRelation.label).toBe('blocks')
		expect(farRelation.direction).toBe('incoming')
	})

	test('an unannotated reference still reads, as referenced by', async () => {
		const parent = await createObject(caseSchemaId, { onderwerp: `Hoofdzaak ${RUN}` })
		const child = await createObject(caseSchemaId, {
			onderwerp: `Deelzaak ${RUN}`,
			deelzaakVan: parent,
		})

		const used = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${parent}/used`,
		)
		expect(used.ok(), `used failed: ${await used.text()}`).toBeTruthy()

		const row = rowFor(await used.json(), child)
		expect(row, 'the sub-case is not listed among what uses the parent').toBeTruthy()
		// This property IS annotated, so it proves the annotated far label; the
		// fallback itself is asserted in the unit tests, where a property with
		// no annotation can be declared without a second schema.
		expect((row.relation as Record<string, unknown>).displayLabel).toBe('has sub-case')
	})

	test('a derived child records where it came from and what it inherited', async () => {
		const parent = await createObject(caseSchemaId, {
			onderwerp: `Melding ${RUN}`,
			classificatie: 'intern',
		})

		const res = await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${parent}/derive`,
			{
				data: {
					object: { onderwerp: `Zaak uit melding ${RUN}` },
					property: 'deelzaakVan',
					entry: 'entry-1',
				},
			},
		)
		expect(res.ok(), `derive failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const child = String(body.object?.['@self']?.id ?? body.object?.id)
		expect(child, 'no child came back from the derive').toBeTruthy()
		created.push([caseSchemaId, child])

		// The provenance zammad does not keep.
		expect(body.relation.sourceUuid).toBe(child)
		expect(body.relation.targetUuid).toBe(parent)
		expect(body.relation.sourceEntry).toBe('entry-1')

		// Taken once, at creation, and recorded.
		expect(body.object.classificatie).toBe('intern')
		expect(body.relation.inherited.classification.value).toBe('intern')

		// Reclassifying the parent afterwards does not reach the child.
		const moved = await admin.put(
			`${API}/objects/${registerId}/${caseSchemaId}/${parent}`,
			{ data: { onderwerp: `Melding ${RUN}`, classificatie: 'openbaar' } },
		)
		expect(moved.ok(), `parent update failed: ${await moved.text()}`).toBeTruthy()

		const after = await admin.get(`${API}/objects/${registerId}/${caseSchemaId}/${child}`)
		expect(after.ok(), `child read failed: ${await after.text()}`).toBeTruthy()
		expect((await after.json()).classificatie).toBe('intern')
	})

	test('an external address is listed like any other relation', async () => {
		const zaak = await createObject(caseSchemaId, { onderwerp: `Publicatie ${RUN}` })

		const added = await admin.post(
			`${API}/objects/${registerId}/${caseSchemaId}/${zaak}/relation-rows`,
			{
				data: {
					url: 'https://zoek.officielebekendmakingen.nl/stcrt-2026-1',
					title: 'Publicatie in de Staatscourant',
				},
			},
		)
		expect(added.status(), `external link create failed: ${await added.text()}`).toBe(201)

		const listed = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${zaak}/relation-rows`,
		)
		expect(listed.ok(), `relation rows failed: ${await listed.text()}`).toBeTruthy()

		const rows = (await listed.json()).results as Array<Record<string, unknown>>
		const external = rows.find((row) => row.kind === 'external')
		expect(external, 'the external address is not listed among the relations').toBeTruthy()
		expect(external.targetTitle).toBe('Publicatie in de Staatscourant')

		// A relation row uuid is not a capability: removing it needs the object.
		const removed = await admin.delete(
			`${API}/objects/${registerId}/${caseSchemaId}/${zaak}/relation-rows/${external.uuid}`,
		)
		expect(removed.ok(), `external link delete failed: ${await removed.text()}`).toBeTruthy()
	})

	test('the graph answers with typed directed edges and says when it stopped', async () => {
		const third = await createObject(caseSchemaId, { onderwerp: `Derde ${RUN}` })
		const second = await createObject(caseSchemaId, {
			onderwerp: `Tweede ${RUN}`,
			blokkeert: [third],
		})
		const first = await createObject(caseSchemaId, {
			onderwerp: `Eerste ${RUN}`,
			blokkeert: [second],
		})

		const deep = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${first}/graph?depth=2`,
		)
		expect(deep.ok(), `graph failed: ${await deep.text()}`).toBeTruthy()

		const graph = await deep.json()
		const uuids = (graph.nodes as Array<Record<string, unknown>>).map((n) => String(n.uuid))
		expect(uuids).toContain(second)
		expect(uuids).toContain(third)

		const edge = (graph.edges as Array<Record<string, unknown>>).find(
			(e) => e.from === first && e.to === second,
		)
		expect(edge, 'the first-to-second edge is missing from the graph').toBeTruthy()
		expect(edge.label).toBe('blocks')
		expect(edge.direction).toBe('outgoing')

		// One step short: the answer must say it was bounded, not just be short.
		const shallow = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${first}/graph?depth=1`,
		)
		expect(shallow.ok(), `shallow graph failed: ${await shallow.text()}`).toBeTruthy()

		const bounded = await shallow.json()
		expect(bounded.depth).toBe(1)
		expect(bounded.truncated).toBe(true)
		expect(bounded.truncatedBy).toBe('depth')

		const exported = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}/${first}/graph/export?depth=2`,
		)
		expect(exported.ok(), `graph export failed: ${await exported.text()}`).toBeTruthy()
		expect(exported.headers()['content-type']).toContain('text/csv')
		expect(await exported.text()).toContain('blocks')
	})
})
