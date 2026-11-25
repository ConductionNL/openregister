import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE RECORD IMPORT, MEASURED: STREAMING, DRY RUN, IDENTITY AND FAILED ROWS.
 *
 * widget-record-import, section Verification (V1 to V6), against a running
 * instance through the administrator-only import routes:
 * - V1 a 5,000-row file over 3 targets reports 3 reference-cache misses and the
 *   rest hits, so the streaming path is engaged;
 * - V6 the same shape through the non-streaming bulk save reports no counters;
 * - V2 a file with one invalid row imports the other 999 and reports one failure;
 * - V3 a dry run over a populated register leaves the object count unchanged;
 * - V4 re-importing an unchanged file with an identifying column creates nothing;
 * - V5 the failed rows, exported, corrected and re-imported, change only those rows.
 *
 * V1 and V6 need reference validation on for the importing administrator. The
 * app setting `reference_validation_admin_bypass` (default on) skips it for an
 * administrator, and the import routes are administrator-only, so on an
 * instance with the bypass on both are skipped with that reason. The spec
 * probes for it with a dry run of one dangling reference rather than reading
 * the setting, which has no API.
 *
 * HERMETIC: it creates its own register, schemas and objects, and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 8)
const DANGLING = '00000000-0000-4000-8000-000000000000'
const DECISIONS = JSON.stringify({ code: 'code', naam: 'naam', doel: 'doel' })

/** The counters an import answers. */
interface ImportStatus {
	createdCount: number
	updatedCount: number
	unchangedCount: number
	failedCount: number
	referenceCacheHits: number
	referenceCacheMisses: number
}

/** A CSV of `code,naam,doel` rows. */
function csv(rows: Array<[string, string, string]>): Buffer {
	return Buffer.from(['code,naam,doel', ...rows.map((row) => row.join(','))].join('\n') + '\n', 'utf8')
}

test.describe.configure({ mode: 'serial' })

