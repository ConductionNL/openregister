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

/**
 * Resolve a {register, schema, objectId} triple to probe against.
 *
 * Order of preference:
 *
 *   1. The `integration-verification` / `verification-probe` register
 *      + schema seeded for this harness — if it exists and has at least
 *      one object, probe that. This yields real list envelopes from
 *      every leaf.
 *   2. Any other register / schema with at least one object — first
 *      register with a non-empty listing.
 *   3. A synthetic UUID against register=1 / schema=1 — exercises the
 *      missing-object branch (HTTP 412). Still non-5xx, still useful
 *      as a smoke check.
 *
 * @param request Playwright request context.
 */
async function pickObjectTriple(request: APIRequestContext): Promise<{ register: string; schema: string; objectId: string }> {
	// 1. Seeded sandbox register first.
	const sandboxRegisters = await request.get('/index.php/apps/openregister/api/registers?slug=integration-verification', {
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
	if (sandboxRegisters.ok()) {
		const body = await sandboxRegisters.json()
		const reg = (body.results ?? []).find((r: { slug?: string }) => r.slug === 'integration-verification')
		if (reg?.id && Array.isArray(reg.schemas) && reg.schemas.length > 0) {
			const schemaId = reg.schemas[0]
			const objects = await request.get(`/index.php/apps/openregister/api/objects/${reg.id}/${schemaId}?_limit=1`, {
				headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
			})
			if (objects.ok()) {
				const objBody = await objects.json()
				const first = (objBody.results ?? [])[0]
				if (first?.id) {
					return { register: String(reg.id), schema: String(schemaId), objectId: first.id }
				}
			}
		}
	}
	// 2. Fall back to any register that happens to have objects.
	const fallback = await request.get('/index.php/apps/openregister/api/objects/1/1?_limit=1', {
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
	if (fallback.ok()) {
		const body = await fallback.json()
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
	// 3. Synthetic — exercises the precondition branch.
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

	// 24 providers × ~700-1700ms each → ~20s typical, with headroom for
	// the lazy-cache warmup on the first /files hit (4s on a cold cache).
	test.setTimeout(120_000)

	test('captures per-leaf verification data and writes the report', async ({ request }) => {
		const providers = await fetchProviders(request)
		expect(providers.length, 'registry should advertise providers').toBeGreaterThan(0)

		const { register, schema, objectId } = await pickObjectTriple(request)

		for (const p of providers) {
			const id = p.id as string
			const url = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/integrations/${id}`
			const start = Date.now()
			// `OCS-APIRequest: true` bypasses NC's session-CSRF guard for
			// programmatic clients; without it the controller short-
			// circuits with HTTP 412 before the provider ever runs.
			const response = await request.get(url, {
				headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
			})
			const latencyMs = Date.now() - start
			const status = response.status()
			let body: unknown = null
			try { body = await response.json() } catch { /* non-json */ }

			const shape = classifyShape(body)
			const sample = firstItem(body)

			const notes: string[] = []
			// 503 is the documented "degraded source" status — the
			// provider is wired but the upstream connector isn't
			// configured. Per the registry contract, that counts as
			// pass (the provider responded with a structured cause,
			// not a stack trace). Anything else >= 500 means the
			// provider crashed.
			if (status === 503) notes.push('degraded source (expected for unconfigured external)')
			else if (status >= 500) notes.push(`HTTP ${status} — server error`)
			if (status === 401 || status === 403) notes.push('auth required / forbidden')
			if (status >= 400 && status < 500 && status !== 401 && status !== 403) {
				notes.push(`HTTP ${status} — client error (object likely missing on dev container)`)
			}

			const verdict: ProviderReport['verdict'] = status < 500 || status === 503 ? 'pass' : 'fail'

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

		// Hard contract: zero non-503 5xx across the whole registry.
		// A 503 is the documented "degraded source" response; anything
		// else >= 500 means the provider crashed.
		const fails = reports.filter(r => r.verdict === 'fail').map(f => `${f.id}(${f.probe.status})`)
		expect(fails, `provider crashes (5xx, excluding 503): ${fails.join(', ')}`).toEqual([])
	})
})
