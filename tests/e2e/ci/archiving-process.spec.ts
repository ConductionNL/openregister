/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE ARCHIVING PROCESS, FROM A LIST TO A SIGNED-OFF DECISION.
 *
 * WHAT THIS PROVES THAT THE UNIT TESTS CANNOT
 * -------------------------------------------
 * PHPUnit covers every rule in DestructionReviewService against a list data
 * array held in memory. What it cannot see is whether an archivist can reach
 * any of it: the routes, the settings the repository reads, the register the
 * lists live in, and the shape a destruction list actually has once it has been
 * through the object API. Before this change `GET /api/archival/
 * destruction-lists` answered `results: []` from a stub, which is exactly what a
 * configured instance with nothing to destroy answers, so no test that only read
 * the response could tell the two apart. This one seeds a list and reads it back.
 *
 * WHAT IT DOES NOT COVER, AND WHERE THAT GOES
 * -------------------------------------------
 * The nomination at closure and the preservation regime are the other half of
 * `archiving-as-a-process-with-sign-off` and ship on their own branch. This file
 * covers the review half only: the worklist, the unassigned entries, and the
 * three answers.
 *
 * SELF-CLEANING, INCLUDING THE INSTANCE SETTING. The archival settings are
 * global: pointing them at a fixture register and walking away would leave the
 * next destruction sweep writing its lists into a register this run deleted. The
 * previous settings are read first and put back in `afterAll`.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'
import {
	createObject,
	createRegister,
	createSchema,
	deleteRegister,
	deleteSchema,
	linkSchemaToRegister,
	makeRunId,
} from '../_fixtures.ts'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RUN = makeRunId()

/** The properties a destruction list needs, so `status` is filterable. */
const LIST_PROPERTIES = {
	status: { type: 'string', title: 'Status' },
	createdBy: { type: 'string', title: 'Created by' },
	createdAt: { type: 'string', title: 'Created at' },
	objects: { type: 'array', title: 'Entries' },
	excluded: { type: 'array', title: 'Excluded' },
	approvals: { type: 'array', title: 'Approvals' },
	decisions: { type: 'array', title: 'Decisions' },
}

/** The dossiers the entries are about, so a transfer has something to hand over. */
const DOSSIER_PROPERTIES = {
	title: { type: 'string', title: 'Title' },
}

test.describe.configure({ mode: 'serial' })

