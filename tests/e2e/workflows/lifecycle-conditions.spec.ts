import type { APIRequestContext } from '@playwright/test'
import type { SeededRegister, SeededSchema } from '../_fixtures.ts'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Declarative JSONLogic `condition` on an x-openregister-lifecycle transition,
 * exercised end to end through the real save path.
 *
 * WHY THIS IS AN API SPEC RATHER THAN A BROWSER ONE
 * -------------------------------------------------
 * The rule being tested is a REFUSAL on write. A browser spec would drive a
 * form and assert a toast, which tests the form. The thing that must hold is
 * that no request of any shape can move an object into a state whose condition
 * does not hold — including a raw PUT that never touches the UI. That is the
 * direction the defect would take, so that is the direction the test comes
 * from.
 *
 * WHAT WOULD PASS WITHOUT THE FEATURE
 * -----------------------------------
 * Nothing here. Every assertion below fails on a build that ignores
 * `condition`: the refusals return 200 instead of 4xx, and the object comes
 * back in the state the condition was written to forbid. The final assertion
 * re-reads the object precisely so a refusal that returns the right status and
 * writes anyway cannot pass.
 *
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
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
	updateSchema,
} from '../_fixtures.ts'

// `_fixtures.ts` keeps these module-private, and the shared helpers all assert
// a success status. The refusal paths below need the raw response, so the two
// constants are restated here rather than widening the fixtures' surface.
const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}

const runId = makeRunId()

/** The bezwaar shape: a status field plus the motivation a decision requires. */
const PROPERTIES = {
	status: {
		type: 'string',
		enum: ['in-behandeling', 'besloten', 'ingetrokken'],
	},
	motivering: { type: 'string' },
}

/**
 * A decision requires a motivation. One line of JSONLogic, where the fleet
 * previously needed a LifecycleGuardInterface class per rule.
 */
const LIFECYCLE = {
	field: 'status',
	initial: 'in-behandeling',
	transitions: {
		beslissen: {
			from: ['in-behandeling'],
			to: 'besloten',
			condition: { '!!': { var: 'object.motivering' } },
			message: {
				nl: 'Een besluit vereist een motivering.',
				en: 'A decision requires a motivation.',
			},
		},
		intrekken: {
			from: ['in-behandeling'],
			to: 'ingetrokken',
		},
	},
}

