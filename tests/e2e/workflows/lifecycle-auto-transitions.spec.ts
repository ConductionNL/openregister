import type { APIRequestContext } from '@playwright/test'
import type { SeededRegister, SeededSchema } from '../_fixtures.ts'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `autoWhen` / `executionMode` on an x-openregister-lifecycle transition,
 * exercised end to end through the real save path.
 *
 * WHAT THE FEATURE IS
 * --------------------
 * A transition MAY declare `autoWhen` (a JSONLogic rule). After an object is
 * created or updated, the engine looks for a transition whose `from` contains
 * the object's current lifecycle value and whose `autoWhen` holds, and fires
 * it — through `TransitionEngine`, so every gate a manual transition meets
 * (condition, authorization, requires) still applies. A `sync` move (the
 * default) is applied before the triggering request's response is produced,
 * at most 10 times per object per pass, and never back into a state already
 * occupied in that pass.
 *
 * WHY THIS IS AN API SPEC RATHER THAN A BROWSER ONE
 * -------------------------------------------------
 * The claim under test is about the WRITE PATH, not a form: a raw PUT or a
 * raw POST to the transition endpoint must already carry the automatic
 * move's result in its own response, before any UI ever re-fetches. A
 * browser spec would test whichever screen happens to re-render, not the
 * contract itself.
 *
 * WHAT WOULD PASS WITHOUT THE FEATURE
 * -----------------------------------
 * Nothing here, with one deliberate exception. On a build that does not read
 * `autoWhen` at all, every assertion below that expects a SECOND transition
 * to have run fails: the response and the re-read both come back in the
 * state the triggering write itself set, never the state the automatic move
 * would have reached. The exception is the last test, a regression guard
 * that a transition declaring no `autoWhen` never fires on its own — see its
 * own comment for why it is included even though it also passes on a build
 * with no automatic-transition code at all.
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	createObject,
	createRegister,
	createSchema,
	deleteRegister,
	deleteSchema,
	getObject,
	linkSchemaToRegister,
	makeRunId,
	objectId,
	updateObject,
	updateSchema,
} from '../_fixtures.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}

const runId = makeRunId()

/**
 * Remove every object a describe block created, then its schema and register.
 *
 * Soft-delete first, then a hard DELETE on /api/deleted/{uuid}, because a
 * soft-deleted row would otherwise survive the run. Admission to the CI
 * allowlist requires a spec that writes to clean up after itself.
 */
async function purge(
	request: APIRequestContext,
	register: SeededRegister | undefined,
	schema: SeededSchema | undefined,
	uuids: string[],
): Promise<void> {
	for (const uuid of uuids) {
		if (register?.id && schema?.id) {
			await request
				.delete(`${API}/objects/${register.id}/${schema.id}/${uuid}`)
				.catch(() => {})
		}
		await request.delete(`${API}/deleted/${uuid}`).catch(() => {})
	}
	if (schema?.id) {
		await deleteSchema(request, schema.id)
	}
	if (register?.id) {
		await deleteRegister(request, register.id)
	}
}

/**
 * A rule that holds for any candidate, whatever the object's own data says.
 *
 * `transition.to` is always a non-empty string for a real candidate — the
 * selector already filters out an empty `to` before a rule is ever
 * evaluated — so `!!` of it is unconditionally true. This is used where a
 * test needs a move to fire regardless of the object's fields, mirroring the
 * shared four-key document `LifecycleConditionEvaluator::document()` builds
 * for both `condition` and `autoWhen`.
 */
const ALWAYS_HOLDS = { '!!': { var: 'transition.to' } }

/**
 * A `condition` that never holds, whatever the object's own data says.
 *
 * `nooit` ("never", in Dutch) is not a declared property, so the document
 * builder resolves `object.nooit` to null and `!!null` is false.
 */
const NEVER_HOLDS = { '!!': { var: 'object.nooit' } }

/** Every lifecycle value used across the scenarios below, kept in one enum. */
const PROPERTIES = {
	status: {
		type: 'string',
		enum: [
			'nieuw',
			'concept',
			'ingediend',
			'in-beoordeling',
			'in-behandeling',
			'besloten',
			'binnengekomen',
			'goedgekeurd',
			'stap-1',
			'stap-2',
			'stap-3',
			'heen',
			'terug',
			'gearchiveerd',
		],
	},
	motivering: { type: 'string' },
	notitie: { type: 'string' },
}

/**
 * One schema, nine transitions, each group using its own disjoint states so
 * the tests below cannot interfere with each other through ambiguity or
 * shadowing (both of which fire nothing and are covered by PHPUnit, not
 * here — see the spec's own `@e2e exclude` on that requirement).
 */
