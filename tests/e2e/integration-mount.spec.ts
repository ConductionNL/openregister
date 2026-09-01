import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integration mount-check fixture — Phase K / K3 regression guard for
 * the Phase-A "bespoke UI is dead code" wiring bug (ADR-019).
 *
 * THE BUG THIS GUARDS AGAINST
 * ---------------------------
 * During the integration-leaves rollout the registry advertised every
 * provider correctly (the OCS capabilities + sub-resource API were
 * green — see integration-registry.spec.ts / leaf-verification.spec.ts),
 * AND the bespoke Cn<X>Tab components were present in the nc-vue bundle,
 * yet none of them rendered. The defect was purely in the host page:
 * ObjectDetails.vue's "Integrations" BTab never dispatched
 * `<component :is="provider.tab || CnIntegrationTab">`, so the bespoke
 * tabs were dead code. A duplicate <script setup> block had also
 * silently dropped the Options-API setup() that drains the registry,
 * leaving `integrationProviders` empty.
 *
 * The existing E2E specs are API-only — they never open a browser on an
 * object detail page, so they could not catch this. THIS spec does: it
 * navigates to a real object detail page (the ObjectDetails.vue surface,
 * NOT the isolated IntegrationsView used by leaf-screenshots.spec.ts),
 * opens the Integrations tab, and asserts that EACH advertised provider's
 * inner tab MOUNTS a non-empty component — i.e. the registry → host-page
 * dispatch is wired, not just that the descriptor exists in the bundle.
 *
 * WHY THIS IS A DOCUMENTED MANUAL / OPT-IN ENTRY POINT, NOT A CI JOB
 * -----------------------------------------------------------------
 * Like every other spec in tests/e2e/, this needs a live Nextcloud +
 * OpenRegister with the integration registry wired and at least one
 * saved object to open. There is no such environment in the PR CI lane
 * (the shared quality workflow has no NC container), so wiring this as a
 * required CI job would be a permanently-red gate. Instead it ships as
 * the documented `npm run test:e2e:integrations` entry point (see
 * package.json + tests/e2e/README.md) that runs against the dev
 * container. The spec self-skips gracefully when the registry isn't
 * wired or no object is reachable, so a casual `npx playwright test`
 * never hard-fails on a half-set-up box.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npm run test:e2e:integrations
 * or
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test \
 *     tests/e2e/integration-mount.spec.ts --project=chromium
 */
import { expect, test } from '@playwright/test'

/**
 * Fetch the registry's advertised providers from OCS capabilities. The
 * same source ObjectDetails.vue's `integrationProviders` ultimately
 * mirrors, so the UI tab strip should carry one tab per entry here.
 *
 * @param request Playwright request context (Basic auth pre-wired).
 * @return The integrations.providers array, or [] when not wired.
 */
async function fetchProviders(
	request: APIRequestContext,
): Promise<
	Array<{ id: string; label?: string; group?: string; enabled?: boolean }>
> {
	const response = await request.get(
		'/ocs/v2.php/cloud/capabilities?format=json',
		{
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		},
	)
	expect(response.status()).toBe(200)
	const body = await response.json()
	return body?.ocs?.data?.capabilities?.openregister?.integrations?.providers ?? []
}

/**
 * Resolve a {register, schema, objectId} triple that actually has a
 * saved object, so ObjectDetails.vue's `relationContext` is non-null
 * (the Integrations BTab is gated on it). Returns null when no object is
 * reachable — the caller then skips rather than hard-fails.
 *
 * @param request Playwright request context.
 */
async function pickObjectTriple(
	request: APIRequestContext,
): Promise<{ register: string; schema: string; objectId: string } | null> {
	// Prefer the seeded verification sandbox if present.
	const sandbox = await request.get(
		'/index.php/apps/openregister/api/registers?slug=integration-verification',
		{
			headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		},
	)
	if (sandbox.ok()) {
		const body = await sandbox.json()
		const reg = (body.results ?? []).find(
			(r: { slug?: string }) => r.slug === 'integration-verification',
		)
		if (reg?.id && Array.isArray(reg.schemas) && reg.schemas.length > 0) {
			const schemaId = reg.schemas[0]
			const objects = await request.get(
				`/index.php/apps/openregister/api/objects/${reg.id}/${schemaId}?_limit=1`,
				{
					headers: {
						Accept: 'application/json',
						'OCS-APIRequest': 'true',
					},
				},
			)
			if (objects.ok()) {
				const objBody = await objects.json()
				const first = (objBody.results ?? [])[0]
				if (first?.id) {
					return {
						register: String(reg.id),
						schema: String(schemaId),
						objectId: String(first.id),
					}
				}
			}
		}
	}
	// Fall back to any register/schema that has at least one object.
	const fallback = await request.get(
		'/index.php/apps/openregister/api/objects/1/1?_limit=1',
		{
			headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		},
	)
	if (fallback.ok()) {
		const body = await fallback.json()
		const first = (body.results ?? [])[0]
		if (first) {
			const meta = first['@self'] ?? {}
			return {
				register: String(meta.register ?? '1'),
				schema: String(meta.schema ?? '1'),
				objectId: String(first.id ?? meta.id ?? ''),
			}
		}
	}
	return null
}

/**
 * Open the object detail page (ObjectDetails.vue, served by ObjectsIndex)
 * via the deep-link route and wait for the in-page registry to flush.
 *
 * @param page Playwright page (authenticated via storageState).
 * @param baseURL The NC base URL.
 * @param triple The {register, schema, objectId} to open.
 * @return The provider ids the in-page registry advertises.
 */
