import type { APIRequestContext, Page } from '@playwright/test'
import type { SeededObject, SeededRegister, SeededSchema } from '../_fixtures.ts'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GENUINE behavioural UI e2e for OpenRegister's DETAIL page hosts — the
 * per-record pages under `src/views/**` that a list page links into.
 *
 * WHAT THIS PROVES, AND WHY IT SEEDS
 * ----------------------------------
 * A detail page needs a record. The tempting shape is to navigate to whatever
 * happens to exist and wrap the assertions in
 * `if (await x.isVisible().catch(() => false)) { … }` — which passes without
 * asserting anything the moment the instance is empty. `tests/e2e/ci/
 * playwright.config.ts` names that shape as admission criterion 3 and refuses
 * it, so every page here SEEDS the record it needs through the documented OR
 * REST controllers, asserts UNCONDITIONALLY against values only this run could
 * have written, and deletes exactly what it created:
 *
 *   ApplicationDetails  POST/DELETE /api/applications      (ApplicationsController)
 *   FlowDetailPage      POST/DELETE /api/flows             (FlowController)
 *   ReportView          POST/DELETE /api/objects/{r}/{s}   (ObjectsController) —
 *                       a dashboard is an ordinary object in the `reports`
 *                       register / `dashboard` schema (src/store/modules/reports.js)
 *   SchemaDetails       POST/DELETE /api/schemas           (SchemasController)
 *
 * Because the assertions name the seeded title / version / node label, they
 * fail on a page that renders its chrome and loses its record — which is the
 * failure a render-only smoke test cannot see.
 *
 * ⚠️ TWO PAGES ARE COVERED MORE NARROWLY THAN THE REST, DELIBERATELY.
 *
 *   1. `SchemaDetails` NEVER LOADS ITS ROUTE ID. Its `mounted()` reads
 *      `dashboardStore` and `schemaStore.schemaItem` — it never calls anything
 *      with `$route.params.id`, and no router hook hydrates `schemaStore` (the
 *      only `setSchemaItem()` callers are sidebars and list rows). On a deep
 *      link `schemaItem` is still its initial `false`, so
 *      `{{ schemaStore.schemaItem.title }}` renders EMPTY — `false.title` is
 *      `undefined`, not a throw, so the page looks fine and is anonymous.
 *      Asserting the seeded schema's title here would therefore assert a
 *      behaviour the app does not have. This spec asserts what the page really
 *      does own — its tab strip, its chart cards and its actions menu — and
 *      says so here rather than dressing a weaker claim up as a stronger one.
 *      Fixing the hydration is a code change, not a test change; when it lands,
 *      the title assertion belongs in the ApplicationDetails shape below.
 *
 *   2. `EntityDetail` (`src/views/entities/EntityDetail.vue`) IS NOT COVERED
 *      HERE AT ALL. `openregister_entities` rows are detected PII, and the
 *      routed surface is read-only: `appinfo/routes.php` registers
 *      `gdprEntities#index|show|destroy|getTypes|getCategories|getStats` and no
 *      create. The only writer is `fileText#addManualEntity`, which
 *      `ManualEntityService` refuses unless the target Nextcloud file already
 *      has EXTRACTED CHUNKS — i.e. it needs an uploaded file plus a completed
 *      text-extraction run, neither of which is hermetic. Rather than seed
 *      nothing and guard the assertions, the page is left out and the gap is
 *      recorded — in the component itself, as the reason-bearing
 *      `@visual exclude` in `EntityDetail.vue`, so the waiver sits where a
 *      future create endpoint would be noticed.
 *
 * Methodology matches `spec-coverage/core-list-pages.spec.ts`: navigate the
 * real hash route through the manifest shell, assert visible UI through the
 * rendered DOM, and fail on any OR-origin console error or >=400 response while
 * filtering core-Nextcloud noise.
 *
 * Routes are imported by COMPONENT NAME from `tests/e2e/_page-routes.ts`, so
 * the component each test drives is a fact recorded in executable code rather
 * than a claim made in a comment.
 *
 * @e2e openspec/specs/no-code-app-builder/spec.md
 * @e2e openspec/specs/frontend-app-bootstrap/spec.md
 * @e2e openspec/specs/flow-engine/spec.md
 * @e2e openspec/specs/rapportage-bi-export/spec.md
 */