test.describe('lifecycle transition conditions', () => {
	let register: SeededRegister
	let schema: SeededSchema

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, runId)
		schema = await createSchema(request, runId, 'bezwaar', PROPERTIES)
		await linkSchemaToRegister(request, register, schema)
		await updateSchema(request, schema, PROPERTIES, {
			configuration: { 'x-openregister-lifecycle': LIFECYCLE },
		})
	})

	// Teardown lives in afterAll rather than at the end of a test body so it
	// still runs when an assertion fails — a failed run must not leave fixture
	// registers behind for the next one to trip over.
	test.afterAll(async ({ request }) => {
		if (schema?.id) {
			await deleteSchema(request, schema.id)
		}
		if (register?.id) {
			await deleteRegister(request, register.id)
		}
	})

	test('a transition whose condition does not hold is refused', async ({ request }) => {
		const created = await createObject(request, register.id, schema.id, {
			status: 'in-behandeling',
		})
		const uuid = objectId(created)
		expect(uuid, 'created object has an id').toBeTruthy()

		const resp = await request.put(
			`${API}/objects/${register.id}/${schema.id}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: { status: 'besloten' },
			},
		)

		expect(resp.status(), 'a decision without a motivation is refused').toBe(422)

		const body = await resp.json()
		const serialised = JSON.stringify(body)
		expect(serialised, 'the refusal names its code').toContain(
			'lifecycle-condition-unmet',
		)
		expect(serialised, 'the refusal carries the author message').toContain(
			'motivering',
		)
		expect(serialised, 'the refusal never leaks the expression').not.toContain(
			'"var"',
		)

		// The status code is only half the claim. A refusal that returns 422
		// and writes anyway is the failure this re-read exists to catch.
		// `getObject` returns an {status, body} envelope, so the lifecycle
		// field is read off `body` — reading `.status` here would assert the
		// HTTP code against a state name and pass for the wrong reason.
		const after = await getObject(request, register.id, schema.id, uuid as string)
		expect(after.status, 'the object is still readable').toBe(200)
		expect(after.body?.status, 'the refused write did not land').toBe(
			'in-behandeling',
		)
	})

	test('the same transition passes once its condition holds', async ({ request }) => {
		const created = await createObject(request, register.id, schema.id, {
			status: 'in-behandeling',
		})
		const uuid = objectId(created)

		const resp = await request.put(
			`${API}/objects/${register.id}/${schema.id}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: { status: 'besloten', motivering: 'Het bezwaar is ongegrond.' },
			},
		)

		expect(resp.status(), 'a motivated decision is allowed').toBe(200)

		const after = await getObject(request, register.id, schema.id, uuid as string)
		expect(after.body?.status, 'the allowed write landed').toBe('besloten')
	})

	test('the named-action route refuses the same transition', async ({ request }) => {
		// The other way in. `TransitionEngine::transition()` does not check
		// conditions itself; it reaches the same listener through saveObject.
		// If that ever stopped being true, this route would silently become a
		// way around a rule the direct save enforces, which is exactly the
		// shape of hole worth a test of its own.
		//
		// Note the different response shape: TransitionController surfaces
		// HookStoppedException::getMessage(), so the wire carries the author's
		// message and NOT the `lifecycle-condition-unmet` code.
		const created = await createObject(request, register.id, schema.id, {
			status: 'in-behandeling',
		})
		const uuid = objectId(created)

		const resp = await request.post(`${API}/objects/${uuid}/transition`, {
			headers: JSON_HEADERS,
			data: { action: 'beslissen' },
		})

		expect(resp.status(), 'the named action is refused too').toBe(422)
		expect(JSON.stringify(await resp.json())).toContain('motivering')

		const after = await getObject(request, register.id, schema.id, uuid as string)
		expect(after.body?.status, 'the refused action did not land').toBe(
			'in-behandeling',
		)
	})

	test('a transition declaring no condition is unaffected', async ({ request }) => {
		// The regression guard. Conditions are additive, so the transition that
		// declares none must behave exactly as it did before the feature.
		const created = await createObject(request, register.id, schema.id, {
			status: 'in-behandeling',
		})
		const uuid = objectId(created)

		const resp = await request.put(
			`${API}/objects/${register.id}/${schema.id}/${uuid}`,
			{
				headers: JSON_HEADERS,
				data: { status: 'ingetrokken' },
			},
		)

		expect(resp.status(), 'an unconditioned transition still passes').toBe(200)
	})

	test('a malformed condition cannot be stored', async ({ request }) => {
		// 🔴 THE FAIL-OPEN CASE. This string is the dialect an `actions[]` entry
		// uses. FlowExpression::isValid() accepts any scalar as a literal, so
		// without the rule-object check it would store cleanly and then evaluate
		// truthy — authorising every transition it was written to block. The
		// schema save must refuse it.
		const resp = await request.put(`${API}/schemas/${schema.id}`, {
			headers: JSON_HEADERS,
			data: {
				slug: schema.slug,
				title: schema.title,
				properties: PROPERTIES,
				configuration: {
					'x-openregister-lifecycle': {
						...LIFECYCLE,
						transitions: {
							...LIFECYCLE.transitions,
							beslissen: {
								from: ['in-behandeling'],
								to: 'besloten',
								condition: "@self.motivering == 'ja'",
							},
						},
					},
				},
			},
		})

		expect(
			resp.status(),
			'a scalar condition is refused at schema-save time',
		).toBeGreaterThanOrEqual(400)
		expect(JSON.stringify(await resp.json())).toContain(
			'lifecycle-condition-malformed',
		)
	})
})

