/**
 * Ad-hoc Playwright config for the api-direct flow specs.
 *
 * The main config excludes `api-direct/**` from the chromium project (those are
 * contract assertions, run via Newman in CI), so running one directly needs a
 * config that targets them: Basic-auth admin, no browser, no globalSetup.
 *
 *   npx playwright test --config playwright.flow.config.ts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: './tests/e2e/api-direct',
	fullyParallel: false,
	workers: 1,
	reporter: [['list']],
	timeout: 120_000,
	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		httpCredentials: {
			username: process.env.NEXTCLOUD_ADMIN_USER || 'admin',
			password: process.env.NEXTCLOUD_ADMIN_PASSWORD || 'admin',
		},
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			// Without this Nextcloud answers a browser-shaped request with the
			// login HTML, and every JSON assertion fails for the wrong reason.
			Accept: 'application/json',
			// Sent PREEMPTIVELY on purpose. `httpCredentials` only answers a 401
			// challenge, and OpenRegister does not challenge — it serves the
			// request as Anonymous, whose RBAC filters every register out. The
			// symptom is a cheerful `200 {"results":[]}`, which looks like an
			// empty instance rather than an auth problem.
			Authorization: `Basic ${Buffer.from(
				`${process.env.NEXTCLOUD_ADMIN_USER || 'admin'}:${process.env.NEXTCLOUD_ADMIN_PASSWORD || 'admin'}`,
			).toString('base64')}`,
		},
		ignoreHTTPSErrors: true,
	},
})