test.describe('archiving-process', () => {
	test.use({ storageState: STORAGE_STATE })

	let dossierRegister: { id: number; slug: string; title: string } | null = null
	let dossierSchema: { id: number; slug: string; title: string } | null = null
	let listRegister: { id: number; slug: string; title: string } | null = null
	let listSchema: { id: number; slug: string; title: string } | null = null
	let listId: string | null = null
	let dossierA: string | null = null
	let dossierB: string | null = null
	let previousArchival: Record<string, unknown> | null = null
	let reviewer: string | null = null

	/** Who the admin session is, because the reviewer is a named person. */
	async function currentUser(request: APIRequestContext): Promise<string> {
		const resp = await request.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(resp.status(), 'read the signed-in user').toBe(200)
		const body = await resp.json()
		return body?.ocs?.data?.id ?? 'admin'
	}

	test.beforeAll(async ({ request }) => {
		reviewer = await currentUser(request)

		dossierRegister = await createRegister(request, `${RUN}-dos`)
		dossierSchema = await createSchema(request, RUN, 'dossier', DOSSIER_PROPERTIES)
		await linkSchemaToRegister(request, dossierRegister, [dossierSchema.id])

		listRegister = await createRegister(request, `${RUN}-lst`)
		listSchema = await createSchema(request, RUN, 'destructionlist', LIST_PROPERTIES)
		await linkSchemaToRegister(request, listRegister, [listSchema.id])

		const a = await createObject(request, dossierRegister.id, dossierSchema.id, {
			title: 'Bezwaar 2019/114',
		})
		const b = await createObject(request, dossierRegister.id, dossierSchema.id, {
			title: 'Bezwaar 2019/115',
		})
		dossierA = a.id
		dossierB = b.id

		// Point the instance at the fixture register, keeping what was there.
		const before = await request.get(`${API}/settings/archival`)
		expect(before.status(), 'read the archival settings').toBe(200)
		previousArchival = await before.json()

		const patched = await request.patch(`${API}/settings/archival`, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				...previousArchival,
				destructionListRegister: listRegister.id,
				destructionListSchema: listSchema.id,
				reviewReminderFrequency: 'P7D',
			},
		})
		expect(patched.status(), 'point the settings at the fixture register').toBeLessThan(300)

		const list = await createObject(request, listRegister.id, listSchema.id, {
			status: 'in_review',
			createdBy: 'e2e',
			createdAt: new Date().toISOString(),
			objects: [
				{ uuid: dossierA, title: 'Bezwaar 2019/114', reviewer: null },
				{ uuid: dossierB, title: 'Bezwaar 2019/115', reviewer: null },
			],
			excluded: [],
			approvals: [],
			decisions: [],
		})
		listId = list.id
	})

	test.afterAll(async ({ request }) => {
		if (previousArchival !== null) {
			await request
				.patch(`${API}/settings/archival`, {
					headers: { 'Content-Type': 'application/json' },
					data: previousArchival,
				})
				.catch(() => {})
		}

		if (listSchema !== null) {
			await deleteSchema(request, listSchema.id)
		}

		if (listRegister !== null) {
			await deleteRegister(request, listRegister.id)
		}

		if (dossierSchema !== null) {
			await deleteSchema(request, dossierSchema.id)
		}

		if (dossierRegister !== null) {
			await deleteRegister(request, dossierRegister.id)
		}
	})

	// @e2e retention-management::an-unassigned-entry-is-named
	test('a list with unassigned entries names them, and says it is configured', async ({ request }) => {
		const index = await request.get(`${API}/archival/destruction-lists`)
		expect(index.status(), 'list the destruction lists').toBe(200)
		const indexBody = await index.json()

		// The stub this replaced answered `configured` not at all and `results`
		// always empty, which reads identically to an instance with no work.
		expect(indexBody.configured, 'the instance reports that it is configured').toBe(true)
		expect(
			indexBody.results.map((row: Record<string, unknown>) => row.uuid),
			'our seeded list is in the index',
		).toContain(listId)

		const detail = await request.get(`${API}/archival/destruction-lists/${listId}`)
		expect(detail.status(), 'read the seeded list').toBe(200)
		const body = await detail.json()

		expect(
			body.unassignedEntries.map((entry: Record<string, unknown>) => entry.uuid),
			'both entries are named as unassigned, not merely counted',
		).toEqual([dossierA, dossierB])
	})

	// @e2e retention-management::a-reviewer-sees-what-is-waiting-on-them
	test('an assigned entry appears on that reviewer worklist', async ({ request }) => {
		const assigned = await request.put(
			`${API}/archival/destruction-lists/${listId}/entries/${dossierA}/reviewer`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { reviewer },
			},
		)
		expect(assigned.status(), 'assign the first entry').toBe(200)
		const assignedBody = await assigned.json()
		expect(assignedBody.entry.reviewer, 'the entry names its reviewer').toBe(reviewer)
		expect(
			assignedBody.unassignedEntries.map((entry: Record<string, unknown>) => entry.uuid),
			'only the second entry is still unassigned',
		).toEqual([dossierB])

		const pending = await request.get(`${API}/archival/reviews/pending`)
		expect(pending.status(), 'read my pending reviews').toBe(200)
		const pendingBody = await pending.json()

		expect(pendingBody.reviewer, 'the worklist is the caller own').toBe(reviewer)
		expect(
			pendingBody.results.map((entry: Record<string, unknown>) => entry.uuid),
			'the assigned entry is waiting on me',
		).toContain(dossierA)
		expect(
			pendingBody.results.map((entry: Record<string, unknown>) => entry.uuid),
			'the unassigned entry is waiting on nobody',
		).not.toContain(dossierB)
	})

	// @e2e retention-management::retaining-moves-the-date-and-says-why
	test('retaining moves the archiefactiedatum and records the reason', async ({ request }) => {
		const decided = await request.post(
			`${API}/archival/destruction-lists/${listId}/entries/${dossierA}/decision`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: {
					answer: 'retain',
					reason: 'Lopende bezwaarprocedure',
					newArchiefactiedatum: '2031-03-01',
				},
			},
		)
		expect(decided.status(), 'answer my own entry with retain').toBe(200)
		const body = await decided.json()

		expect(body.decision.answer).toBe('retain')
		expect(body.decision.reviewer, 'the decision names who took it').toBe(reviewer)
		expect(body.decision.reason).toBe('Lopende bezwaarprocedure')
		expect(body.decision.newArchiefactiedatum).toBe('2031-03-01')

		// The date moved on the RECORD, not only in the decision history.
		const object = await request.get(
			`${API}/objects/${dossierRegister!.id}/${dossierSchema!.id}/${dossierA}`,
		)
		expect(object.status(), 'read the dossier back').toBe(200)
		const retention = (await object.json())?.['@self']?.retention ?? {}
		expect(retention.archiefactiedatum, 'the record carries the new date').toBe('2031-03-01')

		// And the entry has left the worklist, which is the half a decision
		// recorded somewhere else would not do.
		const pending = await request.get(`${API}/archival/reviews/pending`)
		const pendingBody = await pending.json()
		expect(
			pendingBody.results.map((entry: Record<string, unknown>) => entry.uuid),
			'an answered entry stops waiting on me',
		).not.toContain(dossierA)
	})

	// @e2e retention-management::transfer-is-a-decision-not-a-separate-errand
	test('transfer is answered on the list and recorded in the same history', async ({ request }) => {
		const assigned = await request.put(
			`${API}/archival/destruction-lists/${listId}/entries/${dossierB}/reviewer`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { reviewer },
			},
		)
		expect(assigned.status(), 'assign the second entry').toBe(200)

		const decided = await request.post(
			`${API}/archival/destruction-lists/${listId}/entries/${dossierB}/decision`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: {
					answer: 'transfer',
					reason: 'Blijvend bewaren, naar het e-depot',
				},
			},
		)
		expect(decided.status(), 'answer the second entry with transfer').toBe(200)
		const body = await decided.json()

		expect(body.decision.answer).toBe('transfer')
		expect(
			body.decision.transferListUuid,
			'the item was handed to the transfer path and the list is named here',
		).toBeTruthy()

		// ONE history, not two: both answers sit on the same list.
		const detail = await request.get(`${API}/archival/destruction-lists/${listId}`)
		const detailBody = await detail.json()
		expect(
			detailBody.decisions.map((decision: Record<string, unknown>) => decision.answer),
			'destroy, retain and transfer land in one decision history',
		).toEqual(['retain', 'transfer'])
	})

	test('an entry nobody is accountable for refuses rather than falling open', async ({ request }) => {
		const fresh = await createObject(request, dossierRegister!.id, dossierSchema!.id, {
			title: 'Bezwaar 2019/116',
		})

		const refused = await request.post(
			`${API}/archival/destruction-lists/${listId}/entries/${fresh.id}/decision`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { answer: 'destroy', reason: 'Past its date' },
			},
		)

		// Not on the list at all: 404, and nothing was destroyed.
		expect(refused.status(), 'a record that is not on this list cannot be answered').toBe(404)
	})
})