/*
 * The shipped worked example, exercised at runtime.
 *
 * The mock register is what a developer imports to see the platform work, and
 * its `dataSubjectRequest.refuse` transition is the reference example for
 * declarative conditions. This block takes that transition VERBATIM from the
 * file, condition and message included, so the example in the register and
 * the behaviour under test cannot drift apart: edit the example and this test
 * exercises the edit.
 *
 * Only the two fields the condition reads are declared, so the test does not
 * depend on the rest of the DSAR schema's required fields.
 */
const MOCK_REGISTER = JSON.parse(
	fs.readFileSync(
		path.resolve(__dirname, '..', '..', '..', 'lib', 'Settings', 'openregister_mock_register.json'),
		'utf-8',
	),
)
const DSAR = MOCK_REGISTER.components.schemas.dataSubjectRequest
const DSAR_LIFECYCLE = DSAR['x-openregister-lifecycle']
const REFUSE = DSAR_LIFECYCLE.transitions.refuse

test.describe('the mock register refuse example', () => {
	let register: SeededRegister
	let schema: SeededSchema
	const properties = {
		[DSAR_LIFECYCLE.field]: DSAR.properties[DSAR_LIFECYCLE.field],
		denialGround: DSAR.properties.denialGround,
	}

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, runId, 'dsar-reg')
		schema = await createSchema(request, runId, 'dsar', properties)
		await linkSchemaToRegister(request, register, schema)
		await updateSchema(request, schema, properties, {
			configuration: {
				'x-openregister-lifecycle': {
					field: DSAR_LIFECYCLE.field,
					initial: DSAR_LIFECYCLE.initial,
					transitions: { refuse: REFUSE },
				},
			},
		})
	})

	test.afterAll(async ({ request }) => {
		if (schema?.id) {
			await deleteSchema(request, schema.id)
		}
		if (register?.id) {
			await deleteRegister(request, register.id)
		}
	})

	/** Attempt `refuse` with the given denial ground; returns the response. */
	async function attemptRefuse(
		request: APIRequestContext,
		denialGround?: string,
	) {
		const created = await createObject(request, register.id, schema.id, {
			[DSAR_LIFECYCLE.field]: DSAR_LIFECYCLE.initial,
		})
		const uuid = objectId(created) as string
		const data: Record<string, unknown> = { [DSAR_LIFECYCLE.field]: REFUSE.to }
		if (denialGround !== undefined) {
			data.denialGround = denialGround
		}
		const resp = await request.put(
			`${API}/objects/${register.id}/${schema.id}/${uuid}`,
			{ headers: JSON_HEADERS, data },
		)
		return { resp, uuid }
	}

	test('refusing without a denial ground is refused, with the shipped message', async ({ request }) => {
		const { resp, uuid } = await attemptRefuse(request)

		expect(resp.status()).toBe(422)
		const serialised = JSON.stringify(await resp.json())
		// The caller's language decides which entry of the map is used, so
		// accept either declared translation, but nothing else.
		const declared = Object.values(REFUSE.message) as string[]
		expect(
			declared.some(text => serialised.includes(text)),
			`the refusal carries one of the shipped messages: ${declared.join(' | ')}`,
		).toBe(true)

		const after = await getObject(request, register.id, schema.id, uuid)
		expect(after.body?.[DSAR_LIFECYCLE.field]).toBe(DSAR_LIFECYCLE.initial)
	})

	test('refusing with the not-applicable ground is refused', async ({ request }) => {
		const { resp } = await attemptRefuse(request, 'not-applicable')
		expect(resp.status()).toBe(422)
	})

	test('refusing with a real ground is allowed', async ({ request }) => {
		const { resp, uuid } = await attemptRefuse(request, 'manifestly-unfounded')
		expect(resp.status()).toBe(200)

		const after = await getObject(request, register.id, schema.id, uuid)
		expect(after.body?.[DSAR_LIFECYCLE.field]).toBe(REFUSE.to)
	})
})
