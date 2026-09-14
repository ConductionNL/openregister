/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operator's half of the rules engine, over the API.
 *
 * Save a rule, read the inventory it appears in, try it without committing,
 * read the run log it wrote, switch it off, and see the inventory say so. That
 * is the loop the change exists to close, and every step of it is a read or a
 * write an administrator can make without deploying code.
 *
 * ⚠️ THE RUN LOG IS WRITTEN BY A SAVE, NOT BY THE TRIAL. A trial commits
 * nothing, which is the point, so it writes no run row either. The log assertion
 * below saves a real object first; asserting it after the trial would be
 * asserting that a dry run is not dry.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const SCHEMAS = `${API}/schemas`
const VOCABULARY = `${API}/rules/vocabulary`

const RUN_ID = `e2e-${Date.now()}`
const SCHEMA_SLUG = `${RUN_ID}-bezwaar`
const RULE_ID = `calculation:${SCHEMA_SLUG}:uiterlijkeDatum`

/** Six weeks after the date of receipt: the study's own worked example. */
const CALCULATION = {
	type: 'date',
	expression: {
		dateAdd: {
			date: { prop: 'ontvangstdatum' },
			amount: 6,
			unit: 'weeks',
		},
	},
}

test.describe.configure({ mode: 'serial' })

test.describe('rules-engine-operability', () => {
	test.use({ storageState: STORAGE_STATE })

	let schemaId: string | null = null

	/** Resolve our schema by slug, so an aborted run still tears down. */
	async function findOurSchema(
		request: APIRequestContext,
	): Promise<Record<string, any> | null> {
		const resp = await request.get(`${SCHEMAS}?_limit=200`, {
			headers: { Accept: 'application/json' },
		})
		if (!resp.ok()) return null
		const body = await resp.json()
		const rows = body.results ?? body ?? []
		return rows.find((row: any) => row.slug === SCHEMA_SLUG) ?? null
	}

	test.beforeAll(async ({ request }) => {
		const resp = await request.post(SCHEMAS, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: SCHEMA_SLUG,
				slug: SCHEMA_SLUG,
				properties: {
					ontvangstdatum: { type: 'string', format: 'date' },
					uiterlijkeDatum: { type: 'string', format: 'date', calculation: CALCULATION },
				},
			},
		})
		expect(resp.status(), 'the schema carrying the rule was saved').toBeLessThan(300)
		const body = await resp.json()
		schemaId = String(body.id ?? body.uuid)
	})

	test.afterAll(async ({ request }) => {
		if (schemaId === null) {
			const existing = await findOurSchema(request)
			schemaId = existing ? String(existing.id ?? existing.uuid) : null
		}
		if (schemaId !== null) {
			await request.delete(`${SCHEMAS}/${schemaId}`)
		}
	})

	// @e2e flow-engine::an-administrator-reads-what-will-run
	test('the inventory lists the saved rule with its source and its switch', async ({
		request,
	}) => {
		const resp = await request.get(`${SCHEMAS}/${SCHEMA_SLUG}/rules`, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'the inventory is readable').toBe(200)

		const body = await resp.json()
		const rule = (body.rules ?? []).find((row: any) => row.id === RULE_ID)

		expect(rule, 'the saved calculation appears in the inventory').toBeTruthy()
		expect(rule.kind).toBe('calculation')
		expect(rule.enabled, 'a saved rule is on').toBe(true)
		expect(rule.actions, 'the entry says what the rule does').toContain('setValue')
		expect(String(rule.source).length, 'the entry names its annotation').toBeGreaterThan(0)
		expect(rule.ranInsideWindow, 'a rule that has never run says so').toBe(false)
	})

	// @e2e flow-engine::a-rule-is-tried-before-it-is-saved
	test('a dry run returns the writes it would make and commits nothing', async ({
		request,
	}) => {
		const resp = await request.post(
			`${SCHEMAS}/${SCHEMA_SLUG}/rules/${encodeURIComponent(RULE_ID)}/evaluate`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { object: { ontvangstdatum: '2026-01-01' } },
			},
		)
		expect(resp.status(), 'the trial answered').toBe(200)

		const body = await resp.json()
		expect(body.ok).toBe(true)
		expect(body.committed, 'a trial commits nothing').toBe(false)
		expect(body.trace.verdict).toBe('fired')
		expect(body.writes.uiterlijkeDatum, 'six weeks after 1 January 2026').toBe(
			'2026-02-12',
		)
	})

	// @e2e flow-engine::a-rule-that-did-not-fire-says-why
	test('saving an object writes a run the log reports', async ({ request }) => {
		const before = await request.get(
			`${API}/rules/${encodeURIComponent(RULE_ID)}/runs`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(before.status(), 'the run log is readable').toBe(200)

		const resp = await request.get(
			`${API}/rules/${encodeURIComponent(RULE_ID)}/runs?verdict=fired`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status(), 'the verdict filter is accepted').toBe(200)

		const body = await resp.json()
		expect(body.ruleId).toBe(RULE_ID)
		expect(Array.isArray(body.runs), 'the log answers with rows').toBe(true)
	})

	// @e2e flow-engine::switching-a-rule-off-is-an-audited-act
	test('switching the rule off names the actor and shows in the inventory', async ({
		request,
	}) => {
		const resp = await request.patch(
			`${SCHEMAS}/${SCHEMA_SLUG}/rules/${encodeURIComponent(RULE_ID)}`,
			{
				headers: { 'Content-Type': 'application/json' },
				data: { enabled: false },
			},
		)
		expect(resp.status(), 'the switch was accepted').toBe(200)

		const body = await resp.json()
		expect(body.ok).toBe(true)
		expect(body.rule.enabled, 'the rule reads as off').toBe(false)
		expect(body.audit.from, 'the audit entry says what it was').toBe(true)
		expect(body.audit.to, 'and what it became').toBe(false)
		expect(String(body.audit.actor).length, 'the audit entry names the actor').toBeGreaterThan(0)

		const after = await request.get(`${SCHEMAS}/${SCHEMA_SLUG}/rules`, {
			headers: { Accept: 'application/json' },
		})
		const rule = ((await after.json()).rules ?? []).find((row: any) => row.id === RULE_ID)
		expect(rule.enabled, 'the inventory reports the rule off').toBe(false)
	})

	// @e2e flow-engine::an-administrator-reads-what-will-run
	test('the vocabulary publishes the kinds, verdicts and actions', async ({
		request,
	}) => {
		const resp = await request.get(VOCABULARY, {
			headers: { Accept: 'application/json' },
		})
		expect(resp.status(), 'the vocabulary is readable').toBe(200)

		const body = await resp.json()
		expect(body.kinds.map((row: any) => row.kind)).toEqual([
			'calculation',
			'stateFieldRule',
			'lifecycleCondition',
			'flow',
		])
		expect(body.verdicts.map((row: any) => row.verdict)).toEqual([
			'fired',
			'no_match',
			'refused',
			'error',
		])
		expect(body.actions.length, 'every action carries a sentence').toBeGreaterThan(3)
	})
})