/*
 * NOMINATION AT CLOSURE, AND THE FACTS ON THE OBJECT.
 *
 * A separate describe because it needs its own world: a selectielijst register
 * with a row in it, and a schema that both declares an archive block pointing
 * at that row and a lifecycle whose last state is declared final. The unit
 * tests cover the derivation; what they cannot see is whether closing an object
 * through the real transition endpoint reaches the listener at all.
 */
test.describe('archiving-nomination', () => {
	test.use({ storageState: STORAGE_STATE })

	let lijstRegister: { id: number; slug: string; title: string } | null = null
	let lijstSchema: { id: number; slug: string; title: string } | null = null
	let zaakRegister: { id: number; slug: string; title: string } | null = null
	let zaakSchema: { id: number; slug: string; title: string } | null = null
	let zaakId: string | null = null
	let previousArchival: Record<string, unknown> | null = null

	const CATEGORY = `${RUN}-11.1.2`

	test.beforeAll(async ({ request }) => {
		lijstRegister = await createRegister(request, `${RUN}-sel`)
		lijstSchema = await createSchema(request, RUN, 'selectielijst', {
			categorie: { type: 'string', title: 'Categorie' },
			archiefnominatie: { type: 'string', title: 'Archiefnominatie' },
			bewaartermijn: { type: 'string', title: 'Bewaartermijn' },
			bron: { type: 'string', title: 'Bron' },
		})
		await linkSchemaToRegister(request, lijstRegister, [lijstSchema.id])

		await createObject(request, lijstRegister.id, lijstSchema.id, {
			categorie: CATEGORY,
			archiefnominatie: 'vernietigen',
			bewaartermijn: 'P7Y',
			bron: 'Selectielijst gemeenten 2020',
		})

		zaakRegister = await createRegister(request, `${RUN}-zaak`)
		zaakSchema = await createSchema(
			request,
			RUN,
			'zaak',
			{
				title: { type: 'string', title: 'Title' },
				status: {
					type: 'string',
					title: 'Status',
					enum: ['in_behandeling', 'afgehandeld'],
				},
			},
			{
				archive: {
					enabled: true,
					classification: CATEGORY,
				},
				configuration: {
					'x-openregister-lifecycle': {
						field: 'status',
						initial: 'in_behandeling',
						final: ['afgehandeld'],
						transitions: {
							afhandelen: { from: ['in_behandeling'], to: 'afgehandeld' },
						},
					},
				},
			},
		)
		await linkSchemaToRegister(request, zaakRegister, [zaakSchema.id])

		const before = await request.get(`${API}/settings/archival`)
		previousArchival = await before.json()
		await request.patch(`${API}/settings/archival`, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				...previousArchival,
				selectielijstRegister: lijstRegister.id,
				selectielijstSchema: lijstSchema.id,
			},
		})

		const zaak = await createObject(request, zaakRegister.id, zaakSchema.id, {
			title: 'Bezwaar 2026/1',
			status: 'in_behandeling',
		})
		zaakId = zaak.id
	})

	test.afterAll(async ({ request }) => {
		if (previousArchival !== null) {
			await request
				.patch(`${API}/settings/archival`, {
					headers: { 'Content-Type': 'application/json' },
					data: previousArchival,
				})
				.catch(() => {})
		}

		for (const schema of [zaakSchema, lijstSchema]) {
			if (schema !== null) {
				await deleteSchema(request, schema.id)
			}
		}

		for (const register of [zaakRegister, lijstRegister]) {
			if (register !== null) {
				await deleteRegister(request, register.id)
			}
		}
	})

	// @e2e retention-management::closing-an-object-writes-its-archival-future
	// @e2e retention-management::a-handler-answering-a-woo-request-sees-the-basis
	test('closing a case writes its nomination, its date and the row they came from', async ({ request }) => {
		const closed = await request.post(`${API}/objects/${zaakId}/transition`, {
			headers: { 'Content-Type': 'application/json' },
			data: { action: 'afhandelen' },
		})
		expect(closed.status(), 'close the case through the declared transition').toBeLessThan(300)

		const read = await request.get(
			`${API}/objects/${zaakRegister!.id}/${zaakSchema!.id}/${zaakId}`,
		)
		expect(read.status(), 'read the closed case back').toBe(200)
		const retention = (await read.json())?.['@self']?._retention ?? {}

		// The nomination, the date and WHICH ROW decided, all on the object.
		expect(retention.appraisal, 'the selectielijst row decided the appraisal').toBe('destroy')
		expect(retention.disposalDate, 'a disposal date was derived').toBeTruthy()
		expect(retention.selectionListRow, 'the row that decided is named').toBe(CATEGORY)
		expect(retention.source, 'and which list it came from').toBe('Selectielijst gemeenten 2020')
		expect(retention.basis, 'the basis is the selection list, not a schema guess').toBe('selection_list')
		expect(retention.nomination?.status, 'the nomination reports itself').toBe('nominated')
		expect(retention.nomination?.trigger, 'and says the closure caused it').toBe('closure')
	})

	test('recomputing a nomination is refused without a reason and recorded with one', async ({ request }) => {
		const refused = await request.post(
			`${API}/archival/objects/${zaakId}/nomination/recompute`,
			{ headers: { 'Content-Type': 'application/json' }, data: {} },
		)
		expect(refused.status(), 'a recomputation with no reason is refused').toBe(400)

		const done = await request.post(
			`${API}/archival/objects/${zaakId}/nomination/recompute`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { reason: 'Selectielijst 2026 replaced the 2020 list' },
			},
		)
		expect(done.status(), 'a recomputation with a reason is recorded').toBe(200)
		const recomputed = await done.json()
		expect(recomputed.nomination?.trigger, 'the act names itself').toBe('recompute')

		const read = await request.get(
			`${API}/objects/${zaakRegister!.id}/${zaakSchema!.id}/${zaakId}`,
		)
		const retention = (await read.json())?.['@self']?._retention ?? {}
		expect(retention.nomination?.reason, 'the reason is on the record').toBe(
			'Selectielijst 2026 replaced the 2020 list',
		)
	})
})

