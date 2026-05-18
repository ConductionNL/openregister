/**
 * Per-leaf screenshot harness.
 *
 * Logs in to the OR dev container, navigates to the standalone
 * IntegrationsView (/integrations/:register/:schema/:objectId), then
 * walks every advertised provider tab and writes one PNG per leaf
 * under docs/static/screenshots/integrations/{id}.png.
 *
 * Designed to bypass ObjectDetails.vue and its legacy sub-resource
 * plugin races — IntegrationsView mounts CnIntegrationTab in isolation
 * so the screenshot reflects only the registry-driven surface.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test \
 *     tests/e2e/leaf-screenshots.spec.ts --project=chromium
 */
import { test, expect } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const REGISTER = '21'
const SCHEMA = '166'
const OBJECT_ID = '25706ca9-c989-4d6b-9f7b-98cf1cc70639'

const SCREENSHOT_DIR = path.resolve(
	__dirname,
	'../../docs/static/screenshots/integrations',
)

test.describe('Per-leaf screenshot harness', () => {
	test.setTimeout(300_000)

	test('captures one PNG per advertised provider tab', async ({ page, baseURL }) => {
		fs.mkdirSync(SCREENSHOT_DIR, { recursive: true })

		// 1. The chromium project's extraHTTPHeaders sends Basic auth on
		//    every request, so we can navigate directly to the SPA without
		//    going through the login form. NC accepts Basic auth and
		//    sets the session cookie on the first authenticated page hit.
		await page.goto(
			`${baseURL}/index.php/apps/openregister/integrations/${REGISTER}/${SCHEMA}/${OBJECT_ID}`,
		)
		await page.waitForLoadState('networkidle')

		// 3. Wait until the in-page registry has flushed all provider
		//    descriptors and the tab strip rendered.
		await page.waitForFunction(() => {
			const list = (window as { OCA?: { OpenRegister?: { integrations?: { list?: () => Array<{ id: string }> } } } }).OCA?.OpenRegister?.integrations?.list?.()
			return Array.isArray(list) && list.length >= 18
		}, { timeout: 30_000 })

		// 4. Collect the rendered tab ids/labels from the registry.
		const providers = await page.evaluate(() => {
			const list = (window as { OCA?: { OpenRegister?: { integrations?: { list?: () => Array<{ id: string; label?: string; group?: string }> } } } }).OCA?.OpenRegister?.integrations?.list?.()
			return Array.isArray(list) ? list.map(p => ({ id: p.id, label: p.label, group: p.group })) : []
		})

		expect(providers.length, 'registry should advertise ≥18 providers').toBeGreaterThanOrEqual(18)

		// 5. Take an overview screenshot of the integrations strip first.
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '_overview.png'), fullPage: false })

		// 6. Walk every provider, click its tab, wait for the inner
		//    CnIntegrationTab to finish loading, then screenshot.
		for (const provider of providers) {
			// Bootstrap-vue BTab titles render as <a role="tab">title</a>.
			const tab = page.locator(`role=tab[name="${provider.label || provider.id}"]`).first()
			if (await tab.count() === 0) {
				console.warn(`[leaf-screenshots] tab not found for ${provider.id}`)
				continue
			}
			await tab.click({ trial: false })
			// Give the inner CnIntegrationTab time to fetch + paint.
			await page.waitForTimeout(800)
			await page.screenshot({
				path: path.join(SCREENSHOT_DIR, `${provider.id}.png`),
				fullPage: false,
			})
		}

		// 7. Sanity-check at least one screenshot per leaf landed.
		const written = fs.readdirSync(SCREENSHOT_DIR).filter(f => f.endsWith('.png'))
		expect(written.length, 'expected at least one PNG per leaf').toBeGreaterThanOrEqual(providers.length)
	})
})
