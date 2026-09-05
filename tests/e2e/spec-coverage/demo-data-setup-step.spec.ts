/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-111 — the demo-data setup step, exercised against a running instance.
 *
 * WHY THIS EXISTS. The programme that added demo data to this fleet shipped a
 * defect that every unit test passed: the import printed `register "…"
 * imported.` and seeded ZERO of the descriptor's objects. The unit tests could
 * not see it — they mock the import service, so they validate the CALL and
 * never its effect.
 *
 * So the assertion that matters here is not "the endpoint answers 200". It is
 * that the response NAMES WHAT LANDED. A success message that cannot be told
 * apart from an import that wrote nothing is exactly what let that defect
 * through.
 *
 * WHY THE API AND NOT A CLICK-THROUGH. `CnAppRoot` opens the optional wizard
 * only while an optional step is outstanding, and the CI seed deliberately
 * settles those so the wizard stops covering the app in every test. The
 * observable surface for this capability is therefore the contract the wizard
 * calls — `GET /api/setup/status` and `POST /api/setup/action/{id}` — issued
 * from inside the authenticated admin page so every call carries the real
 * session and `OC.requestToken` through Nextcloud's `AuthorizedAdminSetting`
 * middleware. A unit test with a mocked IAppConfig cannot show that middleware
 * admitting the request; this can — and that middleware is precisely what the
 * attribute on SetupController configures.
 *
 * WHAT THIS DELIBERATELY DOES NOT ASSERT. That the demo-data step is FIRST
 * (ADR-111 rule 4) is a property of the manifest, which the app bundles rather
 * than serves, so it is not observable from here. Gate 100
 * (`setup-demo-data-first`) checks it statically on every change. Claiming to
 * prove it here would be asserting something this vantage point cannot see.
 *
 * @spec exclude ADR-042/ADR-111 setup contract; no per-app behavioural spec.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const BASE = '/apps/openregister'

/** The registers this app's demo descriptor creates, by slug. */
const DEMO_REGISTER_SLUGS = [
	'credential-broker',
	'data-subject-requests',
	'dsar-policy-packs',
	'edepot-transfers',
	'flows',
	'merge-operation',
	'trust-configuration',
	'vocabulary',
]

const API = '/index.php/apps/openregister/api'

