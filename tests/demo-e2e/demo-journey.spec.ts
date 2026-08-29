import { test, expect, request as pwRequest } from '@playwright/test'

/**
 * The demo environment, checked the way its documentation says to check it.
 *
 * The point of these assertions is that they can fail. Nextcloud serves its
 * page shell before an app decides whether it has anything to render, so an
 * app URL returns HTTP 200 even when it resolves to nothing at all -- which
 * is exactly why none of these assert on a status code alone.
 */

const APP = process.env.DEMO_APP || 'portaliq'
const BASE = process.env.DEMO_BASE_URL || 'http://localhost:8613'
const HAS_PORTAL = process.env.DEMO_HAS_PORTAL === '1'
// `send: 'always'` is load-bearing. By default Playwright withholds Basic
// credentials until it sees a 401 challenge, and Nextcloud answers an app page
// with a bare 401 carrying no WWW-Authenticate header -- so the retry never
// fires and every authenticated assertion fails against a healthy demo.
const CREDS = { username: 'admin', password: 'admin', send: 'always' as const }

test.describe(`demo environment: ${APP}`, () => {
	test('Nextcloud reports itself installed, not merely reachable', async () => {
		const api = await pwRequest.newContext({ baseURL: BASE })
		const res = await api.get('/status.php')
		expect(res.status()).toBe(200)

		const body = await res.json()
		// `installed:false` also returns 200. The flag is the assertion, not the code.
		expect(body.installed).toBe(true)
		expect(body.maintenance).toBe(false)
		expect(body.needsDbUpgrade).toBe(false)
		await api.dispose()
	})

	test('OpenRegister has registers, so the app has something to attach to', async () => {
		const api = await pwRequest.newContext({
			baseURL: BASE,
			httpCredentials: CREDS,
		})
		const res = await api.get('/apps/openregister/api/registers')
		expect(res.status()).toBe(200)

		const body = await res.json()
		const rows = body.results ?? body
		expect(Array.isArray(rows)).toBe(true)

		// An empty list here is the failure this whole demo exists to catch: it
		// means the register configuration was never imported, which from outside
		// is indistinguishable from "nothing configured yet".
		expect(rows.length).toBeGreaterThan(0)

		// A register with no slug is a row that exists but resolves to nothing.
		for (const r of rows.slice(0, 5)) {
			expect(r.slug ?? r.title).toBeTruthy()
		}
		await api.dispose()
	})

	test('the app page renders its own UI, not just a Nextcloud shell', async ({
		browser,
	}) => {
		// Basic auth is NOT enough for a page navigation. Nextcloud accepts it on
		// API routes but redirects a browser navigation to /login -- and serves
		// that login page with HTTP 200. A test asserting only on the status code
		// would pass while sitting on the login screen. Measured here, which is
		// why this logs in properly and then asserts on content.
		const ctx = await browser.newContext()
		const page = await ctx.newPage()

		await page.goto(`${BASE}/login`)
		await page
			.getByRole('textbox', { name: /account name or email/i })
			.fill(CREDS.username)
		await page.getByRole('textbox', { name: /^password$/i }).fill(CREDS.password)
		// `exact` matters: "Log in with a device" also matches a loose /log in/.
		await page.getByRole('button', { name: 'Log in', exact: true }).click()
		await page.waitForURL((u) => !u.pathname.startsWith('/login'), {
			timeout: 30_000,
		})

		const entry =
			APP === 'thematiq' ? '/settings/admin/theming' : `/apps/${APP}/`
		const res = await page.goto(`${BASE}${entry}`)
		expect(res?.status()).toBe(200)

		// The login page is also 200, so prove we are not on it.
		expect(page.url()).not.toContain('/login')

		// The shell alone would satisfy a status check. Require app content.
		await expect(
			page.locator('#content, #app-content, .app-content').first(),
		).toBeVisible({ timeout: 30_000 })

		const text = (await page.locator('body').innerText()).trim()
		expect(text.length).toBeGreaterThan(50)
		// A Nextcloud error page also returns 200 in some configurations.
		expect(text).not.toMatch(
			/Internal Server Error|not installed|Page not found/i,
		)
		await ctx.close()
	})

	test('the app is enabled and reachable under its own id', async () => {
		const api = await pwRequest.newContext({
			baseURL: BASE,
			httpCredentials: CREDS,
		})
		const entry =
			APP === 'thematiq' ? '/settings/admin/theming' : `/apps/${APP}/`
		const res = await api.get(entry, { maxRedirects: 5 })

		// Unauthenticated this is 401 on a perfectly healthy demo -- the defect
		// the documentation used to describe as a pass.
		expect(res.status()).toBe(200)
		await api.dispose()
	})

	test('the public portal resolves to a real site', async () => {
		test.skip(!HAS_PORTAL, 'this demo does not install portaliq')

		const api = await pwRequest.newContext({ baseURL: BASE })
		const res = await api.get('/apps/portaliq/api/content/site?portal=demo')
		expect(res.status()).toBe(200)

		const body = await res.json()
		// Portaliq answers {"error":"not_found"} with a 200 when the slug resolves
		// to nothing, so the body is the assertion.
		expect(body.error).toBeUndefined()
		expect(body.slug).toBe('demo')
		expect(body.title).toBeTruthy()
		await api.dispose()
	})

	test('the portal page is served without a login', async ({ page }) => {
		test.skip(!HAS_PORTAL, 'this demo does not install portaliq')

		const res = await page.goto(`${BASE}/apps/portaliq/site?portal=demo`)
		expect(res?.status()).toBe(200)

		const text = (await page.locator('body').innerText()).trim()
		expect(text.length).toBeGreaterThan(50)
		// Reaching the login form means the portal did not resolve as public.
		expect(text).not.toMatch(/Log in|Wachtwoord vergeten/i)
	})
})
