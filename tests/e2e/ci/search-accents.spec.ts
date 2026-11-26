import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A READER FINDS AN ACCENTED NAME WITHOUT TYPING THE ACCENT.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e zoeken-filteren::a-reader-finds-an-accented-name-without-typing-the-accent
 * @e2e zoeken-filteren::the-facet-count-matches-the-results
 * @e2e zoeken-filteren::an-integration-asks-for-an-exact-match
 *
 * Seeds "Café de Flore", two more cafés and "Reünie oud-raadsleden", then
 * searches the object API and the objects index page with `cafe` and
 * `reunie`, and the API with `_accents=exact`.
 *
 * 🔴 EXACT WAS NOT EXACT ON MARIADB, AND THIS SPEC IS WHAT FOUND IT. Exact mode
 * fell back to the historical `LOWER(col) LIKE LOWER(pattern)`, which compares
 * under the column's own collation; MariaDB's default (`utf8mb4_general_ci`)
 * ignores accents already, so `cafe&_accents=exact` still returned every café.
 * The unit tests asserted the SQL string, which was the historical one and so
 * "correct". On PostgreSQL ILIKE is accent-sensitive and the old SQL held.
 *
 * HERMETIC. It creates its own register, schema and objects and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'

const CAFES = ['Café de Flore', 'Café Américain', 'Grand Café']
const REUNION = 'Reünie oud-raadsleden'
const PLAIN = 'Bakkerij Verhoeven'

/** Build an API context authenticated as one user. */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
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

test.describe('accent-insensitive search', () => {
	let admin: APIRequestContext
	let registerId = ''
	let schemaId = ''
	const uuids: Record<string, string> = {}

	/** The names and status buckets a search answers. */
	async function search(term: string, extra = ''): Promise<{ names: string[]; buckets: number }> {
		const res = await admin.get(
			`${API}/objects/${registerId}/${schemaId}?_limit=50&_search=${encodeURIComponent(term)}${extra}&_facets[status][type]=terms`,
		)
		expect(res.ok(), `search failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const names = (body.results as Array<Record<string, any>>).map((o) => String(o.naam)).sort()
		expect(body.total, 'the total agrees with the page').toBe(names.length)
		const buckets = ((body.facets?.status?.data?.buckets ?? []) as Array<Record<string, any>>).reduce(
			(sum, b) => sum + Number(b.count),
			0,
		)

		return { names, buckets }
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e search accents register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e search accents place ${RUN}`,
				description: 'e2e',
				properties: {
					naam: { type: 'string', title: 'Naam', maxLength: 128 },
					status: { type: 'string', title: 'Status', maxLength: 32, facetable: true },
				},
				configuration: { objectNameField: 'naam' },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const attach = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e search accents register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(attach.ok(), `register update failed: ${await attach.text()}`).toBeTruthy()

		for (const [naam, status] of [
			[CAFES[0], 'open'],
			[CAFES[1], 'open'],
			[CAFES[2], 'dicht'],
			[REUNION, 'open'],
			[PLAIN, 'open'],
		]) {
			const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data: { naam, status } })
			expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
			uuids[naam] = String((await res.json())['@self'].id)
		}
	})

	test.afterAll(async () => {
		for (const uuid of Object.values(uuids)) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin?.dispose()
	})

	test('the object API finds the accented names, and the facet adds up', async () => {
		const cafe = await search('cafe')
		expect(cafe.names).toEqual([...CAFES].sort())
		expect(cafe.buckets, 'the facet buckets add up to the results').toBe(CAFES.length)

		const reunie = await search('reunie')
		expect(reunie.names).toEqual([REUNION])
		expect(reunie.buckets).toBe(1)
	})

	test('an exact search does not fold the accents, and still folds case', async () => {
		expect((await search('cafe', '&_accents=exact')).names, 'no café for an unaccented exact term').toEqual([])
		expect((await search('reunie', '&_accents=exact')).names).toEqual([])
		expect((await search('Café', '&_accents=exact')).names).toEqual([...CAFES].sort())
		expect((await search('CAFÉ', '&_accents=exact')).names, 'exact is still case-insensitive').toEqual(
			[...CAFES].sort(),
		)
		expect((await search('bakkerij', '&_accents=exact')).names, 'control: an unaccented name').toEqual([PLAIN])
	})

	test.describe('the objects index page', () => {
		test.use({ storageState: STORAGE_STATE })

		test('typing cafe in the search box lists the cafés', async ({ page }) => {
			await page.goto(`${APP}/objects/${registerId}/${schemaId}/${uuids[PLAIN]}`, {
				waitUntil: 'domcontentloaded',
			})
			const box = page.getByRole('textbox', { name: 'Search' }).first()
			await expect(box).toBeVisible({ timeout: 30000 })

			// The list labels each row with the object's uuid, so the rows are
			// found by it rather than by the name the search matched; scoped to
			// the list's rows, because the opened object's uuid is also in the
			// detail pane beside it.
			const row = (naam: string) => page.locator('li').filter({ hasText: uuids[naam] })

			await box.fill('cafe')
			for (const name of CAFES) {
				await expect(row(name).first(), `${name} is listed`).toBeVisible({ timeout: 15000 })
			}
			await expect(row(REUNION)).toHaveCount(0)
			await expect(row(PLAIN)).toHaveCount(0)

			await box.fill('reunie')
			await expect(row(REUNION).first(), `${REUNION} is listed`).toBeVisible({ timeout: 15000 })
			await expect(row(CAFES[0])).toHaveCount(0)
		})
	})
})
