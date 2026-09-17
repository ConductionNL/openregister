import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE TRAIL IS SHIPPED, AND A READ NAMES ITS PURPOSE — end to end, over HTTP.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/audit-trail-shipped-and-purpose-bound/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e verwerkingsregister-api::a-person-search-names-its-grondslag
 * @e2e verwerkingsregister-api::an-unbound-purpose-is-refused
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is REACHABLE over HTTP and that the refusal is real:
 * the purpose routes are registered, an administered purpose bound to a
 * processing activity lets a read of an annotated schema through, a read that
 * names no purpose is refused by name, a purpose naming no activity is refused
 * differently, and the sink status endpoint answers an administrator. Those are
 * exactly the failures a green unit suite hides: a route missing from
 * `appinfo/routes.php` is a 404 no PHPUnit test would notice, and an annotation
 * dropped by `Schema::setConfiguration()` would leave every unbound read
 * answering 200 while the schema editor reported a saved setting.
 *
 * There is deliberately NO anchor for `the-security-operations-centre-can-read-
 * the-trail`. This file cannot prove that scenario, and an anchor claiming it
 * would stop the next person looking for the test that does.
 *
 * It does NOT assert the LINE IN THE FILE. The sink writes to a path on the
 * server's filesystem, and there is no HTTP door that reads it back — by
 * design, because the file exists for something outside this application to
 * read. A spec that configured a sink and then asserted on a file it cannot
 * open would be asserting nothing. The "a broken sink is loud" scenario is
 * marked `@e2e exclude {filesystem condition, covered by unit tests}` in the
 * delta and is asserted in
 * tests/Unit/Service/Audit/AuditSinkTest.php
 * (testABrokenSinkIsLoudOnceAndCountsTheRest and
 * testAnAuditedActAppearsAsALineInTheFile) — named here so nobody has to take
 * this comment's word for it. What this file CAN reach is the status the
 * operations console reads, and that is what it checks.
 *
 * The security-setting announcement (REQ-ATS-004), the token attribution
 * (REQ-ATS-002) and the reported-content copy (REQ-ATS-003) are not here
 * because they are not built yet; they are the second part of this change.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own processing activity, purpose,
 * register and schema, and removes the register and schema. It needs no `occ`
 * and no docker.
 *
 * WHAT IT LEAVES BEHIND, SAID PLAINLY. One processing activity and one purpose,
 * both named for this run. Neither has a delete that removes: an activity
 * soft-archives and a purpose retires, because audit rows reference both and a
 * row naming something nobody can look up is worse than a row too many. They
 * aggregate onto the verantwoording report as a line with a zero count.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const PURPOSES = `${API}/avg/purposes`
const ACTIVITIES = `${API}/avg/processing-activities`

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