import { expect, test } from '@playwright/test'
import * as path from 'path'
import {
	createObject,
	createSchema,
	deleteObject,
	deleteRegister,
	deleteSchema,
	linkSchemaToRegister,
	makeRunId,
	twoPropertySchema,
} from '../_fixtures.ts'
// Routes are imported by COMPONENT NAME (see tests/e2e/_page-routes.ts): the
// binding records which page host each route mounts, which a bare path string
// cannot say. Also what makes this suite legible to gate-26.
import {
	ApplicationDetails,
	FlowDetailPage,
	ReportView,
	SchemaDetails,
} from '../_page-routes.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')
const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
// `OCS-APIRequest: true` is what tells Nextcloud this is an API call rather
// than a browser form post, which is the condition for skipping the CSRF check
// on a route that does not declare `@NoCSRFRequired` — `FlowController::create`,
// `update` and `destroy` are exactly those. Measured and documented in
// tests/e2e/flow-engine.spec.ts: without it every flow write answers 412 even
// with no session cookie. Sent per-request so the project's Basic-auth
// `extraHTTPHeaders` (which a `test.use` override would REPLACE) survives.
const API_WRITE_HEADERS = { ...JSON_HEADERS, 'OCS-APIRequest': 'true' }

const RUN_ID = makeRunId()

// Console-error / HTTP substrings that are core-Nextcloud noise, not OR
// regressions. Kept identical to spec-coverage/core-list-pages.spec.ts — see
// the reasoning recorded there for each entry.
const NOISE = [
	'user_status',
	'heartbeat',
	'Failed to load user status',
	'/apps/activity/',
	'/notifications/api/',
	'dashboard/api/v1/widgets',
	'[AppInit]',
	'Failed to fetch',
	'Failed to load data',
	'Failed to load resource: the server responded with a status of 5',
	'Failed to load resource: the server responded with a status of 404',
	'/apps/hermiq/',
]

function isNoise(text: string): boolean {
	return NOISE.some((n) => text.includes(n))
}

/** Attach console-error + >=400 collectors that ignore core-NC noise. */
function trackErrors(page: Page): { console: string[]; http: string[] } {
	const errors = { console: [] as string[], http: [] as string[] }
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.console.push(t.slice(0, 160))
	})
	page.on('response', (r) => {
		if (r.status() < 400) return
		const u = r.url()
		if (!isNoise(u))
			errors.http.push(`${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`)
	})
	return errors
}

/** Assert the collectors stayed empty — named, so a failure says which. */
function expectNoErrors(errors: { console: string[]; http: string[] }): void {
	expect(
		errors.console,
		`console errors: ${errors.console.join(' | ')}`,
	).toHaveLength(0)
	expect(errors.http, `>=400: ${errors.http.join(' | ')}`).toHaveLength(0)
}

