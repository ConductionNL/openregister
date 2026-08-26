/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
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

import { chromium, expect, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import { seedMdm } from './mdm-seed'
import { resolveBaseUrl } from './base-url'

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
	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

/**
 * Poll status.php until the instance is genuinely serviceable, rather than
 * probing once. A single probe cannot distinguish "healthy" from the two
 * states that produce a whole suite of misleading assertion failures:
 *
 *  - `needsDbUpgrade: true` — the app-bump trap; every page 503s.
 *  - `maintenance: true`    — a concurrent occ upgrade is running.
 *
 * Both were observed on 2026-07-27 and turned an entire run into ~25
 * `page.goto` timeouts with ZERO assertion failures. Fail LOUD with the
 * offending payload instead. Ported from the docudesk/larpingapp pattern
 * (ADR-074 runbook invariants).
 */
async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const deadline =
		Date.now() + Number(process.env.E2E_HEALTH_TIMEOUT_MS ?? 600_000)
	const ctx = await request.newContext()
	let last = 'no response'
	try {
		while (Date.now() < deadline) {
			const res = await ctx
				.get(`${baseURL}/status.php`, { failOnStatusCode: false })
				.catch(() => null)
			if (res?.ok()) {
				const body = await res.json().catch(() => ({}))
				if (
					body?.installed === true
					&& body.maintenance === false
					&& body.needsDbUpgrade === false
				) {
					return
				}
				last = JSON.stringify(body)
			} else {
				last = `HTTP ${res?.status() ?? 'unreachable'}`
			}
			await new Promise((r) => setTimeout(r, 5_000))
		}
		throw new Error(
			`Nextcloud at ${baseURL} did not become healthy (last: ${last}). `
				+ 'Check for a concurrent deploy / occ upgrade, or a missing custom_apps bind mount.',
		)
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// ⚠️ No `'http://localhost:8080'` fallback. That literal is the SHARED dev
	// container, which bind-mounts real host checkouts — a suite that silently
	// retargets there writes fixtures into other people's working trees and
	// fires failed logins at their instance. resolveBaseUrl() throws instead.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseUrl()
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
	// Retry the initial load: on a cold container the first request compiles
	// opcache and can take >30s, which is the single most common cause of a
	// setup-level failure that looks like an app outage.
	for (let attempt = 1; ; attempt++) {
		try {
			await page.goto('/index.php/login', {
				waitUntil: 'domcontentloaded',
				timeout: 90_000,
			})
			break
		} catch (err) {
			if (attempt >= 3) throw err
			console.log(
				`[playwright globalSetup] login page load failed (attempt ${attempt}/3), retrying…`,
			)
		}
	}
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
	// Check the URL FIRST: it is the cheaper and far more diagnostic assertion,
	// and it does not depend on any element having rendered yet.
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).',
		)
	}

	// Then confirm an authenticated page actually rendered.
	//
	// `waitForSelector` used to be here and timed out while REPORTING the header
	// as visible:
	//
	//   waiting for locator('#header, header.header') to be visible
	//     - locator resolved to visible <header id="header">…</header>
	//
	// That contradiction is a navigation race: the post-login redirect
	// invalidates the handle mid-check, so the wait keeps retrying a stale one
	// until it expires. `expect().toBeVisible()` re-resolves the locator on every
	// poll, so a redirect costs it one poll instead of the whole budget. Settle
	// the navigation first — `domcontentloaded`, never `networkidle`, which never
	// fires on Nextcloud because of its notification poll (ADR-074 rule 4).
	await page.waitForLoadState('domcontentloaded')
	await expect(page.locator('#header, header.header').first()).toBeVisible({
		timeout: 20_000,
	})

	// Suppress the openregister product walkthrough for automated runs. On
	// first visit it mounts a modal spotlight tour whose full dim layer
	// intercepts pointer events, so every left-navigation click times out. The
	// "seen" marker is browser-local (`cn-walkthrough-seen:<appId>`), and a
	// fresh Playwright context re-triggers the tour every run.
	//
	// Seeding the marker with a high sentinel version is what dossiq and
	// shillinq already do: every step's `sinceVersion` sorts below it, so the
	// tour composes to an empty step set and never mounts.
	try {
		await page.goto('/apps/openregister/', {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:openregister', '999.0.0')
			} catch (e) {
				// localStorage unavailable — the tour then dismisses via helper clicks.
			}
		})
	} catch {
		// App origin unreachable here is non-fatal; specs still run.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()

	// Self-seed the deep MDM fixture (duplicate pair + multi-source conflict +
	// scored entities) so the mdm-frontend / mdm-merge-ui /
	// mdm-survivorship-override suites RUN their full chains instead of
	// skipping. Guarded: on a non-pipelinq instance seedMdm() no-ops and
	// returns null, and any error is logged without failing the whole run
	// (the specs keep their existing skip fallback).
	try {
		const apiContext = await request.newContext({
			baseURL,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`,
			},
		})
		try {
			const seed = await seedMdm(apiContext)
			// eslint-disable-next-line no-console
			console.log(
				seed
					? `[playwright globalSetup] MDM fixture seeded (register ${seed.register}, schema ${seed.masterEntitySchema}, dup pair ${seed.dupPair.join(' + ')}).`
					: '[playwright globalSetup] pipelinq/masterEntity not found — MDM fixture skipped; MDM specs will self-skip.',
			)
		} finally {
			await apiContext.dispose()
		}
	} catch (err) {
		// eslint-disable-next-line no-console
		console.warn(
			`[playwright globalSetup] MDM seeding failed (continuing): ${(err as Error).message}`,
		)
	}
}
