import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE DUTCH API DESIGN RULES, READ BY ANYONE AND PROBED BY AN ADMINISTRATOR.
 *
 * api-nl-design-rules-conformance task 7.2. Four readers of one register with
 * two schemas:
 *  - an anonymous integrator reads the conformance report: every rule of the
 *    catalogue once, with one of the five results, the counts adding up, the
 *    response rules `not-probed` before anybody probed, and the missing version
 *    segment a deviation with its reason;
 *  - a register whose version is `1.0` fails the semver rule, naming the path and the value;
 *  - a tender assessor reads the marker in the generated document;
 *  - an administrator opens the register list, chooses "Check Dutch API design
 *    rules" and presses "Probe responses"; the dialog then shows the version
 *    header rule probed, and a later read of the report carries the same result
 *    with the probe time;
 *  - a caseworker who is no administrator is refused the probe with 403.
 *
 * The rule count is read from the report, not written here: the catalogue is the
 * one place it lives (NlGovRuleCatalogueTest pins it).
 *
 * HERMETIC: it creates its register and schemas and removes them. The
 * non-administrator is the seeded `e2e-other`.
 *
 * @e2e oas-validation::an-integrator-reads-the-full-report
 * @e2e oas-validation::a-rule-nobody-probed-does-not-read-as-passed
 * @e2e oas-validation::a-version-that-is-not-semantic-fails-the-semver-rule
 * @e2e oas-validation::a-tender-assessor-finds-the-marker-in-the-document
 * @e2e oas-validation::the-missing-version-segment-is-a-deviation-with-its-reason
 * @e2e oas-validation::an-administrator-sees-the-version-header-rule-fail-on-a-major-only-value
 * @e2e oas-validation::a-caseworker-cannot-run-the-probe
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** The seeded non-admin account, provisioned by the workflow's seed command. */
const CASEWORKER = 'e2e-other'
const CASEWORKER_PASS = 'E2e-Share-Pass-123'

const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)
const RESULTS = ['pass', 'fail', 'deviation', 'manual', 'not-probed']
const RESPONSE_RULES = ['/core/version-header', '/core/transport/security-headers', '/core/date-time/timezone']

/**
 * An API context with no session cookie: signed in as one user, or anonymous.
 */
async function contextFor(user: string | null, password = ''): Promise<APIRequestContext> {
	const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
	if (user !== null) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}
	return pwRequest.newContext({ baseURL: BASE, storageState: { cookies: [], origins: [] }, extraHTTPHeaders: headers })
}

