import { expect, request as pwRequest, test } from '@playwright/test'

/**
 * The fleet's documentation sites, checked in a real browser.
 *
 * The curl sweep that drove the rollout asserts status codes and redirect
 * targets. That is necessary and not sufficient: a docs site can answer 200
 * with a blank shell, a build error page, or a Docusaurus 404 route -- all of
 * which are 200s. So this renders the pages and asserts on their content.
 */

const APPS = [
	'openregister',
	'opencatalogi',
	'integriq',
	'filinq',
	'thematiq',
	'launchpad',
	'stackiq',
	'larpinq',
	'zaakafhandelapp',
	'dossiq',
	'pipelinq',
	'shillinq',
	'learniq',
	'portaliq',
	'decidiq',
	'buildiq',
	'keepiq',
	'hermiq',
	'humaniq',
	'versioniq',
	'planninq',
]

// retired hostname -> canonical app
const RENAMED: Record<string, string> = {
	openconnector: 'integriq',
	docudesk: 'filinq',
	nldesign: 'thematiq',
	softwarecatalog: 'stackiq',
	larpingapp: 'larpinq',
	procest: 'dossiq',
	decidesk: 'decidiq',
	openbuild: 'buildiq',
	doriath: 'keepiq',
	hrmq: 'humaniq',
	'app-versions': 'versioniq',
	planix: 'planninq',
	scholiq: 'learniq',
}

test.describe('docs sites render', () => {
	for (const app of APPS) {
		test(`${app}: the demo setup page renders real content`, async ({
			page,
		}) => {
			const url = `https://${app}.conduction.nl/docs/Installation/demo-environment/`
			const res = await page.goto(url, { waitUntil: 'domcontentloaded' })
			expect(res?.status()).toBe(200)

			// A Docusaurus 404 route also answers 200, so assert we are not on one.
			const body = (await page.locator('body').innerText()).trim()
			expect(body).not.toMatch(
				/Page Not Found|We could not find what you were looking for/i,
			)

			// The page must carry its own heading and the compose file's name --
			// content that only the real page has.
			await expect(page.locator('article, main').first()).toBeVisible()
			expect(body).toMatch(/Run a local demo/i)
			expect(body).toContain(`${app}-compose.yaml`)
		})
	}
})

test.describe('retired hostnames redirect', () => {
	for (const [old, canonical] of Object.entries(RENAMED)) {
		test(`${old} -> ${canonical}, in a browser`, async ({ page }) => {
			// Follow the redirect the way a reader's browser would, and assert on
			// where it LANDS rather than on the 301 alone.
			await page.goto(`https://${old}.conduction.nl/`, {
				waitUntil: 'domcontentloaded',
			})
			expect(new URL(page.url()).hostname).toBe(`${canonical}.conduction.nl`)

			const body = (await page.locator('body').innerText()).trim()
			expect(body.length).toBeGreaterThan(50)
		})

		test(`${old}: a deep link keeps its path and query`, async () => {
			const api = await pwRequest.newContext()
			const path = '/docs/Installation/demo-environment/?q=1'
			const res = await api.get(`https://${old}.conduction.nl${path}`, {
				maxRedirects: 0,
			})
			expect(res.status()).toBe(301)
			expect(res.headers().location).toBe(
				`https://${canonical}.conduction.nl${path}`,
			)
			await api.dispose()
		})
	}
})
