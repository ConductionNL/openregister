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
