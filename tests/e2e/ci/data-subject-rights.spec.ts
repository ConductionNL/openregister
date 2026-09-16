import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DATA SUBJECT RIGHTS ACROSS THE INSTANCE — end to end, over the HTTP API.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/data-subject-rights-across-the-instance/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e gdpr-data-subject-rights::an-unapproved-erasure-does-not-run
 *
 * WHAT THIS FILE CAN PROVE, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the capability is REACHABLE over HTTP: the four new routes are
 * registered, a preview comes back in the three-bucket shape a handler reads,
 * an erasure refuses by name when nobody approved the preview, a second run
 * refuses on the consumed stamp, and one handler's preview — which names a data
 * subject's records — is not readable by another. Those are exactly the
 * failures a green unit suite hides: a route missing from `appinfo/routes.php`
 * is a 404 no PHPUnit test would notice.
 *
 * It does NOT assert the COUNTS. The preview discovers a subject through the
 * PII index (`openregister_entities` joined to `openregister_entity_relations`),
 * and that index is written by file text extraction — there is no HTTP door
 * that attaches a detected entity to an OBJECT. A spec that created an object
 * with an email in it and then asserted "eight erasable, four protected" would
 * be asserting against an empty index, which is a test that cannot fail. The
 * two spec-delta scenarios that turn on the counts are marked `@e2e exclude`
 * for that reason and are asserted in
 * tests/Unit/Service/Gdpr/Erasure/ErasurePreviewServiceTest.php
 * (testTheGemeenteCanAnswerTheSubjectHonestly) and
 * tests/Unit/Service/Gdpr/Erasure/ErasureRunnerTest.php
 * (testOneDestructionPathOneRecord) — named here so nobody has to take this
 * comment's word for it.
 *
 * HERMETIC BY CONSTRUCTION. It needs no `occ`, no docker and no seeded PII.
 *
 * WHAT IT LEAVES BEHIND, SAID PLAINLY. Each test records a preview row against
 * a subject that exists only for this run (`preview-shape-<run>@example.org`
 * and friends), and those rows stay. There is deliberately no delete route: a
 * recorded answer that can be removed is not a record, and the row is the thing
 * an erasure was checked against. The rows aggregate onto nothing a person
 * reads — no total, no dashboard, no report — and they name no real subject, so
 * they are residue in the same sense a log line is.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()

/* No admin context here, on purpose. An AVG request is handled by a HANDLER,
 * and every route under test is `@NoAdminRequired`. Driving it as the
 * administrator would pass the reach rule by privilege and prove nothing about
 * the rule itself, so both accounts below are ordinary users — and the reach
 * test needs two of them.
 *
 * The same fixed uids the sharing, watcher and delete-window specs use,
 * provisioned by the workflow's `playwright-seed-command` (tests/e2e/ci/seed.sh). */
const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so parallel runs never collide on a subject. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const PREVIEWS = `${API}/gdpr/erasure-previews`

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

/**
 * Assert a seeded account is actually usable before any test leans on it, so a
 * seed that silently did not run says so here rather than surfacing later as a
 * confusing authorization error inside a refusal assertion.
 */