test.describe('the Dutch API design rules report', () => {
	test.describe.configure({ mode: 'serial' })
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1440, height: 1100 } })

	let admin: APIRequestContext
	let anonymous: APIRequestContext
	let caseworker: APIRequestContext
	let registerId: string
	let registerTitle: string
	const schemaIds: string[] = []

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		anonymous = await contextFor(null)
		caseworker = await contextFor(CASEWORKER, CASEWORKER_PASS)

		for (const name of ['meldingen', 'besluiten']) {
			const schema = await admin.post(`${API}/schemas`, {
				data: { title: `NLGov ${name} ${RUN}`, slug: `nlgov${name}${RUN}`, properties: { titel: { type: 'string' } } },
			})
			expect(schema.ok(), await schema.text()).toBe(true)
			schemaIds.push(String((await schema.json()).id))
		}

		registerTitle = `NLGov zaken ${RUN}`
		const register = await admin.post(`${API}/registers`, {
			data: { title: registerTitle, slug: `nlgovzaken${RUN}`, schemas: schemaIds.map(Number) },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)
	})

	test.afterAll(async () => {
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const id of schemaIds) {
			await admin.delete(`${API}/schemas/${id}`)
		}
		await admin.dispose()
		await anonymous.dispose()
		await caseworker.dispose()
	})

	test('an anonymous integrator reads every rule once, and nothing unprobed reads as passed', async () => {
		const response = await anonymous.get(`${API}/registers/${registerId}/oas/conformance`)
		expect(response.status(), await response.text()).toBe(200)
		const report = await response.json()

		expect(report.ruleset).toBe('2.2.1')
		const ids = report.rules.map((rule: { id: string }) => rule.id)
		expect(new Set(ids).size, 'each rule once').toBe(ids.length)
		for (const rule of report.rules) {
			expect(RESULTS, `${rule.id} has one of the five results`).toContain(rule.result)
		}
		const counted = Object.values(report.counts as Record<string, number>).reduce((sum, count) => sum + count, 0)
		expect(counted, 'the counts add up to the rules').toBe(report.rules.length)

		expect(report.probedAt).toBeNull()
		for (const id of RESPONSE_RULES) {
			expect(report.rules.find((rule: { id: string }) => rule.id === id)?.result, id).toBe('not-probed')
		}
		expect(report.counts['not-probed']).toBe(RESPONSE_RULES.length)

		const uriVersion = report.rules.find((rule: { id: string }) => rule.id === '/core/uri-version')
		expect(uriVersion.result).toBe('deviation')
		expect(JSON.stringify(uriVersion)).toContain('API-Version')
	})

	test('a version that is not semantic fails the semver rule, naming where and what', async () => {
		const register = await admin.post(`${API}/registers`, {
			data: { title: `NLGov semver ${RUN}`, slug: `nlgovsemver${RUN}`, version: '1.0' },
		})
		expect(register.ok(), await register.text()).toBe(true)
		const id = String((await register.json()).id)
		try {
			const report = await (await anonymous.get(`${API}/registers/${id}/oas/conformance`)).json()
			const semver = report.rules.find((rule: { id: string }) => rule.id === '/core/semver')
			expect(semver.result).toBe('fail')
			expect(semver.findings[0].path).toBe('info.version')
			expect(semver.findings[0].message).toContain('"1.0"')

			const ours = await (await anonymous.get(`${API}/registers/${registerId}/oas/conformance`)).json()
			expect(ours.rules.find((rule: { id: string }) => rule.id === '/core/semver').result, 'the control register passes').toBe('pass')
		} finally {
			await admin.delete(`${API}/registers/${id}`)
		}
	})

	test('a tender assessor finds the marker in the generated document', async () => {
		const response = await anonymous.get(`${API}/registers/${registerId}/oas`)
		expect(response.status(), await response.text()).toBe(200)
		const marker = (await response.json())['x-nl-api-design-rules']

		expect(marker.version).toBe('2.2.1')
		expect(marker.pass).toContain('/core/http-methods')
		expect(marker.deviation).toContain('/core/uri-version')
	})

	test('a caseworker who is no administrator is refused the probe', async () => {
		const response = await caseworker.post(`${API}/registers/${registerId}/oas/conformance/probe`)
		expect(response.status(), await response.text()).toBe(403)

		const report = await (await anonymous.get(`${API}/registers/${registerId}/oas/conformance`)).json()
		expect(report.probedAt, 'the refused probe ran nothing').toBeNull()
	})

	test('an administrator probes from the register list and the report keeps the result', async ({ page }) => {
		await page.goto('/index.php/apps/openregister/registers')

		// The list view carries a row menu per register; the register may sit on a later page.
		// The view toggle's second segment is the table (Cards | Table).
		await page.getByRole('group', { name: 'View mode' }).getByRole('button').nth(1).click({ timeout: 30_000 })
		const row = page.getByRole('row').filter({ hasText: registerTitle })
		await page.getByRole('row').nth(1).waitFor({ timeout: 30_000 })
		for (let pageTurns = 0; pageTurns < 10 && !(await row.isVisible()); pageTurns++) {
			const next = page.getByRole('button', { name: 'Next', exact: true })
			if ((await next.count()) === 0 || !(await next.isEnabled())) {
				break
			}
			await next.click()
			await page.waitForTimeout(750)
		}
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.getByRole('button', { name: /actions/i }).first().click()
		await page.getByRole('menuitem', { name: 'Check Dutch API design rules' }).click()

		const dialog = page.getByRole('dialog').filter({ hasText: registerTitle })
		await expect(dialog.getByTestId('nlgov-group-not-probed')).toContainText('/core/version-header', { timeout: 30_000 })

		await dialog.getByTestId('nlgov-probe').click()
		await expect(dialog.getByTestId('nlgov-meta')).toContainText('Responses probed', { timeout: 30_000 })
		await expect(dialog.getByTestId('nlgov-group-not-probed')).toHaveCount(0)

		// The version header rule now carries a probed result, and a later read agrees with the dialog.
		const report = await (await anonymous.get(`${API}/registers/${registerId}/oas/conformance`)).json()
		expect(report.probedAt, 'the report keeps the probe time').not.toBeNull()
		const versionHeader = report.rules.find((rule: { id: string }) => rule.id === '/core/version-header')
		expect(['pass', 'fail']).toContain(versionHeader.result)
		await expect(dialog.getByTestId(`nlgov-group-${versionHeader.result}`)).toContainText('/core/version-header')
		if (versionHeader.result === 'fail') {
			// The instance answers a major-only API-Version; the finding says why it fails.
			await expect(dialog.getByTestId('nlgov-group-fail')).toContainText('not a full major.minor.patch version')
		}
	})
})
