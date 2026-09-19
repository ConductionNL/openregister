import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BPMN interchange e2e — the two answers, over HTTP.
 *
 * THE POINT OF THIS SUITE IS THAT "BROKEN" AND "UNSUPPORTED" ARE DIFFERENT.
 *
 * Before the boundary was validated against the vendored OMG schema set, a
 * malformed file was walked into a flow with missing nodes and answered 201.
 * An author reading that response had no way to learn their file was broken;
 * the only vocabulary the endpoint had was "we could not express this
 * construct", which is a sentence about their process, not about their XML.
 *
 * So every assertion here is paired: the malformed file must be answered as
 * malformed AND must create nothing, and the valid-but-unsupported file must
 * create a flow AND carry a report naming the construct. A suite that only
 * asserted "the malformed one fails" would pass against an importer that
 * refuses everything.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md#requirement-bpmn-import-accepts-a-documented-subset-and-reports-every-loss
 */
import { request as apiRequest, expect, test } from '@playwright/test'

const RUN_ID = `e2e-bpmn-${Date.now().toString(36)}`

// See tests/e2e/flow-engine.spec.ts for why the API describes run with no
// session: with both a session cookie and Basic auth, Nextcloud prefers the
// cookie and then demands a CSRF token an APIRequestContext cannot carry.
const NO_SESSION = { cookies: [], origins: [] }

const API_HEADERS = {
	'OCS-APIRequest': 'true',
	Authorization: `Basic ${Buffer.from(
		`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
	).toString('base64')}`,
}

const HEAD =
	'<?xml version="1.0" encoding="UTF-8"?>\n'
	+ '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"'
	+ ' id="Definitions_1" targetNamespace="urn:openregister:e2e">\n'

/** A file that is XML, is BPMN-shaped, and is not valid BPMN: the flow goes nowhere. */
const MALFORMED =
	`${HEAD}  <bpmn:process id="Process_1" isExecutable="false">\n`
	+ '    <bpmn:startEvent id="s1" name="Start"/>\n'
	+ '    <bpmn:serviceTask id="t1" name="Doe iets"/>\n'
	+ '    <bpmn:sequenceFlow id="f1" sourceRef="s1"/>\n'
	+ '  </bpmn:process>\n</bpmn:definitions>\n'

/** Valid BPMN carrying an event sub-process, which the engine cannot express. */
const VALID_BUT_UNSUPPORTED =
	`${HEAD}  <bpmn:process id="Process_1" name="${RUN_ID} unsupported" isExecutable="false">\n`
	+ '    <bpmn:startEvent id="s1" name="Start"/>\n'
	+ '    <bpmn:subProcess id="sub1" name="Bij een fout" triggeredByEvent="true"/>\n'
	+ '    <bpmn:endEvent id="e1" name="Klaar"/>\n'
	+ '    <bpmn:sequenceFlow id="f1" sourceRef="s1" targetRef="e1"/>\n'
	+ '  </bpmn:process>\n</bpmn:definitions>\n'

const created: string[] = []

test.afterAll(async () => {
	let ctx: APIRequestContext | null = null

	try {
		ctx = await apiRequest.newContext({
			baseURL: process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL,
			extraHTTPHeaders: { ...API_HEADERS },
		})

		for (const id of created) {
			await ctx.delete(`/apps/openregister/api/flows/${id}`)
		}
	} catch (error) {
		console.warn('[flow-bpmn] fixture cleanup failed:', error)
	} finally {
		await ctx?.dispose()
	}
})

test.describe('BPMN interchange over the API', () => {
	test.use({ storageState: NO_SESSION, extraHTTPHeaders: API_HEADERS })

	/**
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md#requirement-bpmn-import-accepts-a-documented-subset-and-reports-every-loss
	 * Scenario: Malformed and unsupported are two different answers
	 */
	test('a malformed file is answered as malformed, and creates nothing', async ({
		request,
	}) => {
		const before = await request.get('/apps/openregister/api/flows?limit=500')
		const countBefore = ((await before.json()).results ?? []).length

		const response = await request.post(
			'/apps/openregister/api/flows/import/bpmn',
			{
				headers: { ...API_HEADERS, 'Content-Type': 'application/xml' },
				data: MALFORMED,
			},
		)

		expect(response.status()).toBe(422)

		const body = await response.json()
		expect(body.malformed).toBe(true)
		expect(body.element).toBe('sequenceFlow')
		expect(body.line).toBeGreaterThan(0)
		// 🔴 NO REPORT. A mapping report here would tell the author their
		// process is unsupported when what is wrong is their XML.
		expect(body.report).toBeUndefined()

		const after = await request.get('/apps/openregister/api/flows?limit=500')
		expect(((await after.json()).results ?? []).length).toBe(countBefore)
	})

	/**
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md#requirement-bpmn-import-accepts-a-documented-subset-and-reports-every-loss
	 * Scenario: Malformed and unsupported are two different answers
	 */
	test('a valid file we cannot fully express still imports, with the construct named', async ({
		request,
	}) => {
		const response = await request.post(
			'/apps/openregister/api/flows/import/bpmn',
			{
				headers: { ...API_HEADERS, 'Content-Type': 'application/xml' },
				data: VALID_BUT_UNSUPPORTED,
			},
		)

		expect(response.status()).toBe(201)

		const body = await response.json()
		if (body.flow?.id) {
			created.push(String(body.flow.id))
		}

		const refused = (body.report?.entries ?? []).filter(
			(entry: { verdict?: string }) => entry.verdict === 'refused',
		)

		expect(refused).toHaveLength(1)
		expect(refused[0].elementId).toBe('sub1')
	})

	/**
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md#requirement-a-flow-exports-to-conformant-bpmn-20-xml
	 */
	test('what the export hands back is a BPMN document, served as XML', async ({
		request,
	}) => {
		const made = await request.post('/apps/openregister/api/flows', {
			data: {
				name: `${RUN_ID} export`,
				nodes: [
					{
						id: 'start',
						name: 'Start',
						type: 'openregister.trigger-manual',
					},
				],
				edges: [],
			},
		})

		expect(made.ok()).toBeTruthy()
		const flow = await made.json()
		created.push(String(flow.id))

		const exported = await request.get(
			`/apps/openregister/api/flows/${flow.id}/bpmn`,
		)

		expect(exported.status()).toBe(200)
		expect(exported.headers()['content-type']).toContain('xml')
		expect(await exported.text()).toContain(
			'http://www.omg.org/spec/BPMN/20100524/MODEL',
		)
	})
})

test.describe('BPMN interchange refuses the anonymous caller', () => {
	// The least privileged principal that should be refused: nobody at all.
	// Import creates a flow, so an unauthenticated POST must never reach the
	// importer, let alone the store.
	test.use({
		storageState: NO_SESSION,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})

	/**
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md#requirement-bpmn-import-accepts-a-documented-subset-and-reports-every-loss
	 */
	test('an unauthenticated import is refused before anything is read', async ({
		request,
	}) => {
		const response = await request.post(
			'/apps/openregister/api/flows/import/bpmn',
			{
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/xml',
				},
				data: VALID_BUT_UNSUPPORTED,
			},
		)

		expect([401, 403]).toContain(response.status())
	})
})