test.describe('record import verification', () => {
	test.setTimeout(300_000)

	let admin: APIRequestContext
	let registerId: string
	let targetSchemaId: string
	let rowSchemaId: string
	const targets: string[] = []
	let validating = false

	/** Post a file to the import route (or its dry run) and answer the status and the failed-row CSV. */
	async function importFile(file: Buffer, options: { dryRun?: boolean, decisions?: string } = {}): Promise<{ status: ImportStatus, failedRows: string }> {
		const route = `${API}/record-import/${registerId}/${rowSchemaId}${options.dryRun === true ? '/dry-run' : ''}`
		const res = await admin.post(route, {
			multipart: {
				file: { name: `import-${RUN}.csv`, mimeType: 'text/csv', buffer: file },
				decisions: options.decisions ?? DECISIONS,
				identifier: 'code',
			},
			timeout: 240_000,
		})
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		return { status: (body.status ?? body) as ImportStatus, failedRows: String(body.failedRows ?? '') }
	}

	/** How many rows the row schema holds. */
	async function rowCount(): Promise<number> {
		const res = await admin.get(`${API}/objects/${registerId}/${rowSchemaId}?_limit=1`)
		expect(res.ok(), await res.text()).toBe(true)
		return Number((await res.json()).total)
	}

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const target = await admin.post(`${API}/schemas`, {
			data: { title: `Import target ${RUN}`, slug: `importtarget${RUN}`, properties: { naam: { type: 'string' } } },
		})
		expect(target.ok(), await target.text()).toBe(true)
		targetSchemaId = String((await target.json()).id)

		const rows = await admin.post(`${API}/schemas`, {
			data: {
				title: `Import row ${RUN}`,
				slug: `importrow${RUN}`,
				required: ['naam'],
				properties: {
					code: { type: 'string' },
					naam: { type: 'string', minLength: 1, maxLength: 40 },
					doel: { type: 'string', $ref: targetSchemaId, validateReference: true },
				},
			},
		})
		expect(rows.ok(), await rows.text()).toBe(true)
		rowSchemaId = String((await rows.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Import ${RUN}`, slug: `import${RUN}`, schemas: [Number(targetSchemaId), Number(rowSchemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		for (const naam of ['a', 'b', 'c']) {
			const res = await admin.post(`${API}/objects/${registerId}/${targetSchemaId}`, { data: { naam } })
			expect(res.status(), await res.text()).toBe(201)
			targets.push((await res.json())['@self'].id)
		}

		// Is reference validation on for this administrator? One dangling row, dry run.
		const probe = await importFile(csv([[`probe-${RUN}`, 'probe', DANGLING]]), { dryRun: true })
		validating = probe.status.failedCount === 1
	})

	test.afterAll(async () => {
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const schemaId of [rowSchemaId, targetSchemaId]) {
			if (schemaId !== undefined) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		await admin.dispose()
	})

	test('V1: 5,000 rows over 3 targets report 3 cache misses and the rest hits', async () => {
		test.skip(!validating, 'reference_validation_admin_bypass is on: an administrator import validates no reference')
		const rows: Array<[string, string, string]> = Array.from({ length: 5000 }, (_, i) => [`v1-${i}`, `naam${i}`, targets[i % 3]])
		const { status } = await importFile(csv(rows))

		expect(status.createdCount).toBe(5000)
		expect(status.failedCount).toBe(0)
		expect(status.referenceCacheMisses).toBe(3)
		expect(status.referenceCacheHits).toBe(4997)
	})

	test('V6: the non-streaming bulk save reports no cache counters', async () => {
		test.skip(!validating, 'reference_validation_admin_bypass is on: an administrator import validates no reference')
		const objects = Array.from({ length: 300 }, (_, i) => ({ code: `v6-${i}`, naam: `n${i}`, doel: targets[i % 3] }))
		const res = await admin.post(`${API}/bulk/${registerId}/${rowSchemaId}/save`, { data: { objects } })
		expect(res.ok(), await res.text()).toBe(true)
		const text = await res.text()
		expect(text).not.toContain('referenceCacheHits')
		expect(text).not.toContain('referenceCacheMisses')
	})

	test('V2 and V5: one invalid row fails alone, and its corrected export changes only that row', async () => {
		const rows: Array<[string, string, string]> = Array.from({ length: 1000 }, (_, i) => [`v2-${i}`, `naam${i}`, targets[i % 3]])
		// Longer than the schema's maxLength: refused by the same validation the write path uses.
		rows[500] = ['v2-500', 'x'.repeat(60), targets[0]]
		const before = await rowCount()
		const { status, failedRows } = await importFile(csv(rows))

		expect(status.createdCount).toBe(999)
		expect(status.failedCount).toBe(1)
		expect(await rowCount()).toBe(before + 999)

		// The failed rows come back in the file's own columns plus a reason.
		const lines = failedRows.trim().split(/\r?\n/)
		expect(lines[0]).toBe('code,naam,doel,reason')
		expect(lines).toHaveLength(2)
		expect(lines[1].startsWith('v2-500,')).toBe(true)

		const corrected = Buffer.from(lines.map((line, index) => (index === 1 ? line.replace(/^v2-500,x{60},/, 'v2-500,gecorrigeerd,') : line)).join('\n') + '\n', 'utf8')
		const fixed = await importFile(corrected, { decisions: JSON.stringify({ code: 'code', naam: 'naam', doel: 'doel', reason: null }) })

		expect(fixed.status.createdCount).toBe(1)
		expect(fixed.status.updatedCount).toBe(0)
		expect(fixed.status.failedCount).toBe(0)
		expect(await rowCount()).toBe(before + 1000)
	})

	test('V3: a dry run over a populated register leaves the count unchanged', async () => {
		const before = await rowCount()
		expect(before).toBeGreaterThan(0)
		const rows: Array<[string, string, string]> = Array.from({ length: 200 }, (_, i) => [`v3-${i}`, `naam${i}`, targets[i % 3]])
		const { status } = await importFile(csv(rows), { dryRun: true })

		expect(status.createdCount).toBe(200)
		expect(await rowCount()).toBe(before)
	})

	test('V4: re-importing an unchanged file with an identifying column creates nothing', async () => {
		const rows: Array<[string, string, string]> = Array.from({ length: 300 }, (_, i) => [`v4-${i}`, `naam${i}`, targets[i % 3]])
		const first = await importFile(csv(rows))
		expect(first.status.createdCount).toBe(300)
		const before = await rowCount()

		const again = await importFile(csv(rows))

		expect(again.status.createdCount).toBe(0)
		expect(again.status.unchangedCount).toBe(300)
		expect(await rowCount()).toBe(before)
	})
})
