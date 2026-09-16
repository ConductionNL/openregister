import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CONFIGURATION AS A DEPLOYMENT — end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/configuration-as-a-deployment/specs/` is archived into
 * `openspec/specs/`:
 *
 * @e2e configuration-deployment::an-edit-does-not-take-effect-yet
 * @e2e configuration-deployment::a-broken-weekend-is-undone-in-one-act
 * @e2e configuration-deployment::a-partial-deployment-does-not-happen
 * @e2e configuration-deployment::why-does-this-instance-behave-like-this
 * @e2e configuration-deployment::two-hundred-case-types-stay-in-step
 * @e2e configuration-deployment::an-exception-is-visible-as-an-exception
 * @e2e configuration-deployment::a-copied-matrix-is-reviewed-before-it-is-live
 * @e2e settings-management::a-new-instance-gets-a-working-vocabulary-to-review
 *
 * WHAT THIS FILE CAN PROVE, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is REACHABLE: the twelve routes are registered, a
 * draft leaves the live value alone, a preview answers in the shape an
 * administrator reads, a deployment records both sides of every value it moved,
 * a rollback restores them as a new record, and a set whose value has moved
 * underneath it refuses and applies NOTHING. Those are the failures a green
 * unit suite hides — a route missing from `appinfo/routes.php` is a 404 no
 * PHPUnit test would notice.
 *
 * Two spec scenarios carry an `@e2e exclude` instead, for reasons that are
 * about the door and not about the assertion:
 *
 * - "four eyes are required" turns on the instance flag `configuration_four_eyes`,
 *   and that flag is deliberately RESERVED: it cannot travel inside a
 *   deployment, precisely so a set cannot approve itself by switching it off on
 *   the way in. There is therefore no HTTP door to turn it on, and there should
 *   not be. Asserted in
 *   tests/Unit/Service/ConfigurationDeployment/ConfigurationDraftServiceTest.php
 *   (testFourEyesAreRequired) and again at deploy time in
 *   tests/Unit/Service/ConfigurationDeployment/DeploymentServiceTest.php
 *   (testUnderFourEyesAnAuthorCannotDeployTheirOwnSet).
 *
 * - "an older value is named honestly" needs a value that predates the first
 *   deployment. Over HTTP every value this spec can reach is one it created, so
 *   the assertion would be about its own fixture rather than about an older
 *   instance. Asserted in
 *   tests/Unit/Service/ConfigurationDeployment/ConfigurationExplainerTest.php
 *   (testAnOlderValueIsNamedHonestly).
 *
 * SAFE BY CONSTRUCTION. Every address it touches is at the REGISTER layer under
 * a reference unique to the run (`e2e-cfg-<run>`), and the key is under the open
 * `integration.` prefix. It never writes an instance setting, so it cannot move
 * RBAC, retention, Solr or anything else a person or another suite depends on.
 *
 * WHAT IT LEAVES BEHIND, SAID PLAINLY. Deployment rows, and the draft sets they
 * published. There is no delete route for either, on purpose: an append-only
 * history that can be pruned is not a history. They aggregate onto nothing a
 * person reads, and they name a register reference that exists only for this
 * run. The configuration values themselves are removed by the rollback the last
 * test performs.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so parallel runs never collide on an address. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const SETS = `${API}/configuration/draft-sets`
const DEPLOYMENTS = `${API}/configuration/deployments`
const EFFECTIVE = `${API}/configuration/effective`

/* The register reference every address in this spec hangs off. Nothing reads
 * it, which is the point: the suite must not be able to move a live setting. */
const LAYER_REF = `e2e-cfg-${RUN}`
const KEY = 'integration.mail'

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

test.describe('configuration as a deployment over HTTP', () => {
	let admin: APIRequestContext

	/** Open a draft set and return its uuid. */
	async function openSet(name: string): Promise<string> {
		const res = await admin.post(SETS, { data: { name, description: 'e2e' } })
		expect(res.status(), `opening a set failed: ${await res.text()}`).toBe(201)

		return String((await res.json()).uuid)
	}

	/** Draft one value into a set, at the run's own register address. */
	async function draft(setUuid: string, value: Record<string, unknown>): Promise<void> {
		const res = await admin.post(`${SETS}/${setUuid}/values`, {
			data: { layer: 'register', layerRef: LAYER_REF, key: KEY, value },
		})
		expect(res.status(), `drafting a value failed: ${await res.text()}`).toBe(201)
	}

	/** What the explainer answers for the run's address. */
	async function effective(): Promise<Record<string, unknown>> {
		const res = await admin.get(`${EFFECTIVE}?key=${KEY}&register=${LAYER_REF}`)
		expect(res.ok(), `the explainer failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()) as Record<string, unknown>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		/* A seed that silently did not run must say so HERE, not later inside a
		 * refusal assertion where it would read as the feature misbehaving. */
		const reach = await admin.get(SETS)
		expect(
			reach.status(),
			`the administrator account cannot reach the configuration surface (${reach.status()})`,
		).toBeLessThan(400)
	})

	test('an edit does not take effect yet', async () => {
		const setUuid = await openSet(`e2e drafting ${RUN}`)
		await draft(setUuid, { relay: 'drafted-never-deployed' })

		const answer = await effective()

		/* THE WHOLE POINT. The draft exists, and the live configuration has not
		 * moved: nothing sets this address yet. */
		expect(answer.found, 'a draft was applied to the live configuration').toBe(false)
		expect(answer.value, 'a draft leaked into the effective value').toBeNull()

		/* And the set still holds the pending value, so "not applied" is not
		 * "quietly dropped". */
		const read = await admin.get(`${SETS}/${setUuid}`)
		const body = (await read.json()) as Record<string, unknown>
		const drafts = body.drafts as Array<Record<string, unknown>>
		expect(drafts, 'the set lost its pending value').toHaveLength(1)
		expect((drafts[0].value as Record<string, unknown>).relay).toBe('drafted-never-deployed')
	})

	test('a preview says what would change, and a deployment records both sides', async () => {
		const setUuid = await openSet(`e2e first deployment ${RUN}`)
		await draft(setUuid, { relay: 'smtp-one' })

		const previewRes = await admin.get(`${SETS}/${setUuid}/preview`)
		const preview = (await previewRes.json()) as Record<string, unknown>
		const counts = preview.counts as Record<string, number>

		expect(preview.deployable, `the preview refused: ${JSON.stringify(preview.refusals)}`).toBe(true)
		expect(counts.toChange, 'the preview does not count the change').toBe(1)
		expect(counts.refused).toBe(0)

		const changes = preview.changes as Array<Record<string, unknown>>
		expect(changes[0].status, 'a key nothing held yet is a create, not a change').toBe('create')

		const deployRes = await admin.post(`${SETS}/${setUuid}/deploy`, {
			data: { name: `e2e first deployment ${RUN}` },
		})
		expect(deployRes.status(), `deploy failed: ${await deployRes.text()}`).toBe(201)

		const deployment = (await deployRes.json()) as Record<string, unknown>
		const recorded = deployment.changes as Array<Record<string, unknown>>

		expect(String(deployment.uuid ?? ''), 'the deployment was not recorded').toMatch(/[0-9a-f-]{36}/)
		expect(deployment.deployedBy, 'the deployment does not name who deployed it').toBeTruthy()
		expect(deployment.deployedAt, 'the deployment does not carry a time').toBeTruthy()
		expect(recorded, 'the deployment did not record the value it moved').toHaveLength(1)
		/* The half a rollback is made of: what was there BEFORE. */
		expect(recorded[0].previousPresent, 'the address held nothing before').toBe(false)
		expect((recorded[0].value as Record<string, unknown>).relay).toBe('smtp-one')
	})

	test('why does this instance behave like this', async () => {
		const answer = await effective()

		expect(answer.found).toBe(true)
		expect((answer.value as Record<string, unknown>).relay).toBe('smtp-one')
		/* All three answers from one read (D-4): value, layer and deployment. */
		expect(answer.layer, 'the explainer does not name the layer').toBe('register')
		expect(answer.layerRef).toBe(LAYER_REF)
		expect(
			answer.predatesFirstDeployment,
			'a value this run deployed cannot predate the first deployment',
		).toBe(false)

		const deployment = answer.deployment as Record<string, unknown>
		expect(deployment, 'the explainer does not name the deployment').toBeTruthy()
		expect(String(deployment.name ?? ''), 'the deployment is unnamed').toContain(RUN)
		expect(String(answer.explanation ?? '')).toContain('register')
	})

	test('a partial deployment does not happen', async () => {
		/* A set taken against the value as it stands now. */
		const staleSet = await openSet(`e2e stale ${RUN}`)
		await draft(staleSet, { relay: 'smtp-from-a-stale-draft' })

		/* Somebody else moves the same address in between. */
		const interloper = await openSet(`e2e interloper ${RUN}`)
		await draft(interloper, { relay: 'smtp-two' })
		const interloperDeploy = await admin.post(`${SETS}/${interloper}/deploy`, {
			data: { name: `e2e interloper ${RUN}` },
		})
		expect(interloperDeploy.status()).toBe(201)

		/* The first set now refuses, and says which value refused. */
		const preview = (await (await admin.get(`${SETS}/${staleSet}/preview`)).json()) as Record<string, unknown>
		const refusals = preview.refusals as Array<Record<string, unknown>>

		expect(preview.deployable, 'a set taken against a moved value must not deploy').toBe(false)
		expect(refusals, 'the preview names no refusal').toHaveLength(1)
		expect(refusals[0].refusal).toBe('stale-draft')
		expect(refusals[0].key).toBe(KEY)

		const deployRes = await admin.post(`${SETS}/${staleSet}/deploy`, { data: {} })
		const body = (await deployRes.json()) as Record<string, unknown>

		expect(deployRes.status(), 'a refused deployment must not answer 2xx').toBe(409)
		expect(body.error).toBe('value-refused')
		expect(body.applied, 'a refused deployment reports values applied').toBe(0)
		expect(String(body.message ?? ''), 'the refusal does not name the value').toContain(KEY)

		/* NOTHING was applied: the interloper's value is still the live one. */
		const answer = await effective()
		expect((answer.value as Record<string, unknown>).relay).toBe('smtp-two')
	})

	test('a broken weekend is undone in one act', async () => {
		const history = (await (await admin.get(DEPLOYMENTS)).json()) as Record<string, unknown>
		const results = history.results as Array<Record<string, unknown>>
		const interloper = results.find((row) => String(row.name ?? '') === `e2e interloper ${RUN}`)

		expect(interloper, 'the interloper deployment is not in the history').toBeTruthy()

		const before = results.length

		const rollbackRes = await admin.post(`${DEPLOYMENTS}/${interloper!.uuid}/rollback`, { data: {} })
		expect(rollbackRes.status(), `rollback failed: ${await rollbackRes.text()}`).toBe(201)

		const rollback = (await rollbackRes.json()) as Record<string, unknown>
		expect(rollback.isRollback).toBe(true)
		expect(rollback.restores, 'the rollback does not name what it restores').toBe(interloper!.uuid)

		/* The earlier value is live again. */
		const answer = await effective()
		expect((answer.value as Record<string, unknown>).relay).toBe('smtp-one')

		/* APPEND-ONLY: the history GREW. Undoing did not remove the row that
		 * recorded the change, which is the record of what happened. */
		const after = (await (await admin.get(DEPLOYMENTS)).json()) as Record<string, unknown>
		const afterResults = after.results as Array<Record<string, unknown>>
		expect(afterResults.length, 'the rollback shortened the history').toBe(before + 1)
		expect(
			afterResults.find((row) => row.uuid === interloper!.uuid),
			'the rolled-back deployment was removed from the history',
		).toBeTruthy()
	})

	test('a deployed set cannot be deployed or edited again', async () => {
		const history = (await (await admin.get(DEPLOYMENTS)).json()) as Record<string, unknown>
		const results = history.results as Array<Record<string, unknown>>
		const first = results.find((row) => String(row.name ?? '') === `e2e first deployment ${RUN}`)
		const setUuid = String(first!.setUuid)

		const again = await admin.post(`${SETS}/${setUuid}/deploy`, { data: {} })
		expect(again.status(), 'a deployed set deployed twice').toBe(409)
		expect((await again.json()).error).toBe('state')

		const edit = await admin.post(`${SETS}/${setUuid}/values`, {
			data: { layer: 'register', layerRef: LAYER_REF, key: KEY, value: { relay: 'late' } },
		})
		expect(edit.status(), 'a deployed set took another value').toBe(409)
	})

	test('an unknown set and a keyless explainer refuse by name', async () => {
		const unknown = await admin.get(`${SETS}/00000000-0000-0000-0000-000000000000`)
		expect(unknown.status()).toBe(404)
		expect((await unknown.json()).error).toBe('unknown')

		const keyless = await admin.get(EFFECTIVE)
		expect(keyless.status()).toBe(400)
		expect(String((await keyless.json()).message ?? '')).toContain('key is required')
	})
})

