/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * The docs-capture project then reuses that storage state via
 * `use.storageState` in playwright.config.ts, so specs start from an
 * authenticated session.
 *
 * Why a real browser login (instead of POSTing to /login directly):
 * Nextcloud's login form ships a CSRF token (`requesttoken`) plus an
 * `oc_session_passphrase` cookie that must be set in the same browser
 * context. Driving the form via Playwright sidesteps having to
 * reverse-engineer the token-rotation contract, which has shifted
 * across NC 28 / 29 / 30.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'openregister-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/openregister/`.
 *
 * The shared `ConductionNL/.github/quality.yml` Playwright job runs
 * `npm ci` + `npx playwright install` before the spec run, but never
 * `npm run build`. On a fresh CI VM the `js/openregister-main.js`
 * artefact doesn't exist, so the rendered page loads a 404 script tag
 * and the Vue app never mounts — every selector wait then times out.
 *
 * Locally, the app running in the dev container is usually mounted
 * from a separate checkout, so this build only helps CI / a checkout
 * that serves its own `js/`.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. ` +
				'Make sure the docker container is running and reachable.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Hit the login form so the CSRF token + session passphrase land in
	// the browser jar.
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	// Arm the post-login navigation wait BEFORE submitting the form.
	//
	// Previously the form was submitted first and `page.waitForURL(...)`
	// was awaited afterwards. On a busy dev container the login POST +
	// redirect to /apps/dashboard/ can fully complete (firing its `load`
	// event) before `waitForURL` registers its listener; `waitForURL`'s
	// default `waitUntil: 'load'` then blocks waiting for a *fresh* load
	// event that never comes, and times out at 25s — even though the page
	// is already authenticated on /apps/dashboard/ (the timeout log shows
	// `navigated to ".../apps/dashboard/"`). This raced consistently under
	// container load and failed the whole suite in globalSetup.
	//
	// Build the promise first (listener attached synchronously), then
	// trigger the submit, then await. `waitUntil: 'commit'` resolves as
	// soon as the navigation to the post-login URL is committed, which is
	// robust to the redirect having already landed. If the navigation
	// settled before the wait armed, fall back to the current URL.
	const leftLogin = page.waitForURL(
		(url) => !/\/login(\?|$|\/)/.test(url.toString()),
		{ timeout: 25_000, waitUntil: 'commit' },
	)
	// Submit via the form rather than a themed-button .click(): on NC's
	// themed login the styled submit button can swallow the click.
	await page.locator('input[name="password"]').evaluate((el: HTMLInputElement) => {
		el.form?.requestSubmit()
	})
	try {
		await leftLogin
	} catch (err) {
		// The navigation may have already settled off /login before the
		// wait armed — accept that rather than failing the whole suite.
		if (/\/login(\?|$|\/)/.test(page.url())) {
			throw err
		}
	}
	// Then confirm an authenticated page rendered (header on a non-login page).
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
			+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).',
		)
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
