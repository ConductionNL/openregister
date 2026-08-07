/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BOOT SMOKE GATE — two routes, asserting the Vue app actually MOUNTED.
 *
 * WHY THIS EXISTS
 * ---------------
 * A Vue 3 bundle can fail to boot in a way that npm, ESLint, webpack and even
 * a byte-verified deploy all report as green. The known case: any
 * `@nextcloud/*` package left on its Vue-2 major. nc-vue's pre-bundled dialogs
 * call the v3-only `getGettextBuilder().detectLanguage()`, so an
 * `@nextcloud/l10n@2` in the tree throws
 *
 *     TypeError: …detectLanguage is not a function
 *
 * at module-eval time. Every route then renders an empty shell. HTTP is 200,
 * the script tag resolves, the bytes on disk are the ones you built — and the
 * app is dead. On scholiq this was found only after 37 minutes of e2e had run
 * against it.
 *
 * So: gate the expensive step on a cheap one. This spec is ~20 seconds and the
 * run script aborts before the full suite if it fails.
 *
 * The assertions are deliberately about MOUNTING, not about any feature:
 *   1. the app's host element has real child elements (Vue replaced nothing =
 *      empty shell), and
 *   2. no page error / boot-killer console error was raised.
 *
 * ⚠️ `toBeVisible()` on a container is NOT sufficient — the shell is visible
 * when the app is dead. Assert on rendered descendants.
 */
import { test, expect, type ConsoleMessage } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

// Two routes: the app root, and one sub-route reached through the hash router.
// A sub-route additionally proves the lazy view chunks resolve — a wrong
// `publicPath` returns 200 with `text/html`, not a 404, so a chunk failure
// shows up as a MIME refusal here and nowhere else.
const ROUTES = [
	{ name: 'app root', url: '/index.php/apps/openregister/' },
	{ name: 'registers', url: '/index.php/apps/openregister/#/registers' },
]

/** Console messages that mean the bundle never booted. */
const BOOT_KILLERS = [
	'is not a function',
	'ChunkLoadError',
	'Failed to fetch dynamically imported module',
	'Cannot read properties of undefined',
	'Unexpected token',
	'MIME type',
]

test.use(
	fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {},
)

for (const route of ROUTES) {
	test(`boot smoke: ${route.name} mounts`, async ({ page }) => {
		const killers: string[] = []
		const pageErrors: string[] = []

		page.on('console', (msg: ConsoleMessage) => {
			if (msg.type() !== 'error') {
				return
			}
			const text = msg.text()
			if (BOOT_KILLERS.some((k) => text.includes(k))) {
				killers.push(text)
			}
		})
		page.on('pageerror', (err) => pageErrors.push(String(err)))

		await page.goto(route.url, { waitUntil: 'domcontentloaded' })

		// The app mounts into `<div id="openregister">` (templates/index.php).
		// Wait for it to acquire rendered children rather than for a timeout.
		await page.waitForFunction(
			() => {
				const host = document.querySelector('#openregister')
				return host !== null && host.children.length > 0
			},
			undefined,
			{ timeout: 20_000 },
		)

		const childCount = await page.evaluate(
			() => document.querySelector('#openregister')?.children.length ?? 0,
		)
		expect(
			childCount,
			`#openregister rendered no children on ${route.name} — the app did not mount`,
		).toBeGreaterThan(0)

		expect(
			pageErrors,
			`uncaught page error(s) on ${route.name}`,
		).toEqual([])
		expect(
			killers,
			`boot-killer console error(s) on ${route.name}`,
		).toEqual([])

		// TEMPORARY SENTINEL — deliberate failure to prove this suite can go
		// red. Removed in the next commit on this branch.
		expect(1, 'SENTINEL: proving the e2e suite can fail').toBe(2)
	})
}
