/**
 * Per-leaf verification harness — runs against a live OpenRegister and
 * records, per provider:
 *
 *   - OCS capabilities metadata (id, group, enabled, storage, surfaces,
 *     authStatus, requiredApp)
 *   - Sub-resource probe result: HTTP status, response shape, latency,
 *     first-item sample
 *   - Pass/fail verdict against the documented contract
 *
 * Output:
 *
 *   tests/e2e/leaf-verification.json — machine-readable per-leaf report
 *
 * The verification doc generator (scripts/generate-leaf-verification.js)
 * reads that JSON and updates each leaf's docs page with a verified
 * timestamp + endpoint sample. Keeps the per-leaf docs honest.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test \
 *     tests/e2e/leaf-verification.spec.ts --project=chromium
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

interface ProviderReport {
	id: string
	timestamp: string
	metadata: {
		label?: string
		group?: string
		enabled?: boolean
		requiredApp?: string | null
		storageStrategy?: string
		surfaces?: string[]
		authStatus?: { status?: string; authStatus?: string }
	}
	probe: {
		url: string
		status: number
		latencyMs: number
		responseShape: 'list-envelope' | 'bare-array' | 'passthrough-envelope' | 'error-envelope' | 'unknown'
		sample: unknown
	}
	verdict: 'pass' | 'fail'
	notes: string[]
}

const REPORT_PATH = path.resolve(__dirname, 'leaf-verification.json')

async function fetchProviders(request: APIRequestContext): Promise<Array<Record<string, unknown>>> {
	const response = await request.get('/ocs/v2.php/cloud/capabilities?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	return body?.ocs?.data?.capabilities?.openregister?.integrations?.providers ?? []
}

async function pickObjectTriple(request: APIRequestContext): Promise<{ register: string; schema: string; objectId: string }> {
	const listing = await request.get('/index.php/apps/openregister/api/objects/1/1?_limit=1', {
		headers: { Accept: 'application/json' },
	})
	if (listing.ok()) {
		const body = await listing.json()
		const first = (body.results ?? [])[0]
		if (first) {
			const meta = first['@self'] ?? {}
			return {
				register: meta.register ?? '1',
				schema: meta.schema ?? '1',
				objectId: first.id ?? meta.id ?? '00000000-0000-0000-0000-000000000000',
			}
		}
	}
	return { register: '1', schema: '1', objectId: '00000000-0000-0000-0000-000000000000' }
}

function classifyShape(body: unknown): ProviderReport['probe']['responseShape'] {
	if (Array.isArray(body)) return 'bare-array'
	if (typeof body !== 'object' || body === null) return 'unknown'
	const obj = body as Record<string, unknown>
	if (Array.isArray(obj.results)) return 'list-envelope'
	if (Array.isArray(obj.items)) return 'list-envelope'
	if (Array.isArray(obj.pageSummaries) || Array.isArray(obj.searchResults)) return 'passthrough-envelope'
	if (obj.message || obj.error) return 'error-envelope'
	return 'unknown'
}

function firstItem(body: unknown): unknown {
	if (Array.isArray(body)) return body[0] ?? null
	if (typeof body !== 'object' || body === null) return null
	const obj = body as Record<string, unknown>
	for (const key of ['results', 'items', 'pageSummaries', 'searchResults']) {
		const arr = obj[key]
		if (Array.isArray(arr) && arr.length > 0) return arr[0]
	}
	return null
}

test.describe('Leaf verification harness', () => {
	const reports: ProviderReport[] = []

	test('captures per-leaf verification data and writes the report', async ({ request }) => {
		const providers = await fetchProviders(request)
		expect(providers.length, 'registry should advertise providers').toBeGreaterThan(0)

		const { register, schema, objectId } = await pickObjectTriple(request)

		for (const p of providers) {
			const id = p.id as string
			const url = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/integrations/${id}`
			const start = Date.now()
			const response = await request.get(url, { headers: { Accept: 'application/json' } })
			const latencyMs = Date.now() - start
			const status = response.status()
			let body: unknown = null
			try { body = await response.json() } catch { /* non-json */ }

			const shape = classifyShape(body)
			const sample = firstItem(body)

			const notes: string[] = []
			if (status >= 500) notes.push(`HTTP ${status} — server error`)
			if (status === 503) notes.push('degraded source (expected for unconfigured external)')
			if (status === 401 || status === 403) notes.push('auth required / forbidden')
			if (status >= 400 && status < 500 && status !== 401 && status !== 403 && status !== 503) {
				notes.push(`HTTP ${status} — client error (object likely missing on dev container)`)
			}

			const verdict: ProviderReport['verdict'] = status < 500 ? 'pass' : 'fail'

			reports.push({
				id,
				timestamp: new Date().toISOString(),
				metadata: {
					label: p.label as string,
					group: p.group as string,
					enabled: p.enabled as boolean,
					requiredApp: (p.requiredApp ?? null) as string | null,
					storageStrategy: p.storageStrategy as string,
					surfaces: p.surfaces as string[],
					authStatus: p.authStatus as ProviderReport['metadata']['authStatus'],
				},
				probe: { url, status, latencyMs, responseShape: shape, sample },
				verdict,
				notes,
			})
		}

		fs.writeFileSync(REPORT_PATH, JSON.stringify({
			generated: new Date().toISOString(),
			baseUrl: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
			objectTriple: { register, schema, objectId },
			providerCount: reports.length,
			passCount: reports.filter(r => r.verdict === 'pass').length,
			failCount: reports.filter(r => r.verdict === 'fail').length,
			providers: reports.sort((a, b) => a.id.localeCompare(b.id)),
		}, null, 2))

		// Hard contract: zero 5xx across the whole registry.
		const fails = reports.filter(r => r.verdict === 'fail')
		expect(fails, `5xx providers: ${fails.map(f => f.id).join(', ')}`).toEqual([])
	})
})