/*
 * BUNDLES, EXCEPTIONS, THE MATRIX COPY AND THE SEED.
 *
 * Same safety rule as above, one layer lower: every address hangs off a bundle,
 * a subject or a role named for this run, under the open `notification.` and
 * `permission.` prefixes. The seed test is the one exception and it is safe by
 * construction: seeding writes drafts, never live values, and this spec asserts
 * that by reading an instance key before and after and refusing to deploy the
 * set it opened.
 *
 * WHAT IT LEAVES BEHIND. Bundle-layer and subject-layer configuration values
 * under this run's references, and the deployments that published them. The
 * bindings are removed by the last test. Nothing aggregates onto anything a
 * person reads.
 */
test.describe('configuration bundles over HTTP', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext

	const BUNDLES = `${API}/configuration/bundles`
	const SEED = `${API}/configuration/seed`

	const BUNDLE = `e2e-bundle-${RUN}`
	const FOLLOWER = `e2e-follower-${RUN}`
	const EXCEPTION = `e2e-exception-${RUN}`
	const ROLE_FROM = `e2e-role-from-${RUN}`
	const ROLE_TO = `e2e-role-to-${RUN}`
	const RULE = 'notification.assigned'

	/** Draft one value at any address and deploy it in one act. */
	async function publish(
		name: string,
		layer: string,
		layerRef: string,
		key: string,
		value: Record<string, unknown>,
	): Promise<void> {
		const opened = await admin.post(SETS, { data: { name, description: 'e2e bundles' } })
		expect(opened.status(), `opening a set failed: ${await opened.text()}`).toBe(201)
		const setUuid = String((await opened.json()).uuid)

		const drafted = await admin.post(`${SETS}/${setUuid}/values`, {
			data: { layer, layerRef, key, value },
		})
		expect(drafted.status(), `drafting failed: ${await drafted.text()}`).toBe(201)

		const deployed = await admin.post(`${SETS}/${setUuid}/deploy`, { data: { name } })
		expect(deployed.status(), `deploy failed: ${await deployed.text()}`).toBe(201)
	}

	/** What the explainer answers for one subject, resolving its bundle itself. */
	async function forSubject(subject: string, key = RULE): Promise<Record<string, unknown>> {
		const res = await admin.get(`${EFFECTIVE}?key=${key}&subject=${subject}`)
		expect(res.ok(), `the explainer failed: ${await res.text()}`).toBeTruthy()

		return (await res.json()) as Record<string, unknown>
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reach = await admin.get(BUNDLES)
		expect(
			reach.status(),
			`the administrator account cannot reach the bundle surface (${reach.status()})`,
		).toBeLessThan(400)
	})

	test('two hundred case types stay in step', async () => {
		await publish(`e2e bundle rule ${RUN}`, 'bundle', BUNDLE, RULE, { channel: 'mail' })

		for (const subject of [FOLLOWER, EXCEPTION]) {
			const bound = await admin.post(`${BUNDLES}/${BUNDLE}/bindings`, { data: { subject } })
			expect(bound.status(), `binding failed: ${await bound.text()}`).toBe(201)
		}

		/* THE WHOLE POINT. Neither subject holds a copy of the rule, and the
		 * caller names only the subject: the bundle is resolved from the
		 * binding. Change the one bundle row and both change with it. */
		for (const subject of [FOLLOWER, EXCEPTION]) {
			const answer = await forSubject(subject)
			expect(answer.found, `${subject} does not see its bundle`).toBe(true)
			expect((answer.value as Record<string, unknown>).channel).toBe('mail')
			expect(answer.layer, `${subject} reads the rule from the wrong layer`).toBe('bundle')
			expect(answer.layerRef).toBe(BUNDLE)
		}

		/* And changing the bundle moves both, which a copy could never do. */
		await publish(`e2e bundle rule changed ${RUN}`, 'bundle', BUNDLE, RULE, { channel: 'push' })

		for (const subject of [FOLLOWER, EXCEPTION]) {
			expect(((await forSubject(subject)).value as Record<string, unknown>).channel).toBe('push')
		}
	})

	test('an exception is visible as an exception', async () => {
		await publish(`e2e subject override ${RUN}`, 'subject', EXCEPTION, RULE, { channel: 'none' })

		/* The override wins for the subject that made it, and only for it. */
		const overridden = await forSubject(EXCEPTION)
		expect((overridden.value as Record<string, unknown>).channel).toBe('none')
		expect(overridden.layer).toBe('subject')
		expect(((await forSubject(FOLLOWER)).value as Record<string, unknown>).channel).toBe('push')

		/* And the bundle's own listing names it, rather than showing two
		 * subjects that look equally compliant. */
		const shown = (await (await admin.get(`${BUNDLES}/${BUNDLE}`)).json()) as Record<string, unknown>
		const bindings = shown.bindings as Array<Record<string, unknown>>
		const exception = bindings.find((row) => row.subject === EXCEPTION)
		const follower = bindings.find((row) => row.subject === FOLLOWER)

		expect(exception?.overriding, 'an override is not listed as an exception').toBe(true)
		expect(exception?.overrides).toContain(RULE)
		expect(follower?.overriding, 'a compliant subject is listed as overriding').toBe(false)
	})

	test('a copied matrix is reviewed before it is live', async () => {
		await publish(`e2e matrix read ${RUN}`, 'subject', ROLE_FROM, 'permission.read', { allowed: true })
		await publish(`e2e matrix write ${RUN}`, 'subject', ROLE_FROM, 'permission.write', { allowed: true })

		const opened = await admin.post(SETS, { data: { name: `e2e matrix copy ${RUN}` } })
		const setUuid = String((await opened.json()).uuid)

		const copied = await admin.post(`${SETS}/${setUuid}/copy`, {
			data: { prefix: 'permission.', from: ROLE_FROM, to: ROLE_TO },
		})
		expect(copied.status(), `the copy failed: ${await copied.text()}`).toBe(201)

		const body = (await copied.json()) as Record<string, unknown>
		expect(body.total, 'the copy did not take the whole matrix').toBe(2)

		/* THE WHOLE POINT of D-6. The copy exists as a draft and the target's
		 * live matrix has not moved. */
		const live = await forSubject(ROLE_TO, 'permission.read')
		expect(live.found, 'a copied matrix went live without review').toBe(false)

		const set = (await (await admin.get(`${SETS}/${setUuid}`)).json()) as Record<string, unknown>
		expect(set.drafts as Array<unknown>, 'the copy left no drafts to review').toHaveLength(2)

		/* A copy onto itself, and a prefix that is not one, refuse by name. */
		const itself = await admin.post(`${SETS}/${setUuid}/copy`, {
			data: { prefix: 'permission.', from: ROLE_FROM, to: ROLE_FROM },
		})
		expect(itself.status()).toBe(400)

		const loose = await admin.post(`${SETS}/${setUuid}/copy`, {
			data: { prefix: 'permission', from: ROLE_FROM, to: ROLE_TO },
		})
		expect(loose.status(), 'a prefix without a dot was accepted').toBe(400)
	})

	test('a new instance gets a working vocabulary to review', async () => {
		/* rbac is the instance key the seed would stage on a fresh instance.
		 * Reading it before and after is the assertion that seeding stages
		 * rather than applies: whatever this instance holds, it still holds. */
		const before = (await (await admin.get(`${EFFECTIVE}?key=rbac`)).json()) as Record<string, unknown>

		const seeded = await admin.post(SEED, { data: { name: `e2e seed ${RUN}` } })
		expect(seeded.status(), `the seed failed: ${await seeded.text()}`).toBe(201)

		const body = (await seeded.json()) as Record<string, unknown>
		const set = body.set as Record<string, unknown>
		const staged = body.seeded as Array<Record<string, unknown>>
		const skipped = body.skipped as Array<Record<string, unknown>>

		expect(String(set.uuid ?? ''), 'the seed opened no set to review').toMatch(/[0-9a-f-]{36}/)
		expect(set.state, 'the seed published instead of staging').toBe('open')
		expect(
			staged.length + skipped.length,
			'the seed accounted for no domain at all',
		).toBeGreaterThan(0)

		/* Every domain it touched is accounted for by name, staged or skipped,
		 * so an operator reads what it did rather than only what it changed. */
		for (const row of [...staged, ...skipped]) {
			expect(String(row.key ?? ''), 'the seed staged an unnamed key').not.toBe('')
		}

		const after = (await (await admin.get(`${EFFECTIVE}?key=rbac`)).json()) as Record<string, unknown>
		expect(after.found, 'the seed changed a live value').toBe(before.found)
		expect(after.value, 'the seed changed a live value').toEqual(before.value)

		/* Discard the set rather than leaving an instance-key draft open for
		 * the next person to deploy by accident. */
		const discarded = await admin.delete(`${SETS}/${String(set.uuid)}`)
		expect(discarded.ok(), `discarding the seeded set failed: ${await discarded.text()}`).toBeTruthy()
	})

	test('a subject follows one bundle, and unbinding says so', async () => {
		const twice = await admin.post(`${API}/configuration/bundles/e2e-other-${RUN}/bindings`, {
			data: { subject: FOLLOWER },
		})
		expect(twice.status(), 'a subject was rebound to a second bundle').toBe(409)
		expect(String((await twice.json()).message ?? '')).toContain(BUNDLE)

		for (const subject of [FOLLOWER, EXCEPTION]) {
			const gone = await admin.delete(`${BUNDLES}/${BUNDLE}/bindings/${subject}`)
			expect(gone.ok(), `unbinding failed: ${await gone.text()}`).toBeTruthy()
		}

		const again = await admin.delete(`${BUNDLES}/${BUNDLE}/bindings/${FOLLOWER}`)
		expect(again.status(), 'unbinding something unbound reported success').toBe(404)

		/* Unbinding is not a delete: the subject keeps the value it set. */
		const kept = await forSubject(EXCEPTION)
		expect((kept.value as Record<string, unknown>).channel).toBe('none')
	})
})
