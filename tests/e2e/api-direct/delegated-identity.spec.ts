/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Delegated identity e2e — ADR-099, end to end against a live instance.
 *
 * WHAT THIS SUITE IS GUARDING AGAINST
 *
 * The defect class here is silent and it has bitten this subsystem repeatedly:
 * a run that executes as SOMEBODY, successfully, but as the wrong somebody.
 * Nothing goes red. The flow completes, objects are written, and the audit trail
 * names a person who never asked for any of it — historically whoever happened
 * to author the flow, because `flow.owner` was resolved implicitly at fire time.
 *
 * So an assertion of the form "the run completed" is worthless here, and so is
 * "the run was refused" on its own. Every refusal assertion below is paired with
 * a POSITIVE CONTROL proving the same call succeeds once an identity IS named —
 * otherwise a validator that rejects everything would pass this file, and a
 * validator that rejects everything is how you turn a privilege bug into an
 * outage and call it fixed.
 *
 * @spec openspec/specs/delegated-identity/spec.md
 * @spec openspec/specs/flow-engine/spec.md
 */
import { test, expect, type APIRequestContext } from '@playwright/test'

const RUN_ID = `e2e-ident-${Date.now().toString(36)}`

/** The uid every fixture runs as. Present on any dev instance. */
const ADMIN = process.env.NEXTCLOUD_ADMIN_USER || 'admin'

/** A uid that must NOT resolve. Used to prove the account check is real. */
const GHOST = 'e2e-no-such-user-9f3a1c'

/**
 * A real account that is NOT the caller.
 *
 * Distinct from GHOST on purpose: the account check and the GRANT check refuse
 * for different reasons, and a single fixture would let either one satisfy both
 * assertions. A uid that resolves and is not you is the only shape that isolates
 * the delegation rule.
 */
const OTHER = process.env.NEXTCLOUD_OTHER_USER || 'ddauth-alice'

interface FlowResponse {
	id: string
	uuid?: string
}

/**
 * Create a flow, returning its id.
 *
 * Failures include the response body: a bare "expected 201, got 400" on a flow
 * create sends the reader to the wrong layer, and the body always names the key.
 */
async function createFlow(
	request: APIRequestContext,
	overrides: Record<string, unknown>,
): Promise<FlowResponse> {
	const response = await request.post('/apps/openregister/api/flows', {
		data: {
			description: 'Created by the delegated-identity e2e suite.',
			trigger: 'manual',
			nodes: [],
			edges: [],
			...overrides,
			// AFTER the spread, deliberately. With `name` before it, an
			// `overrides.name` overwrote the prefixed value and the RUN_ID never
			// applied — so every run wrote flows called "schedule with identity"
			// into the instance, indistinguishable from each other and from
			// anything a person had made. The prefix exists for cleanup
			// isolation; putting it where a caller can silently defeat it made
			// the isolation decorative. Measured: two such flows left behind on
			// the dev instance before this was noticed.
			name: `${RUN_ID} ${String(overrides.name ?? 'flow')}`,
		},
	})

	expect(response.status(), await response.text()).toBe(201)

	return response.json() as Promise<FlowResponse>
}

/** A schedule trigger node, optionally declaring who its runs act as. */
function scheduleTrigger(runAs?: string): Record<string, unknown> {
	const config: Record<string, unknown> = { cron: '*/5 * * * *' }
	if (runAs !== undefined) {
		config.runAs = runAs
	}

	return {
		id: 'start',
		type: 'openregister.trigger-schedule',
		config,
		position: { x: 0, y: 0 },
	}
}

/** A terminal node, so the graph has no dead end to be refused for. */
const END_NODE = {
	id: 'done',
	type: 'openregister.end',
	config: { message: 'The flow completed.' },
	position: { x: 200, y: 0 },
}

// `from`/`to`, not `source`/`target`. FlowDefinitionBuilder reads the former;
// the latter is silently ignored, which presents as the dead-end refusal ("node
// start has no outgoing edge") rather than as an unknown-key error.
const EDGE_START_TO_END = { id: 'e1', from: 'start', to: 'done' }