test.describe('doelbinding and the shipped trail over HTTP', () => {
	let admin: APIRequestContext
	let registerId = ''
	let boundSchemaId = ''
	let openSchemaId = ''
	const boundPurpose = `e2e-brp-${RUN}`
	const unboundPurpose = `e2e-unbound-${RUN}`

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		// A processing activity for the bound purpose to name.
		const activity = await admin.post(ACTIVITIES, {
			data: {
				code: `E2E-VA-${RUN}`,
				naam: `e2e adresonderzoek ${RUN}`,
				doelbinding: 'e2e',
				rechtsgrond: 'wettelijke verplichting',
				status: 'actief',
			},
		})
		expect(activity.ok(), `activity create failed: ${await activity.text()}`).toBeTruthy()

		const bound = await admin.post(PURPOSES, {
			data: {
				code: boundPurpose,
				name: `e2e adresonderzoek ${RUN}`,
				activity: `E2E-VA-${RUN}`,
			},
		})
		expect(bound.ok(), `purpose create failed: ${await bound.text()}`).toBeTruthy()
		expect(
			(await bound.json()).bound,
			'the purpose did not bind to the activity it names',
		).toBeTruthy()

		// A purpose naming an activity nothing answers to. Administering it is
		// allowed; querying under it is not, and those are different things.
		const unbound = await admin.post(PURPOSES, {
			data: { code: unboundPurpose, name: 'names nothing', activity: `E2E-MISSING-${RUN}` },
		})
		expect(unbound.ok(), `unbound purpose create failed: ${await unbound.text()}`).toBeTruthy()
		expect((await unbound.json()).bound, 'a purpose naming nothing must not read as bound').toBeFalsy()

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e doelbinding register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const properties = { title: { type: 'string', title: 'Title', maxLength: 255 } }
		const authorization = {
			read: ['authenticated'],
			create: ['authenticated'],
			update: ['authenticated'],
			delete: ['authenticated'],
		}

		// The opt-in. Without it every read below answers 200, which is also
		// what makes this line the test of the annotation surviving the save.
		const boundSchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e doelbinding schema ${RUN}`,
				description: 'e2e',
				properties,
				authorization,
				'x-openregister-purpose-required': true,
			},
		})
		expect(boundSchema.ok(), `schema create failed: ${await boundSchema.text()}`).toBeTruthy()
		boundSchemaId = String((await boundSchema.json()).id)

		// The control. Same shape, no annotation: it separates "doelbinding
		// refused this read" from "this instance refuses reads".
		const openSchema = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e open schema ${RUN}`,
				description: 'e2e',
				properties,
				authorization,
			},
		})
		expect(openSchema.ok(), `control schema create failed: ${await openSchema.text()}`).toBeTruthy()
		openSchemaId = String((await openSchema.json()).id)
	})

	test.afterAll(async () => {
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}

		for (const id of [boundSchemaId, openSchemaId]) {
			if (id !== '') {
				await admin.delete(`${API}/schemas/${id}`)
			}
		}

		await admin.dispose()
	})

	test('the administered purposes are readable, with their bindings resolved', async () => {
		const res = await admin.get(PURPOSES)
		expect(res.ok(), `purpose list failed: ${res.status()}`).toBeTruthy()

		const body = await res.json()
		const codes = (body.results as Array<Record<string, unknown>>).map((row) => row.code)
		expect(codes, 'the bound purpose is missing from the list').toContain(boundPurpose)
		expect(codes, 'the unbound purpose is hidden rather than published as unusable').toContain(
			unboundPurpose,
		)
	})

	test('a control read of an unannotated schema still needs no purpose', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${openSchemaId}`)
		expect(
			res.status(),
			'an unannotated schema must read exactly as it did before this change',
		).toBeLessThan(400)
	})

	test('a read of an annotated schema with no declared purpose is refused by name', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${boundSchemaId}`)

		expect(res.status(), 'an unbound read was not refused').toBe(403)
		const body = await res.json()
		expect(body.error).toBe('PURPOSE_REFUSED')
		expect(body.rule, 'the refusal does not say which rule refused').toBe('purpose-missing')
		expect(
			body.available,
			'the refusal offers no purpose the caller could have named',
		).toContain(boundPurpose)
	})

	test('the same read under a bound purpose runs', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${boundSchemaId}`, {
			headers: { 'X-Processing-Purpose': boundPurpose },
		})

		expect(
			res.status(),
			`a read under a bound purpose was refused: ${await res.text()}`,
		).toBeLessThan(400)
	})

	test('the parameter form works too, so a client with no header control is not locked out', async () => {
		const res = await admin.get(
			`${API}/objects/${registerId}/${boundSchemaId}?_purpose=${boundPurpose}`,
		)

		expect(res.status(), 'the _purpose parameter was not honoured').toBeLessThan(400)
	})

	test('a purpose that names no processing activity is refused as unbound, not as unknown', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${boundSchemaId}`, {
			headers: { 'X-Processing-Purpose': unboundPurpose },
		})

		expect(res.status()).toBe(403)
		expect((await res.json()).rule, 'an unbound purpose was not told apart from an unknown one').toBe(
			'purpose-unbound',
		)
	})

	test('a purpose nobody administered is refused as unknown', async () => {
		const res = await admin.get(`${API}/objects/${registerId}/${boundSchemaId}`, {
			headers: { 'X-Processing-Purpose': `never-administered-${RUN}` },
		})

		expect(res.status()).toBe(403)
		const body = await res.json()
		expect(body.rule).toBe('purpose-unknown')
		expect(
			body.purpose,
			'the refusal does not quote back the purpose the caller sent',
		).toBe(`never-administered-${RUN}`)
	})

	test('the refused reads are countable per purpose', async () => {
		const res = await admin.get(`${PURPOSES}/report`)
		expect(res.ok(), `report failed: ${res.status()}`).toBeTruthy()

		const body = await res.json()
		expect(typeof body.total, 'the report carries no total').toBe('number')
		expect(body.counts, 'the report carries no per-purpose counts').toBeTruthy()
		expect(
			body.counts[boundPurpose] ?? 0,
			'the read made under the bound purpose was not attributed to it',
		).toBeGreaterThan(0)
	})

	test('an administrator can see whether the trail is reaching the log platform', async () => {
		const res = await admin.get(`${API}/audit/sink`)
		expect(res.ok(), `sink status failed: ${res.status()}`).toBeTruthy()

		const body = await res.json()
		// An instance that ships nothing reports `configured: false` and a null
		// health, rather than a green tick that means nothing was checked.
		expect(body).toHaveProperty('configured')
		expect(body).toHaveProperty('unshipped')
		if (body.configured === false) {
			expect(body.healthy, 'an unconfigured sink must not report itself healthy').toBeNull()
		}
	})
})