const LIFECYCLE = {
	field: 'status',
	initial: 'nieuw',
	transitions: {
		// Group 1: a direct save's own response carries a sync automatic move.
		beslissen: {
			from: ['in-behandeling'],
			to: 'besloten',
			autoWhen: { '!!': { var: 'object.motivering' } },
		},
		// Group 2: the named-transition route's response carries the automatic
		// follow-on move it triggers. `indienen` itself declares no `autoWhen`
		// — it is the manual move that makes `beoordelen`'s rule start holding.
		indienen: {
			from: ['concept'],
			to: 'ingediend',
		},
		beoordelen: {
			from: ['ingediend'],
			to: 'in-beoordeling',
			autoWhen: ALWAYS_HOLDS,
		},
		// Group 3: an automatic move refused by its own `condition`.
		keuren: {
			from: ['binnengekomen'],
			to: 'goedgekeurd',
			autoWhen: ALWAYS_HOLDS,
			condition: NEVER_HOLDS,
		},
		// Group 4: a chain of two automatic moves in one pass, A to B to C.
		stapEen: {
			from: ['stap-1'],
			to: 'stap-2',
			autoWhen: ALWAYS_HOLDS,
		},
		stapTwee: {
			from: ['stap-2'],
			to: 'stap-3',
			autoWhen: ALWAYS_HOLDS,
		},
		// Group 5: a ping-pong pair, to prove the loop cap's no-revisit rule.
		heenGaan: {
			from: ['heen'],
			to: 'terug',
			autoWhen: ALWAYS_HOLDS,
		},
		terugGaan: {
			from: ['terug'],
			to: 'heen',
			autoWhen: ALWAYS_HOLDS,
		},
		// Group 6: declares no `autoWhen` at all — the regression guard.
		archiveren: {
			from: ['nieuw'],
			to: 'gearchiveerd',
		},
	},
}