test.describe('delegated-identity — a schedule trigger must name who it acts as', () => {
	test('POSITIVE CONTROL: a schedule trigger naming a real user saves', async ({
		request,
	}) => {
		// Without this, every refusal below is satisfied by a validator that
		// rejects all schedule triggers — which would look like a fix and be an
		// outage.
		const flow = await createFlow(request, {
			name: 'schedule with identity',
			nodes: [scheduleTrigger(ADMIN), END_NODE],
			edges: [EDGE_START_TO_END],
		})

		expect(
			flow.id,
			'a fully-specified schedule trigger must be storable',
		).toBeTruthy()

		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect(read.status()).toBe(200)

		const stored = await read.json()
		const trigger = (stored.nodes ?? []).find(
			(n: Record<string, unknown>) =>
				n.type === 'openregister.trigger-schedule',
		)
		expect(
			trigger,
			'the schedule trigger must survive the round trip',
		).toBeTruthy()
		expect(
			(trigger.config ?? {}).runAs,
			'the declared identity must be STORED, not silently dropped — a dropped '
				+ 'runAs reads as a saved flow that later refuses to fire',
		).toBe(ADMIN)
	})

	test('a schedule trigger with no runAs is refused at save', async ({
		request,
	}) => {
		const response = await request.post('/apps/openregister/api/flows', {
			data: {
				name: `${RUN_ID} schedule without identity`,
				trigger: 'manual',
				nodes: [scheduleTrigger(), END_NODE],
				edges: [EDGE_START_TO_END],
			},
		})

		expect(
			response.status(),
			'a schedule trigger naming nobody must not be storable',
		).toBeGreaterThanOrEqual(400)

		// The message has to name the KEY. "Invalid flow" sends an author back to
		// a canvas with no indication of which field is wrong, and the cron
		// expression on this node is perfectly valid.
		expect((await response.text()).toLowerCase()).toContain('runas')
	})

	test('a schedule trigger naming a non-existent user is refused at save', async ({
		request,
	}) => {
		const response = await request.post('/apps/openregister/api/flows', {
			data: {
				name: `${RUN_ID} schedule with ghost identity`,
				trigger: 'manual',
				nodes: [scheduleTrigger(GHOST), END_NODE],
				edges: [EDGE_START_TO_END],
			},
		})

		expect(
			response.status(),
			'a uid that resolves to no account is not an identity',
		).toBeGreaterThanOrEqual(400)
		expect(await response.text()).toContain(GHOST)
	})
})

test.describe('delegated-identity — naming somebody else needs a grant', () => {
	test('a schedule trigger naming another real user is refused without a grant', async ({
		request,
	}) => {
		// 🔴 The delegation rule, end to end. The account EXISTS — that is the
		// point. Refusing an unknown uid is an account check and was already
		// true; refusing a real colleague you hold no grant for is the rule this
		// change adds, and only a resolvable uid can tell the two apart.
		const response = await request.post('/apps/openregister/api/flows', {
			data: {
				name: `${RUN_ID} schedule naming a colleague`,
				trigger: 'manual',
				nodes: [scheduleTrigger(OTHER), END_NODE],
				edges: [EDGE_START_TO_END],
			},
		})

		expect(
			response.status(),
			'scheduling unattended runs as somebody else is a delegation and needs their consent',
		).toBeGreaterThanOrEqual(400)

		const body = (await response.text()).toLowerCase()
		expect(body).toContain(OTHER.toLowerCase())
		// The reason has to be in the message. "Not allowed" leaves the author
		// unable to tell "ask them" from "they said no" from "your grant ran
		// out", which are three different next steps.
		expect(body).toMatch(/none|denied|pending|revoked|expired|scope/)
	})

	test('POSITIVE CONTROL: the SAME trigger saves when it names the caller', async ({
		request,
	}) => {
		// Byte-for-byte the flow above with one field changed. Without this, the
		// refusal is satisfied by a validator that rejects every `runAs` — which
		// would break the ordinary case, a person scheduling their own flow,
		// while looking like a security fix.
		const flow = await createFlow(request, {
			name: 'schedule naming the caller',
			nodes: [scheduleTrigger(ADMIN), END_NODE],
			edges: [EDGE_START_TO_END],
		})

		expect(flow.id).toBeTruthy()
	})

	test('a self-named trigger carries no delegation stamp', async ({
		request,
	}) => {
		// `runAsDeclaredBy` is what the fire path re-resolves a grant against, so
		// a value surviving on a trigger that delegates nothing would be an
		// assertion no save-time check ever examined. The server writes it, and
		// strips it — this proves the stripping, including against a client that
		// supplies one.
		const flow = await createFlow(request, {
			name: 'forged stamp on a self-named trigger',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-schedule',
					config: {
						cron: '*/5 * * * *',
						runAs: ADMIN,
						runAsDeclaredBy: OTHER,
					},
					position: { x: 0, y: 0 },
				},
				END_NODE,
			],
			edges: [EDGE_START_TO_END],
		})

		const read = await request.get(`/apps/openregister/api/flows/${flow.id}`)
		expect(read.status()).toBe(200)

		const stored = await read.json()
		const trigger = (stored.nodes ?? []).find(
			(n: Record<string, unknown>) =>
				n.type === 'openregister.trigger-schedule',
		)

		expect(
			(trigger.config ?? {}).runAsDeclaredBy,
			'a stamp supplied by the client must not survive the save',
		).toBeUndefined()
		expect((trigger.config ?? {}).runAs).toBe(ADMIN)
	})
})