/*
 * THE ADMINISTERED ELEMENT MAPPING, AND THE REFUSAL IT DRIVES.
 *
 * The unit tests cover which elements a mapping leaves unfilled. What they
 * cannot see is whether the schema editor accepts a mapping at all: the key has
 * to survive `setConfiguration()`, which silently DROPS any configuration key
 * not in Schema::ANNOTATION_VOCABULARY. A dropped key looks exactly like a
 * saved one from the client's side, and the refusal it exists to drive would
 * then never fire on any instance.
 */
test.describe('archiving-element-mapping', () => {
	test.use({ storageState: STORAGE_STATE })

	let register: { id: number; slug: string; title: string } | null = null
	let schema: { id: number; slug: string; title: string } | null = null

	const COMPLETE_MAPPING = {
		identificatie: { property: 'zaaknummer' },
		naam: { property: 'titel' },
		waardering: { property: 'resultaat' },
		archiefvormer: { const: 'Gemeente Voorbeeld' },
		beperkingGebruik: { const: 'Geen beperking' },
	}

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, `${RUN}-map`)
	})

	test.afterAll(async ({ request }) => {
		if (schema !== null) {
			await deleteSchema(request, schema.id)
		}

		if (register !== null) {
			await deleteRegister(request, register.id)
		}
	})

	// @e2e retention-management::an-unmapped-mandatory-element-stops-the-transfer-here
	test('a mapping that leaves a mandatory element unfilled is refused at schema save', async ({ request }) => {
		const incomplete = { ...COMPLETE_MAPPING }
		delete (incomplete as Record<string, unknown>).archiefvormer

		const refused = await request.post(`${API}/schemas`, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				slug: `${RUN}-unmapped`,
				title: 'E2E unmapped',
				properties: {
					zaaknummer: { type: 'string', title: 'Zaaknummer' },
					titel: { type: 'string', title: 'Titel' },
					resultaat: { type: 'string', title: 'Resultaat' },
				},
				configuration: { 'x-openregister-mdto-mapping': incomplete },
			},
		})

		expect(refused.status(), 'a mapping missing a mandatory element is refused').toBe(400)
		const body = await refused.text()
		expect(body, 'and the refusal names the element').toContain('archiefvormer')
	})

	// @e2e retention-management::an-unmapped-mandatory-element-stops-the-transfer-here
	test('a complete mapping survives the save and reads back', async ({ request }) => {
		schema = await createSchema(
			request,
			RUN,
			'mapped',
			{
				zaaknummer: { type: 'string', title: 'Zaaknummer' },
				titel: { type: 'string', title: 'Titel' },
				resultaat: { type: 'string', title: 'Resultaat' },
			},
			{ configuration: { 'x-openregister-mdto-mapping': COMPLETE_MAPPING } },
		)
		await linkSchemaToRegister(request, register!, [schema.id])

		const read = await request.get(`${API}/schemas/${schema.id}`)
		expect(read.status(), 'read the saved schema back').toBe(200)
		const configuration = (await read.json())?.configuration ?? {}

		// 🔴 THE KEY SURVIVED. setConfiguration() drops any key not in
		// ANNOTATION_VOCABULARY, and a dropped key reports success.
		expect(
			configuration['x-openregister-mdto-mapping']?.archiefvormer?.const,
			'the mapping was stored, not dropped',
		).toBe('Gemeente Voorbeeld')
	})
})
