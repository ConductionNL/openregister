/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Register descriptors — the inventory, the forced re-import, and what it costs.
 *
 * WHY THIS IS AN E2E AND NOT A UNIT TEST
 *
 * `RegisterDescriptorServiceTest` covers the decision table with mocks, and that
 * is the right place for it. But the two assertions that matter most here are
 * about what the IMPORT actually does to a live instance:
 *
 *   1. A forced re-import of a register whose version already matches must
 *      WRITE. `ImportHandler` short-circuits on `version_compare(shipped,
 *      installed, '<=')` unless forced, and a mock of the import service can
 *      only ever confirm which arguments were passed — never that the row moved.
 *
 *   2. A schema that EXTENDS one the descriptor ships must survive that write.
 *      An extension refers to its base (`allOf` holds ids/uuids/slugs), so it
 *      should be impervious to the base moving. A mock cannot tell that apart
 *      from an extension materialised as a copy, which would silently revert
 *      somebody's customisation — through the button offered as a repair.
 *
 * Both are claims about the database, so both are tested against one.
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const RUN_ID = `e2e-desc-${Date.now().toString(36)}`

interface Row {
	appId: string
	slug: string
	state: 'current' | 'behind' | 'absent'
	installedVersion: string | null
	shippedVersion: string
	descriptor: string
}

async function inventory(request: APIRequestContext): Promise<Row[]> {
	const resp = await request.get(`${API}/register-descriptors`)
	expect(resp.status(), await resp.text()).toBe(200)

	return (await resp.json()).results as Row[]
}

test.describe('register-descriptors — what landed, and what a re-import costs', () => {
	test('the inventory reports every declared register with a state', async ({
		request,
	}) => {
		const rows = await inventory(request)

		expect(rows.length, 'this instance has openregister installed').toBeGreaterThan(0)

		for (const row of rows) {
			expect(['current', 'behind', 'absent']).toContain(row.state)
			expect(row.shippedVersion, `${row.slug} ships a version`).toBeTruthy()

			// 🔴 absent and present are told apart by the INSTALLED version being
			// null, not by a truthiness check on a string. A `behind` row and an
			// `absent` row both "need attention" and need different actions, so
			// nothing here is allowed to collapse them.
			if (row.state === 'absent') {
				expect(row.installedVersion).toBeNull()
			} else {
				expect(row.installedVersion).not.toBeNull()
			}
		}
	})

	test('🔴 a forced re-import writes even when the versions already match', async ({
		request,
	}) => {
		const rows = await inventory(request)
		const current = rows.find((r) => r.state === 'current')
		test.skip(
			current === undefined,
			'no register on this instance is present and current, so the version-gate '
				+ 'case — the one an administrator actually presses the button in — is '
				+ 'UNVERIFIED by this run.',
		)

		const target = current as Row
		const resp = await request.post(
			`${API}/register-descriptors/${target.appId}/${target.slug}/import`,
		)

		expect(resp.status(), await resp.text()).toBe(200)
		expect(
			(await resp.json()).outcome,
			'an unforced import would report success here having skipped on the version '
				+ 'comparison — which is the whole defect this endpoint exists to avoid',
		).toBe('imported')
	})

	test('🔴 a schema extending the base survives a forced re-import of it', async ({
		request,
	}) => {
		const rows = await inventory(request)
		const present = rows.find((r) => r.state !== 'absent')
		test.skip(
			present === undefined,
			'no descriptor has landed on this instance, so there is no base schema to '
				+ 'extend and the extension-survives assertion is UNVERIFIED.',
		)
		const target = present as Row

		// Find a schema the target register owns — the thing a re-import rewrites.
		const registers = await request.get(`${API}/registers?limit=1000`)
		const register = ((await registers.json()).results as Array<Record<string, unknown>>)
			.find((r) => r.slug === target.slug)
		const baseId = ((register?.schemas ?? []) as number[])[0]
		test.skip(
			baseId === undefined,
			`register "${target.slug}" owns no schema, so there is nothing to extend`,
		)

		const created = await request.post(`${API}/schemas`, {
			data: {
				title: `${RUN_ID} extension`,
				slug: `${RUN_ID}-extension`,
				allOf: [baseId],
				properties: {
					customField: { type: 'string', description: 'added by the extension' },
				},
			},
		})
		expect(created.status(), await created.text()).toBeLessThanOrEqual(201)
		const extensionId = (await created.json()).id

		try {
			const imported = await request.post(
				`${API}/register-descriptors/${target.appId}/${target.slug}/import`,
			)
			expect(imported.status(), await imported.text()).toBe(200)

			const after = await request.get(`${API}/schemas/${extensionId}`)
			expect(after.status(), 'the extension still exists').toBe(200)

			const schema = await after.json()
			expect(schema.title).toBe(`${RUN_ID} extension`)
			expect(
				schema.allOf,
				'the reference to the base is intact — this is what makes the extension '
					+ 'impervious to the base moving',
			).toContain(baseId)
			expect(
				schema.properties?.customField?.description,
				"the extension's own property is untouched",
			).toBe('added by the extension')
		} finally {
			await request.delete(`${API}/schemas/${extensionId}`).catch(() => {})
		}
	})

	test('a re-import naming a register the app does not declare fails, and says why', async ({
		request,
	}) => {
		const resp = await request.post(
			`${API}/register-descriptors/openregister/no-such-register-9f3a1c/import`,
		)

		expect(resp.status()).toBe(422)

		const body = await resp.json()
		expect(body.outcome).toBe('failed')
		// The reason has to name the slug. "Import failed" leaves the administrator
		// unable to tell a typo from a broken descriptor from a missing app.
		expect(String(body.reason)).toContain('no-such-register-9f3a1c')
	})
})