async function openObjectDetail(
	page: Page,
	baseURL: string,
	triple: { register: string; schema: string; objectId: string },
): Promise<string[]> {
	// vue-router runs in HASH mode (src/main.js, since the #133 fix). A
	// path-form deep-link is rewritten by the hash router to `.../objects#/`
	// and renders the dashboard — verified empirically 2026-07-27. The hash
	// URL dispatches `objectDetail` (route name in the manifest) to
	// ObjectsIndex and its param-watch primes the object store.
	await page.goto(
		`${baseURL}/index.php/apps/openregister/objects/${triple.register}/${triple.schema}/${triple.objectId}`,
		// `networkidle` never settles on Nextcloud (ADR-074 rule 4): the
		// long-poll / notification channels keep a request in flight for the
		// life of the page, so it always burns its timeout. The readiness
		// signal that matters is the registry flush asserted just below.
		{ waitUntil: 'domcontentloaded' },
	)

	// Wait until nc-vue's in-page registry has been drained onto window.
	// (installIntegrationRegistry + registerBuiltin + registerLeaf run in
	// main.js bootstrap.) Tolerate slow boot.
	await page.waitForFunction(
		() => {
			const list = (
				window as {
					OCA?: {
						OpenRegister?: {
							integrations?: { list?: () => Array<{ id: string }> }
						}
					}
				}
			).OCA?.OpenRegister?.integrations?.list?.()
			return Array.isArray(list) && list.length > 0
		},
		{ timeout: 30_000 },
	)

	return page.evaluate(() => {
		const list = (
			window as {
				OCA?: {
					OpenRegister?: {
						integrations?: { list?: () => Array<{ id: string }> }
					}
				}
			}
		).OCA?.OpenRegister?.integrations?.list?.()
		return Array.isArray(list) ? list.map((p) => p.id) : []
	})
}

test.describe('Integration tabs MOUNT on the object detail page (K3 / Phase-A regression guard)', () => {
	// 24 providers, each clicked + asserted; allow generous headroom for
	// the first object load + registry flush.
	test.setTimeout(180_000)

	test('the Integrations tab is present and dispatches a component per provider', async ({
		page,
		request,
		baseURL,
	}) => {
		const providers = await fetchProviders(request)
		test.skip(
			providers.length === 0,
			'integration registry not wired on this deploy',
		)

		const triple = await pickObjectTriple(request)
		test.skip(
			triple === null,
			'no saved object reachable to open ObjectDetails.vue against',
		)

		const registeredIds = await openObjectDetail(page, baseURL!, triple!)
		expect(
			registeredIds.length,
			'in-page registry should advertise providers',
		).toBeGreaterThan(0)

		// 1. The host-page "Integrations" BTab must exist. Its absence is
		//    the symptom of the empty-`integrationProviders` half of the
		//    Phase-A bug (the drained-registry setup() never ran).
		const integrationsTab = page.locator('role=tab[name="Integrations"]').first()
		await expect(
			integrationsTab,
			'ObjectDetails.vue must render an "Integrations" tab when providers are advertised',
		).toBeVisible({ timeout: 15_000 })
		await integrationsTab.click()

		// 2. The inner per-provider tab strip must carry one tab per
		//    advertised provider. Walk each, click it, and assert the
		//    dispatched <component :is="provider.tab || CnIntegrationTab">
		//    actually MOUNTED something — not an empty pane. An empty pane
		//    is the other half of the Phase-A bug (the dispatch was never
		//    wired, so the bespoke tabs were dead code).
		const mountFailures: string[] = []
		// Scope to the CnIntegrationWidget container — the outer ObjectDetails
		// BTabs strip ALSO has tabs named "Files" / "Emails" / "Contacts" /
		// "Audit Trail" alongside the registry, so `role=tab[name="Files"]`
		// would match the outer strip first and clicking it would close the
		// Integrations panel (hiding every subsequent inner tab from the
		// a11y tree). The widget exposes a stable per-provider data-testid
		// (`cn-integration-widget-tab-{id}`) that we target directly.
		const widget = page.locator('.cn-integration-widget')
		for (const id of registeredIds) {
			const provider = providers.find((p) => p.id === id)
			const tabName = provider?.label || id
			const innerTab = widget.locator(
				`[data-testid="cn-integration-widget-tab-${id}"]`,
			)
			if ((await innerTab.count()) === 0) {
				mountFailures.push(`${id}: no inner tab rendered for "${tabName}"`)
				continue
			}
			await innerTab.click()
			// The active panel for the widget lives at `[role="tabpanel"]`
			// inside the same `.cn-integration-widget`. Scope here too —
			// outer BTabs panels would otherwise match first.
			const activePanel = widget.locator('[role="tabpanel"]').first()
			const mounted = await activePanel
				.evaluate((el) => {
					// A mounted Vue component leaves at least one element child
					// (the integration tab root). Whitespace-only / comment-only
					// panels mean nothing dispatched.
					return (
						el.querySelector('*') !== null
						&& (el.textContent || '').trim().length >= 0
						&& el.children.length > 0
					)
				})
				.catch(() => false)
			if (!mounted) {
				mountFailures.push(
					`${id}: Integrations sub-tab "${tabName}" mounted no component (dead-code dispatch)`,
				)
			}
		}

		expect(
			mountFailures,
			`Integration tabs that failed to mount a component on ObjectDetails.vue:\n  ${mountFailures.join('\n  ')}`,
		).toEqual([])
	})
})