test.describe('delegated-identity — a run records what it acts as, separately from its cause', () => {
	test('a manual run acts as the caller and records both fields', async ({
		request,
	}) => {
		const flow = await createFlow(request, {
			name: 'manual attribution',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-manual',
					config: {},
					position: { x: 0, y: 0 },
				},
				END_NODE,
			],
			edges: [EDGE_START_TO_END],
		})

		const run = await request.post('/apps/openregister/api/flow-runs/test', {
			data: { flowId: flow.id },
		})
		expect(run.status(), await run.text()).toBe(200)

		const finished = await run.json()

		// Both fields must be present. They are equal here — a manual run is
		// caused by, and executes as, the same person — and the point of
		// asserting both is that a later scheduled run makes them differ.
		expect(finished.triggeredBy, 'provenance must be recorded').toBe(ADMIN)
		expect(
			finished.runAs,
			'the authorization subject must be recorded, not inferred at read time',
		).toBe(ADMIN)
	})

	test('the run reports the steps it actually took', async ({ request }) => {
		// Paired with the attribution assertions above for the reason this whole
		// suite exists: a run that executed NOTHING would satisfy every identity
		// assertion trivially, because there was no work to attribute.
		const flow = await createFlow(request, {
			name: 'observable work',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-manual',
					config: {},
					position: { x: 0, y: 0 },
				},
				END_NODE,
			],
			edges: [EDGE_START_TO_END],
		})

		const run = await request.post('/apps/openregister/api/flow-runs/test', {
			data: { flowId: flow.id },
		})
		expect(run.status(), await run.text()).toBe(200)

		const finished = await run.json()

		// `stopped`, not `completed`, is the terminal status when an `end` node
		// halts the walk — the end node reports `status: stopped` with its
		// configured reason. Both are terminal-and-successful; what distinguishes
		// a healthy run from the silent-skip bug is that STEPS RAN, so that is
		// what this asserts.
		expect(['completed', 'stopped']).toContain(finished.status)
		expect(
			(finished.log ?? []).length,
			'zero steps in a terminal run is the silent-skip failure mode',
		).toBeGreaterThan(0)

		// Every recorded step belongs to a run that named who it acted as. A step
		// log with no identity behind it is the state this whole change removes.
		expect(finished.runAs, 'work was done, so an identity must own it').toBe(
			ADMIN,
		)
	})
})

test.describe('delegated-identity — the acting identity cannot be chosen by the caller', () => {
	test('a caller-supplied runAs in the run context is ignored', async ({
		request,
	}) => {
		// 🔴 The security property. Context is caller-supplied, so honouring a
		// `runAs` in it would let anyone who can start a flow choose the identity
		// its steps execute as. Identity narrows along an invocation chain and
		// only widens through a grant checked against the caller — never through
		// a key in a payload.
		const flow = await createFlow(request, {
			name: 'context override attempt',
			nodes: [
				{
					id: 'start',
					type: 'openregister.trigger-manual',
					config: {},
					position: { x: 0, y: 0 },
				},
				END_NODE,
			],
			edges: [EDGE_START_TO_END],
		})

		const run = await request.post('/apps/openregister/api/flow-runs/test', {
			data: {
				flowId: flow.id,
				context: { runAs: GHOST },
			},
		})
		expect(run.status(), await run.text()).toBe(200)

		const finished = await run.json()
		expect(
			finished.runAs,
			'the run decides who it acts as; a caller-supplied context must not',
		).toBe(ADMIN)
		expect(finished.runAs).not.toBe(GHOST)
	})
})