/** Navigate to an OR route via the manifest shell and wait for content mount. */
async function gotoPage(page: Page, route: string): Promise<void> {
	// HASH form — the router runs in hash mode (src/main.js). A path-form
	// deep-link (`/apps/openregister/applications/12`) is rewritten by the hash
	// router and renders the DASHBOARD, not the target page.
	await page.goto(`/index.php/apps/openregister${route}`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('#header, header.header-appcontainer', {
		timeout: 25_000,
	})
	await page.waitForSelector('#app-content-vue, .app-content, main', {
		timeout: 20_000,
	})
	// The manifest shell renders the chrome first and the routed component a
	// beat later, so race a heading against a visible content button rather
	// than sleeping a fixed amount and hoping.
	await Promise.race([
		page
			.locator('#app-content-vue h1, .app-content h1, main h1')
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
		page
			.locator('.app-content button, main button')
			.first()
			.waitFor({ state: 'visible', timeout: 15_000 }),
	]).catch(() => {})
	await page.waitForTimeout(800)
}

/**
 * Find a register / schema by its slug, or null.
 *
 * Used only by the ReportView fixture, whose register and schema slugs are
 * NOT run-namespaced — see the note on that describe.
 */
async function findBySlug(
	request: APIRequestContext,
	collection: 'registers' | 'schemas',
	slug: string,
): Promise<Record<string, any> | null> {
	const resp = await request.get(`${API}/${collection}?_limit=1000`, {
		headers: { Accept: 'application/json' },
	})
	expect(resp.status(), `GET /api/${collection}`).toBe(200)
	const body = await resp.json()
	const rows: Array<Record<string, any>> = body.results ?? []
	return rows.find((row) => String(row.slug ?? '') === slug) ?? null
}

test.describe('detail-pages — seeded detail hosts render their own record', () => {
	test.use({ storageState: STORAGE_STATE })

	// ─────────────────────────────────────────────────────────────────────────
	// ApplicationDetails — `src/views/application/ApplicationDetails.vue`
	//
	// The one detail host in this file that loads itself: `mounted()` reads
	// `$route.params.id` and calls `applicationStore.getApplication(id)`. So the
	// seeded name/version/status ARE assertable, and they are what make this a
	// test of the page rather than of the shell.
	// ─────────────────────────────────────────────────────────────────────────
	test.describe('ApplicationDetails', () => {
		const NAME = `E2E Application ${RUN_ID}`
		const DESCRIPTION = `Seeded by detail-pages.spec.ts (${RUN_ID})`
		const VERSION = '1.2.3'
		let applicationId: number | null = null

		test.beforeAll(async ({ request }) => {
			const resp = await request.post(`${API}/applications`, {
				headers: JSON_HEADERS,
				data: {
					name: NAME,
					description: DESCRIPTION,
					version: VERSION,
					active: true,
				},
			})
			expect(
				resp.status(),
				`POST /api/applications: ${(await resp.text()).slice(0, 200)}`,
			).toBe(201)
			const body = await resp.json()
			applicationId = body.id ?? null
			expect(
				applicationId,
				'the created application must have an id',
			).toBeTruthy()
		})

		test.afterAll(async ({ request }) => {
			if (applicationId !== null) {
				await request
					.delete(`${API}/applications/${applicationId}`)
					.catch(() => {})
			}
		})

		test('renders the seeded application name, version and active status', async ({
			page,
		}) => {
			const e = trackErrors(page)
			await gotoPage(page, ApplicationDetails(applicationId as number))

			// The <h1> is `applicationItem.name` — an empty store renders the
			// loading spinner and a failed fetch renders "Error loading
			// application", so this single assertion covers the whole load path.
			await expect(
				page.getByRole('heading', { name: NAME }).first(),
				'the application name did not render — the route id never reached the store',
			).toBeVisible({ timeout: 15_000 })

			// Fields that only a successful round-trip can produce. Scoped to the
			// detail header so the badge assertions cannot be satisfied by an
			// unrelated "Active" somewhere in the Nextcloud chrome.
			const header = page.locator('.headerTitleSection')
			await expect(header.getByText(`v${VERSION}`)).toBeVisible()
			await expect(page.getByText(DESCRIPTION).first()).toBeVisible()
			// `active: true` was seeded, so the page must say Active, not Inactive.
			await expect(header.getByText('Active', { exact: true })).toBeVisible()
			await expect(header.getByText('Inactive', { exact: true })).toHaveCount(
				0,
			)

			// The page's own primary control.
			await expect(
				page.getByRole('button', { name: /Back/i }).first(),
			).toBeVisible()

			expectNoErrors(e)
		})
	})

	// ─────────────────────────────────────────────────────────────────────────
	// FlowDetailPage — `src/views/flows/FlowDetailPage.vue`
	//
	// A one-line host over `CnFlowDetail`, whose `mounted()` calls
	// `useFlowStore().load({ id })` — the flow list is fetched and `open(id)`
	// puts THAT flow's nodes on the canvas. So a node card carrying the seeded
	// node's config is proof the route id selected the right document.
	//
	// One terminal `openregister.end` node is the smallest flow this app calls
	// valid (see tests/e2e/ci/flow-controls.spec.ts on why a lone non-terminal
	// node is refused at run time). `CnFlowDetail.nodeLabel()` renders the first
	// non-empty config key as `key: value`, and `EndNode::configKeys()` accepts
	// `error` and `message` — so `message` is a real key, not one invented to
	// give the test something to read.
	// ─────────────────────────────────────────────────────────────────────────
	test.describe('FlowDetailPage', () => {
		const NAME = `E2E Flow ${RUN_ID}`
		const NODE_MESSAGE = `stopped by ${RUN_ID}`
		let flowId: string | null = null

		test.beforeAll(async ({ request }) => {
			const resp = await request.post(`${API}/flows`, {
				headers: API_WRITE_HEADERS,
				data: {
					name: NAME,
					description: 'Seeded by detail-pages.spec.ts',
					app: 'openregister',
					trigger: 'manual',
					nodes: [
						{
							id: `end-${RUN_ID}`,
							type: 'openregister.end',
							x: 120,
							y: 120,
							start: true,
							config: { message: NODE_MESSAGE },
						},
					],
					edges: [],
				},
			})
			expect(
				resp.status(),
				`POST /api/flows: ${(await resp.text()).slice(0, 200)}`,
			).toBe(201)
			flowId = String((await resp.json())?.id ?? '')
			expect(flowId, 'the created flow came back without a uuid').toMatch(
				/^[0-9a-f-]{8,}$/,
			)
		})

		test.afterAll(async ({ request }) => {
			if (flowId) {
				await request
					.delete(`${API}/flows/${flowId}`, { headers: API_WRITE_HEADERS })
					.catch(() => {})
			}
		})

		test("renders the seeded flow's node on the canvas", async ({ page }) => {
			const e = trackErrors(page)
			await gotoPage(page, FlowDetailPage(flowId as string))

			// The canvas host itself.
			await expect(
				page.locator('.cn-flow-detail'),
				'the flow canvas did not render',
			).toBeVisible({ timeout: 15_000 })

			// THIS flow's node, not just A node: the label is derived from the
			// config this run seeded. An empty canvas renders "No steps yet"
			// instead, so this fails loudly when the id selects nothing.
			await expect(
				page.locator('.cn-flow-detail__node-label').first(),
				'the seeded node never reached the canvas',
			).toHaveText(`message: ${NODE_MESSAGE}`, { timeout: 15_000 })

			// The type label comes from the node CATALOGUE, so this also asserts
			// the catalogue resolved `openregister.end` — a type it cannot
			// explain renders as the raw id plus an "Unknown step" warning.
			await expect(
				page.locator('.cn-flow-detail__node-type').first(),
			).toHaveText('End')
			await expect(page.locator('.cn-flow-detail__node--unknown')).toHaveCount(
				0,
			)

			expectNoErrors(e)
		})
	})

	// ─────────────────────────────────────────────────────────────────────────
	// ReportView — `src/views/reports/ReportView.vue`
	//
	// A dashboard is an ordinary OR object: `reportsStore.fetchDashboard(id)`
	// issues `GET /api/objects/reports/dashboard/{id}` with the register and
	// schema slugs HARD-CODED (`DEFAULT_REPORTS_REGISTER` /
	// `DEFAULT_DASHBOARD_SCHEMA` in src/store/modules/reports.js; the
	// `REPORTS_REGISTER_OVERRIDE` its header advertises is not read anywhere).
	//
	// ⚠️ SO THIS ONE PAIR CANNOT BE RUN-NAMESPACED — the slugs are the app's,
	// not the test's. The OBJECT still is, and it is the only row this fixture
	// asserts on. The register/schema are therefore FOUND FIRST and only created
	// when absent, and torn down only when this run created them, so a
	// deployment that already ships the Rapportage bundle keeps its own rows.
	// ─────────────────────────────────────────────────────────────────────────
	test.describe('ReportView', () => {
		const TITLE = `E2E Dashboard ${RUN_ID}`
		const DESCRIPTION = `Seeded dashboard for ${RUN_ID}`
		let register: SeededRegister | null = null
		let schema: SeededSchema | null = null
		let dashboard: SeededObject | null = null
		let createdRegister = false
		let createdSchema = false

		test.beforeAll(async ({ request }) => {
			const existingRegister = await findBySlug(
				request,
				'registers',
				'reports',
			)
			if (existingRegister === null) {
				const resp = await request.post(`${API}/registers`, {
					headers: JSON_HEADERS,
					data: {
						slug: 'reports',
						title: 'Reports',
						description:
							'Operator-defined dashboards and scheduled reports.',
					},
				})
				expect(
					resp.status(),
					`POST /api/registers (reports): ${(await resp.text()).slice(0, 200)}`,
				).toBeLessThanOrEqual(201)
				const body = await resp.json()
				register = { id: body.id, slug: 'reports', title: body.title }
				createdRegister = true
			} else {
				register = {
					id: existingRegister.id,
					slug: 'reports',
					title: existingRegister.title,
				}
			}

			const existingSchema = await findBySlug(request, 'schemas', 'dashboard')
			if (existingSchema === null) {
				const resp = await request.post(`${API}/schemas`, {
					headers: JSON_HEADERS,
					data: {
						slug: 'dashboard',
						title: 'Dashboard',
						description: 'Operator-defined dashboard / report.',
						properties: {
							titel: { type: 'string', title: 'Titel' },
							beschrijving: { type: 'string', title: 'Beschrijving' },
							widgets: {
								type: 'array',
								title: 'Widgets',
								items: { type: 'object' },
							},
						},
					},
				})
				expect(
					resp.status(),
					`POST /api/schemas (dashboard): ${(await resp.text()).slice(0, 200)}`,
				).toBeLessThanOrEqual(201)
				const body = await resp.json()
				schema = { id: body.id, slug: 'dashboard', title: body.title }
				createdSchema = true
			} else {
				schema = {
					id: existingSchema.id,
					slug: 'dashboard',
					title: existingSchema.title,
				}
			}

			// Objects live under register+schema, so the pair has to be linked.
			// The UNION is written, never a bare replacement: on a deployment
			// that already has a `reports` register this must not drop the
			// schemas it already carries.
			const linked = ((existingRegister?.schemas ?? []) as unknown[])
				.map((entry) =>
					Number(
						typeof entry === 'object' && entry !== null
							? (entry as { id?: number }).id
							: entry,
					),
				)
				.filter((id) => Number.isFinite(id))
			if (linked.includes(schema.id) === false) {
				await linkSchemaToRegister(request, register, [...linked, schema.id])
			}

			// `widgets: []` is deliberate: the bundle's dashboard schema requires
			// the key, and an empty list means ReportView fetches no widget data,
			// so this spec asserts the PAGE and never an aggregation backend.
			dashboard = await createObject(request, register.id, schema.id, {
				titel: TITLE,
				beschrijving: DESCRIPTION,
				widgets: [],
			})
		})

		test.afterAll(async ({ request }) => {
			if (register !== null && schema !== null && dashboard !== null) {
				await deleteObject(request, register.id, schema.id, dashboard.id)
			}
			if (createdSchema === true && schema !== null) {
				await deleteSchema(request, schema.id)
			}
			if (createdRegister === true && register !== null) {
				await deleteRegister(request, register.id)
			}
		})

		test('renders the seeded dashboard title, description and controls', async ({
			page,
		}) => {
			const e = trackErrors(page)
			await gotoPage(page, ReportView((dashboard as SeededObject).id))

			// The heading falls back to the literal "Dashboard" when nothing
			// loaded, so asserting the SEEDED title is what separates a loaded
			// dashboard from an empty page wearing the same chrome.
			await expect(
				page.getByRole('heading', { name: TITLE }).first(),
				'the dashboard title did not render — the object was not loaded',
			).toBeVisible({ timeout: 15_000 })
			await expect(page.getByText(DESCRIPTION).first()).toBeVisible()

			// The explicit not-found state must be absent, not merely unasserted.
			await expect(page.getByText('Dashboard not found')).toHaveCount(0)

			// The page's own controls.
			await expect(
				page.getByRole('button', { name: /Back/i }).first(),
			).toBeVisible()
			await expect(
				page.getByRole('button', { name: /Refresh dashboard/i }).first(),
			).toBeVisible()

			expectNoErrors(e)
		})
	})

	// ─────────────────────────────────────────────────────────────────────────
	// SchemaDetails — `src/views/schema/SchemaDetails.vue`
	//
	// ⚠️ Read the header note before adding a title assertion here: this page
	// does not load its route id, so the seeded schema's title is NOT on screen.
	// What IS the page's own is asserted: the three-tab strip, the dashboard
	// tab's chart cards, and the actions menu — all of which disappear if the
	// route stops resolving to this component or the component stops rendering.
	//
	// The schema is still seeded and still the id in the URL: the route is a
	// real one for a real record, so the day the page starts hydrating from it
	// this fixture already holds the record to assert against.
	// ─────────────────────────────────────────────────────────────────────────
	test.describe('SchemaDetails', () => {
		let schema: SeededSchema | null = null

		test.beforeAll(async ({ request }) => {
			schema = await createSchema(
				request,
				RUN_ID,
				'detail',
				twoPropertySchema(),
			)
		})

		test.afterAll(async ({ request }) => {
			if (schema !== null) {
				await deleteSchema(request, schema.id)
			}
		})

		test('renders its tab strip, chart cards and actions menu', async ({
			page,
		}) => {
			const e = trackErrors(page)
			await gotoPage(page, SchemaDetails((schema as SeededSchema).id))

			// The tab strip is this component's own markup — three buttons, in
			// this order, rendered by no other page in the app.
			const tabs = page.locator('.schemaTabNav button')
			await expect(tabs).toHaveCount(3, { timeout: 15_000 })
			await expect(tabs.nth(0)).toHaveText(/Dashboard/)
			await expect(tabs.nth(1)).toHaveText(/Calendar/)
			await expect(tabs.nth(2)).toHaveText(/Workflows/)

			// The dashboard tab is active on arrival and owns these two cards.
			await expect(
				page.getByRole('heading', { name: 'Audit Trail Actions' }).first(),
			).toBeVisible()
			await expect(
				page.getByRole('heading', { name: 'Objects by Register' }).first(),
			).toBeVisible()

			// The actions menu OPENS and offers the schema-scoped actions. This
			// is an interaction, not a render check: a menu that renders its
			// trigger and nothing else fails here.
			await page.getByRole('button', { name: 'Actions' }).first().click()
			await expect(
				page.getByRole('menuitem', { name: /Add Property/i }).first(),
			).toBeVisible({ timeout: 10_000 })
			await expect(
				page.getByRole('menuitem', { name: /Download/i }).first(),
			).toBeVisible()

			expectNoErrors(e)
		})
	})
})
