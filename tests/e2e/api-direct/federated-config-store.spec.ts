import type { APIRequestContext } from '@playwright/test'

/**
 * Federated config STORE surface — publish/discover/sign/RBAC, e2e via HTTP.
 *
 * Covers the store foundation beyond the basic bundle/install:
 *  - the instance exposes a signing public key,
 *  - discovery searches GitHub by a type's topic,
 *  - installing is gated by per-org RBAC (install groups),
 *  - signing enforcement (a trusted-keys allowlist) refuses unsigned bundles,
 *  - publish refuses when the user has selected no store credential.
 *
 * The RBAC/enforcement toggles go through `occ` in the container named by
 * NC_CONTAINER, plus a restart to clear the appconfig cache; with no
 * NC_CONTAINER set those tests skip.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
import { expect, test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolveBaseUrl, resolveContainer } from '../base-url.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
// ⚠️ 'restart', not the default 'exec'. This spec calls
// `docker restart ${CONTAINER}`, which bounces an environment that bind-mounts
// several developers' real working trees — mid-session, with no warning to
// them. Sibling specs may `occ` in the shared container by default because one
// named command is recoverable; a restart is not. So this one resolves to null
// there, and skips, unless NC_ALLOW_SHARED_RESTART=1 says otherwise.
const CONTAINER = resolveContainer('restart')
const runId = `e2e-store-${Date.now()}`

function occ(args: string): string | null {
	if (CONTAINER === null) {
		return null
	}
	try {
		return execSync(`docker exec -u www-data ${CONTAINER} php occ ${args}`, {
			encoding: 'utf8',
		})
	} catch {
		return null
	}
}

function restartAndWait(): void {
	if (CONTAINER === null) {
		return
	}
	try {
		execSync(`docker restart ${CONTAINER}`, { stdio: 'ignore' })
		for (let i = 0; i < 40; i++) {
			try {
				// ⚠️ was a hardcoded `http://localhost:8080` — the shared dev
				// container. Poll the instance actually under test.
				const code = execSync(
					`curl -s -o /dev/null -w '%{http_code}' ${resolveBaseUrl()}/status.php`,
					{ encoding: 'utf8' },
				)
				if (code.trim() === '200') return
			} catch {
				/* keep polling */
			}
			execSync('sleep 3')
		}
	} catch {
		/* best effort */
	}
}

test.describe('Federated config store', () => {
	let regId: number
	let schId: number
	let type: string
	const objects: string[] = []

	test.beforeAll(async ({ request }) => {
		const sch = await (
			await request.post(`${API}/schemas`, {
				headers: JSON_HEADERS,
				data: {
					title: `Store ${runId}`,
					properties: { name: { type: 'string' } },
					configuration: { 'x-openregister-shareable': true },
				},
			})
		).json()
		schId = sch.id
		const reg = await (
			await request.post(`${API}/registers`, {
				headers: JSON_HEADERS,
				data: { title: `Store Reg ${runId}`, schemas: [schId] },
			})
		).json()
		regId = reg.id
		type = `${reg.slug}.${sch.slug}`
		const obj = await (
			await request.post(`${API}/objects/${regId}/${schId}`, {
				headers: JSON_HEADERS,
				data: { name: 'Alpha' },
			})
		).json()
		objects.push(obj['@self'].id)
	})

	test.afterAll(async ({ request }) => {
		for (const u of objects)
			await request
				.delete(`${API}/objects/${regId}/${schId}/${u}`)
				.catch(() => {})
		await request.delete(`${API}/registers/${regId}`).catch(() => {})
		await request.delete(`${API}/schemas/${schId}`).catch(() => {})
	})

	async function bundle(request: APIRequestContext): Promise<any> {
		return (
			await request.post(`${API}/federated-config/bundle`, {
				headers: JSON_HEADERS,
				data: { type, selection: {} },
			})
		).json()
	}

	test('the instance exposes a signing public key', async ({ request }) => {
		const resp = await request.get(`${API}/federated-config/public-key`)
		expect(resp.status()).toBe(200)
		const key = (await resp.json()).publicKey
		expect(typeof key).toBe('string')
		// A base64 Ed25519 public key is 32 bytes → 44 base64 chars.
		expect(key.length).toBe(44)
	})

	test('discovery searches GitHub by topic', async ({ request }) => {
		const resp = await request.get(
			`${API}/federated-config/discover?topic=openbuild-app`,
		)
		expect(resp.status()).toBe(200)
		const results = (await resp.json()).results
		expect(Array.isArray(results)).toBe(true)
		// Shape check on whatever the search returns (may be 0 on a rate-limited run).
		for (const card of results) {
			expect(card).toHaveProperty('repo')
			expect(card).toHaveProperty('url')
			expect(card).toHaveProperty('stars')
		}
	})

	test('discovery needs a topic', async ({ request }) => {
		expect(
			(await request.get(`${API}/federated-config/discover`)).status(),
		).toBe(400)
	})

	test('publish refuses when no store credential is selected', async ({
		request,
	}) => {
		// The admin session has no federated-config-credential preference set, so
		// publish must refuse rather than guess a credential.
		const resp = await request.post(`${API}/federated-config/publish`, {
			headers: JSON_HEADERS,
			data: {
				type,
				selection: {},
				repo: 'ConductionNL/store-e2e',
				path: 'bundle.json',
			},
		})
		expect(resp.status()).toBe(400)
		expect((await resp.json()).error).toContain('credential')
	})

	test('signing enforcement refuses an unsigned bundle', async ({ request }) => {
		const set = occ(
			'config:app:set openregister federated_config_trusted_keys --value="AAAAsomeotherkey="',
		)
		test.skip(set === null, 'occ not reachable (not on the dev host)')
		restartAndWait()
		try {
			const b = await bundle(request)
			const denied = await request.post(`${API}/federated-config/install`, {
				headers: JSON_HEADERS,
				data: { type, bundle: b, source: 'ConductionNL/x' },
			})
			expect(
				denied.status(),
				'unsigned bundle refused under enforcement',
			).toBe(403)
			expect((await denied.json()).error).toContain('trusted')
		} finally {
			occ('config:app:delete openregister federated_config_trusted_keys')
			restartAndWait()
		}
	})

	test('unsigned install succeeds when signing is not enforced', async ({
		request,
	}) => {
		const b = await bundle(request)
		const ok = await request.post(`${API}/federated-config/install`, {
			headers: JSON_HEADERS,
			data: { type, bundle: b, source: 'ConductionNL/x' },
		})
		expect(ok.status()).toBe(200)
		const installed = (await ok.json()).installed ?? []
		installed.forEach((u: string) => objects.push(u))
	})
})