/** One authenticated JSON call issued from inside the logged-in admin page. */
async function api(
	page: Page,
	method: string,
	apiPath: string,
	body?: unknown,
): Promise<{ status: number; json: any }> {
	return await page.evaluate(
		async ({ method, apiPath, body }) => {
			const res = await fetch(apiPath, {
				method,
				headers: {
					'Content-Type': 'application/json',

					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			let json: any
			try {
				json = await res.json()
			} catch {
				json = null
			}
			return { status: res.status, json }
		},
		{ method, apiPath, body },
	)
}

/**
 * Choose the shipped dataset, and answer with the id that was chosen.
 *
 * 🔴 THE TEST HAS TO MAKE THE DECISION IT ASSERTS AGAINST. The demo-data step
 * is a choice followed by a load step now, and the CI seed settles the optional
 * steps by posting `skip-demo-data` — which records "none". A load that follows
 * correctly imports nothing, so an install test that skips this arranges no
 * precondition and measures the seed instead of the app.
 *
 * The id comes from `/api/setup/status` rather than a literal: the choice step
 * reads its options from exactly that list, so a hardcoded id can pass while
 * the list an operator sees is empty.
 */
async function pickShippedDataset(page: Page): Promise<string> {
	const status = await api(page, 'GET', `${BASE}/api/setup/status`)
	const shipped = (status.json?.datasets ?? []).find(
		(d: any) => d?.id && d.id !== 'none',
	)
	expect(
		shipped,
		`setup/status offers no dataset to load: ${JSON.stringify(status.json?.datasets)}`,
	).toBeTruthy()

	const saved = await api(page, 'POST', `${BASE}/api/setup/config`, {
		demo_dataset: shipped.id,
	})
	expect(saved.status, JSON.stringify(saved.json)).toBe(200)

	return shipped.id
}

test.describe.configure({ mode: 'serial' })

test.describe('ADR-111 demo data', () => {
	// The setup contract lives behind the admin middleware, so these calls need
	// the real logged-in session `globalSetup` captured — not the suite's
	// default Basic-auth header, which does not produce an `OC.requestToken`.
	test.use({ storageState: STORAGE_STATE })

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' })
		await page.waitForFunction(() => (window as any).OC?.requestToken, null, {
			timeout: 15000,
		})
	})

	test('setup status reports the demo-data step, so the wizard can offer it', async ({
		page,
	}) => {
		const res = await api(page, 'GET', `${BASE}/api/setup/status`)

		expect(res.status, 'setup/status must answer an authenticated admin').toBe(
			200,
		)

		// A step the endpoint never MENTIONS resolves to `done: false` forever —
		// no operator action can clear it, and CnAppRoot then covers the app with
		// the wizard in every fresh browser context. Absence is the defect here,
		// not "not done".
		expect(
			Object.keys(res.json?.steps ?? {}),
			'setup/status must report a demo-data step',
		).toContain('demo-data')
	})

	test('installing the demo data reports HOW MUCH landed, not just success', async ({
		page,
	}) => {
		// 🔴 A REAL IMPORT, NOT A STUB. Measured on this fleet: the install arm
		// took 42.8s on dossiq and 49.6s on shillinq, and exceeded the 30s
		// default on one run. The operation is legitimately slow, and the
		// assertion is worth its cost: it is the only check that the install
		// WROTE something.
		test.slow()

		await pickShippedDataset(page)

		const res = await api(
			page,
			'POST',
			`${BASE}/api/setup/action/install-demo-data`,
		)

		expect(res.status, 'the action must pass the admin middleware').toBe(200)
		expect(
			res.json?.success,
			`install failed: ${JSON.stringify(res.json)}`,
		).toBe(true)

		// 🔴 THE COUNTS ARE THE ASSERTION. "Demo data installed" with no numbers
		// is indistinguishable from an import that wrote nothing — the exact
		// defect this programme shipped and had to fix. A message carrying a
		// positive object count is the only evidence the data reached the
		// instance.
		const message = String(res.json?.message ?? '')
		const numbers = (message.match(/\d+/g) ?? []).map(Number)

		expect(
			numbers.some((n) => n > 0),
			`the install message must name a non-zero object count; got: "${message}"`,
		).toBe(true)
	})

	test('re-installing is safe, because the step promises it is', async ({
		page,
	}) => {
		// The step body tells the operator it is "safe to run more than once".
		// That sentence is a contract; this asserts the server keeps it rather
		// than erroring or reporting failure on a second pass.
		await pickShippedDataset(page)

		const again = await api(
			page,
			'POST',
			`${BASE}/api/setup/action/install-demo-data`,
		)

		expect(again.status).toBe(200)
		expect(
			again.json?.success,
			`a second install must not fail: ${JSON.stringify(again.json)}`,
		).toBe(true)
	})

	/*
	 * 🔴 THIS SPEC WRITES, SO IT CLEANS UP. The install is a real import: it
	 * creates this app's eight demo registers and their objects. Left behind,
	 * they would land in the lists the other admitted specs assert on, which is
	 * exactly why the CI seed settles the demo-data decision as SKIPPED rather
	 * than installing it.
	 *
	 * Resolved by SLUG rather than by an id captured during the run, so a
	 * mid-run failure still tears the fixtures down — the same shape as
	 * crud/register-crud.spec.ts.
	 *
	 * `_limit`, not `limit`: a bare control param is read as a PROPERTY filter
	 * by this API and comes back with zero rows and HTTP 200, which would make
	 * this teardown silently delete nothing.
	 */
	test.afterAll(async ({ request }) => {
		const resp = await request
			.get(`${API}/registers?_limit=200`, {
				headers: { Accept: 'application/json' },
			})
			.catch(() => null)

		if (resp === null || !resp.ok()) {
			return
		}

		const registers = (await resp.json()).results ?? []

		for (const register of registers) {
			if (DEMO_REGISTER_SLUGS.includes(register.slug)) {
				await request
					.delete(`${API}/registers/${register.id}`)
					.catch(() => {})
			}
		}
	})
})