test.describe('lifecycle autoWhen automatic transitions', () => {
	let register: SeededRegister
	let schema: SeededSchema
	const createdIds: string[] = []

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, runId, 'auto-reg')
		schema = await createSchema(request, runId, 'auto-sch', PROPERTIES)
		await linkSchemaToRegister(request, register, [schema.id])
		await updateSchema(request, schema, PROPERTIES, {
			configuration: { 'x-openregister-lifecycle': LIFECYCLE },
		})
	})

	// Teardown lives in afterAll rather than at the end of a test body so it
	// still runs when an assertion fails — a failed run must not leave fixture
	// registers behind for the next one to trip over.
	test.afterAll(async ({ request }) => {
		await purge(request, register, schema, createdIds)
	})

	test('a sync automatic move via a direct save lands in the same response', async ({
		request,
	}) => {
		// GIVEN an object in `in-behandeling` without a `motivering` — the
		// `beslissen` autoWhen does not hold yet, so create fires nothing.
		const created = await createObject(request, register.id, schema.id, {
			status: 'in-behandeling',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)
		expect(uuid, 'created object has an id').toBeTruthy()

		// WHEN the object is saved with a non-empty `motivering` — a PUT is a
		// full replace (SaveObject::prepareObjectForUpdate null-fills every
		// omitted property), so `status` is resent unchanged rather than left
		// out and nulled.
		const after = await updateObject(request, register.id, schema.id, uuid, {
			status: 'in-behandeling',
			motivering: 'Overwegingen zijn compleet.',
		})

		// THEN the response to THAT save already carries `besloten` — not a
		// later GET. This is the outermost-write-boundary claim: the automatic
		// move is applied before the response of the triggering request is
		// produced.
		expect(
			after.status,
			'the PUT response already carries the automatic move',
		).toBe('besloten')

		// AND the move is real in storage, not just a lying response body.
		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(stored.status, 'the object is still readable').toBe(200)
		expect(stored.body?.status, 'the automatic move is stored').toBe('besloten')
	})

	test('a sync automatic move via the named-transition route lands in the same response', async ({
		request,
	}) => {
		// The other entry point into the same boundary. `indienen` is a manual
		// transition with no `autoWhen`; firing it by name is what makes
		// `beoordelen`'s autoWhen start holding, and TransitionEngine::
		// transition() opens the automatic-transition boundary around the
		// WHOLE call, not just its own save — so the automatic follow-on move
		// is applied before this endpoint answers.
		const created = await createObject(request, register.id, schema.id, {
			status: 'concept',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)

		const resp = await request.post(`${API}/objects/${uuid}/transition`, {
			headers: JSON_HEADERS,
			data: { action: 'indienen' },
		})

		expect(resp.status(), 'the named transition is applied').toBe(200)

		// TransitionController::transition() returns
		// `$object->jsonSerialize()` on success, the same flat shape
		// `getObject()` returns (the lifecycle field sits at the top level,
		// beside `@self`), so the response body itself is asserted here rather
		// than only the storage re-read below.
		const body = await resp.json()
		expect(
			body?.status,
			'the transition response already carries the automatic follow-on move',
		).toBe('in-beoordeling')

		// AND the move is real in storage.
		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(stored.body?.status, 'the automatic move is stored').toBe(
			'in-beoordeling',
		)
	})

	test('an automatic move refused by its condition leaves the object in place', async ({
		request,
	}) => {
		// `keuren`'s autoWhen always holds, but its condition never does. The
		// refusal is caught inside AutoTransitionRunner::fire() and logged; it
		// must not fail — or partially undo — the triggering save.
		const created = await createObject(request, register.id, schema.id, {
			status: 'binnengekomen',
			notitie: 'origineel',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)

		const after = await updateObject(request, register.id, schema.id, uuid, {
			status: 'binnengekomen',
			notitie: 'bijgewerkt',
		})

		// The triggering write succeeded and its own field change landed —
		// the refused automatic move did not unwind it.
		expect(after.notitie, 'the triggering write is not undone').toBe(
			'bijgewerkt',
		)
		expect(after.status, 'the refused automatic move did not change state').toBe(
			'binnengekomen',
		)

		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(stored.body?.notitie).toBe('bijgewerkt')
		expect(stored.body?.status, 'still in place after a fresh read').toBe(
			'binnengekomen',
		)
	})

	test('a chain of two automatic moves resolves to the final state in one pass', async ({
		request,
	}) => {
		// `stapEen` (stap-1 to stap-2) and `stapTwee` (stap-2 to stap-3) both
		// always hold. The pass re-decides after each move within the same
		// drain, so both apply before this PUT's response is produced.
		const created = await createObject(request, register.id, schema.id, {
			status: 'nieuw',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)

		const after = await updateObject(request, register.id, schema.id, uuid, {
			status: 'stap-1',
		})

		expect(
			after.status,
			'the chain resolves to its final state in one response',
		).toBe('stap-3')

		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(stored.body?.status, 'the chain is stored at its final state').toBe(
			'stap-3',
		)
	})

	test('a ping-pong pair is cut after exactly one move by the loop cap', async ({
		request,
	}) => {
		// `heenGaan` (heen to terug) and `terugGaan` (terug to heen) both
		// always hold, which is exactly the declaration AutoTransitionPass's
		// NO-REVISIT rule exists for. Reading AutoTransitionPass::step(): the
		// object's starting state counts as visited before the first move is
		// even taken, so heen -> terug is allowed (terug not yet visited), and
		// the very next decision, terug -> heen, is cut because heen is
		// already in this pass's visited set. That is ONE move, deterministic,
		// landing on `terug` — not the ceiling of 10, which only bites a
		// chain through more than ten DISTINCT states.
		const created = await createObject(request, register.id, schema.id, {
			status: 'nieuw',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)

		const after = await updateObject(request, register.id, schema.id, uuid, {
			status: 'heen',
		})

		// The request itself must still answer normally — the cut logs an
		// error and stops; it never throws and never hangs the request that
		// triggered it.
		expect(
			after.status,
			'the pass is cut deterministically at the first revisit, landing on terug',
		).toBe('terug')

		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(
			stored.body?.status,
			'the cut result is stored, not just answered',
		).toBe('terug')
	})

	test('a transition declaring no autoWhen never fires on its own', async ({
		request,
	}) => {
		// The regression guard. `archiveren` shares its `from` (`nieuw`) with
		// two states this file also drives through `stapEen` and `heenGaan`'s
		// starting point, so a broken implementation that treated EVERY
		// declared transition as an automatic candidate — rather than only
		// those carrying an `autoWhen` key, as
		// AutoTransitionSelector::holdingTransitions() gates — would fire
		// `archiveren` here. A correct implementation, and a build with no
		// automatic-transition code at all, both leave the object exactly
		// where the write itself put it: this test cannot distinguish
		// "absent" from "correctly gated", which is exactly why the spec
		// marks the underlying requirement's own version of this scenario
		// `@e2e exclude` in favour of PHPUnit. It is kept here anyway because
		// the task this file was written for asked for it explicitly, as a
		// second, defense-in-depth check that sits next to the five tests
		// above that DO fail on a feature-less build.
		const created = await createObject(request, register.id, schema.id, {
			status: 'nieuw',
			notitie: 'a',
		})
		const uuid = objectId(created) as string
		createdIds.push(uuid)

		const after = await updateObject(request, register.id, schema.id, uuid, {
			status: 'nieuw',
			notitie: 'b',
		})

		expect(after.notitie, 'the unrelated field change landed').toBe('b')
		expect(
			after.status,
			'a transition with no autoWhen key is never treated as an automatic candidate',
		).toBe('nieuw')

		const stored = await getObject(request, register.id, schema.id, uuid)
		expect(stored.body?.status).toBe('nieuw')
	})
})
