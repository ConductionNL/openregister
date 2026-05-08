/**
 * Playwright E2E config for OpenRegister.
 *
 * Smoke-test suite that hits a running OR instance (default
 * http://localhost:8080) with admin:admin credentials. Auth is
 * configured via the request-context API (basic auth) — there is no
 * UI flow yet, so no storageState / globalSetup is wired here.
 *
 * Override the target via NEXTCLOUD_URL / OR_USER / OR_PASS env vars.
 */
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		extraHTTPHeaders: {
			// Basic auth: base64("admin:admin") = YWRtaW46YWRtaW4=
			Authorization: `Basic ${Buffer.from(
				`${process.env.OR_USER || 'admin'}:${process.env.OR_PASS || 'admin'}`,
			).toString('base64')}`,
		},
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],

	testIgnore: [
		'**/node_modules/**',
		'**/custom_apps/**',
		'**/.claude/**',
	],
})