async function assertSeededUser(ctx: APIRequestContext, uid: string): Promise<void> {
	const res = await ctx.get(`${API}/registers`)
	expect(
		res.status(),
		`seeded account '${uid}' cannot authenticate (${res.status()}) — did playwright-seed-command run?`,
	).toBeLessThan(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('the previewed erasure over HTTP', () => {
	let owner: APIRequestContext
	let other: APIRequestContext

	/** Take a preview as the owner and return the recorded row. */
	async function takePreview(subject: string): Promise<Record<string, unknown>> {
		const res = await owner.post(PREVIEWS, {
			data: { subject, eraseMode: 'whole-object', request: `DSR-${RUN}` },
		})
		expect(
			res.ok(),
			`preview failed: ${res.status()} ${await res.text()}`,
		).toBeTruthy()

		return (await res.json()) as Record<string, unknown>
	}

	test.beforeAll(async () => {
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		await assertSeededUser(owner, OWNER)
		await assertSeededUser(other, OTHER)
	})

	test('a preview comes back recorded, pending, and split three ways', async () => {
		const preview = await takePreview(`preview-shape-${RUN}@example.org`)

		expect(String(preview.uuid ?? ''), 'the preview was not recorded').toMatch(
			/[0-9a-f-]{36}/,
		)
		expect(preview.status, 'a fresh preview must not already be approved').toBe(
			'pending',
		)
		expect(
			preview.requestId,
			'the preview does not name the request it answers',
		).toBe(`DSR-${RUN}`)
		expect(
			String(preview.digest ?? ''),
			'the preview carries no digest',
		).toHaveLength(64)

		const report = preview.report as Record<string, unknown>
		const counts = report.counts as Record<string, Record<string, number>>

		// THE THREE BUCKETS ARE THE ANSWER SHAPE. One number cannot be used to
		// write "deels niet, en dit is waarom", so all three are present even
		// when a bucket is empty.
		for (const bucket of ['erasable', 'pseudonymised', 'protected']) {
			expect(
				counts[bucket],
				`the preview has no ${bucket} bucket`,
			).toBeTruthy()
			for (const kind of [
				'objects',
				'files',
				'timelineEntries',
				'partyRecords',
			]) {
				expect(
					typeof counts[bucket][kind],
					`${bucket}.${kind} is not counted`,
				).toBe('number')
			}
		}

		expect(
			Array.isArray(report.protected),
			'protected records are not listed by name',
		).toBeTruthy()
	})

	test('a preview without a subject is refused', async () => {
		const res = await owner.post(PREVIEWS, {
			data: { eraseMode: 'whole-object' },
		})

		expect(
			res.status(),
			'a preview with no subject should be a bad request',
		).toBe(400)
	})

	test('an unapproved erasure does not run', async () => {
		const preview = await takePreview(`unapproved-${RUN}@example.org`)

		const run = await owner.post(`${PREVIEWS}/${preview.uuid}/run`)
		expect(run.status(), 'an unapproved erasure must refuse').toBe(409)

		const body = await run.json()
		// THE REFUSAL NAMES THE RULE. "Forbidden" with no rule leaves a handler
		// guessing about an irreversible act.
		expect(body.error).toBe('ERASURE_REFUSED')
		expect(
			body.rule,
			`the refusal does not name its rule: ${JSON.stringify(body)}`,
		).toBe('erasure-not-approved')

		// NOTHING WAS WRITTEN: the preview is still pending, not consumed.
		const after = await owner.get(`${PREVIEWS}/${preview.uuid}`)
		expect((await after.json()).status).toBe('pending')
	})

	test('an approved preview runs, and a second run refuses on the stamp', async () => {
		const preview = await takePreview(`approved-${RUN}@example.org`)

		const approve = await owner.post(`${PREVIEWS}/${preview.uuid}/approve`)
		expect(approve.ok(), `approve failed: ${await approve.text()}`).toBeTruthy()

		const approved = await approve.json()
		expect(approved.status).toBe('approved')
		expect(approved.approvedBy, 'the approval does not name who gave it').toBe(
			OWNER,
		)

		const run = await owner.post(`${PREVIEWS}/${preview.uuid}/run`)
		expect(
			run.ok(),
			`run failed: ${run.status()} ${await run.text()}`,
		).toBeTruthy()

		const outcome = await run.json()
		expect(outcome.preview).toBe(preview.uuid)
		expect(
			outcome.request,
			'the run does not carry the request it answers',
		).toBe(`DSR-${RUN}`)
		expect(typeof outcome.destroyedCount).toBe('number')
		expect(typeof outcome.complete).toBe('boolean')

		const second = await owner.post(`${PREVIEWS}/${preview.uuid}/run`)
		expect(second.status(), 'a spent preview must refuse a second run').toBe(409)
		expect((await second.json()).rule).toBe('erasure-already-run')
	})

	test('another handler cannot read a preview, and is told nothing exists', async () => {
		const preview = await takePreview(`private-${RUN}@example.org`)

		const read = await other.get(`${PREVIEWS}/${preview.uuid}`)
		expect(
			read.status(),
			"a stranger must not reach another handler's preview",
		).toBe(404)

		const body = await read.json()
		// ANSWERED AS UNKNOWN, NOT AS FORBIDDEN. "Forbidden" would confirm that
		// somebody asked about this data subject, which is itself a disclosure.
		expect(body.rule).toBe('erasure-preview-unknown')

		const run = await other.post(`${PREVIEWS}/${preview.uuid}/run`)
		expect(run.status(), 'a stranger must not be able to run it either').toBe(
			404,
		)
	})

	test('an unknown preview id is refused rather than answered', async () => {
		const res = await owner.get(
			`${PREVIEWS}/00000000-0000-4000-8000-000000000000`,
		)

		expect(res.status()).toBe(404)
		expect((await res.json()).rule).toBe('erasure-preview-unknown')
	})
})
